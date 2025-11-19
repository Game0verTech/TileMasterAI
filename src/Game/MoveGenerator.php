<?php

declare(strict_types=1);

namespace TileMasterAI\Game;

class MoveGenerator
{
    private Board $board;
    private Dictionary $dictionary;

    public function __construct(Board $board, Dictionary $dictionary)
    {
        $this->board = $board;
        $this->dictionary = $dictionary;
    }

    /**
     * Generate and rank legal moves for the current position.
     *
     * @return array<int, array{
     *   word:string,
     *   direction:string,
     *   start:string,
     *   score:int,
     *   bingo:bool,
     *   mainWordScore:int,
     *   crossWords: array<int, array{word:string, score:int, start:string, direction:string}>,
     *   placements: array<int, array{coord:string, tile:Tile}>
     * }>
     */
    public function generateMoves(Rack $rack, int $limit = 5): array
    {
        $anchors = $this->anchorSquares();
        if ($anchors === []) {
            return [];
        }

        $inventory = $this->rackInventory($rack);
        $candidates = [];
        $rackCount = count($rack->tiles());

        foreach ($this->dictionary->words() as $word) {
            $wordLength = strlen($word);
            $letters = str_split($word);

            foreach ($anchors as [$row, $column]) {
                foreach (['horizontal', 'vertical'] as $direction) {
                    for ($offset = 0; $offset < $wordLength; $offset++) {
                        [$startRow, $startColumn] = $this->startFromAnchor($row, $column, $offset, $direction);

                        if ($startRow < 1 || $startColumn < 1 || $startRow > Board::ROWS || $startColumn > Board::COLUMNS) {
                            continue;
                        }

                        if (!$this->withinBounds($startRow, $startColumn, $wordLength, $direction)) {
                            continue;
                        }

                        $anchorIndex = $offset;
                        $placements = $this->placementsForWord($letters, $startRow, $startColumn, $inventory, $anchorIndex, $direction);
                        if ($placements === null) {
                            continue;
                        }

                        $score = Scoring::scoreMove($this->board, $placements, $direction);
                        $bingo = count($placements) === $rackCount;
                        if ($bingo) {
                            $score['total'] += 50;
                        }

                        $candidates[] = [
                            'word' => $word,
                            'direction' => $direction,
                            'start' => Board::coordinateKey($startRow, $startColumn),
                            'score' => $score['total'],
                            'bingo' => $bingo,
                            'mainWordScore' => $score['mainWord']['total'],
                            'crossWords' => $score['crossWords'],
                            'placements' => $placements,
                        ];
                    }
                }
            }
        }

        usort($candidates, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, $limit);
    }

    private function rackInventory(Rack $rack): array
    {
        $inventory = ['blanks' => 0, 'letters' => []];
        foreach ($rack->tiles() as $tile) {
            if ($tile->isBlank()) {
                $inventory['blanks']++;
            } else {
                $letter = $tile->letter();
                $inventory['letters'][$letter] = ($inventory['letters'][$letter] ?? 0) + 1;
            }
        }

        return $inventory;
    }

    /**
     * @return array<int, array{int, int}>
     */
    private function anchorSquares(): array
    {
        $anchors = [];

        for ($row = 1; $row <= Board::ROWS; $row++) {
            for ($column = 1; $column <= Board::COLUMNS; $column++) {
                if ($this->board->tileAtPosition($row, $column) !== null) {
                    continue;
                }

                if ($this->board->isEmpty() && $row === 8 && $column === 8) {
                    $anchors[] = [$row, $column];
                    continue;
                }

                if ($this->hasNeighbor($row, $column)) {
                    $anchors[] = [$row, $column];
                }
            }
        }

        return $anchors;
    }

    private function hasNeighbor(int $row, int $column): bool
    {
        $neighbors = [
            [$row - 1, $column],
            [$row + 1, $column],
            [$row, $column - 1],
            [$row, $column + 1],
        ];

        foreach ($neighbors as [$r, $c]) {
            if ($r < 1 || $c < 1 || $r > Board::ROWS || $c > Board::COLUMNS) {
                continue;
            }

            if ($this->board->tileAtPosition($r, $c) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $letters
     * @param array{blanks:int, letters:array<string,int>} $inventory
     * @return array<int, array{coord:string, tile:Tile}>|null
     */
    private function placementsForWord(array $letters, int $startRow, int $startColumn, array $inventory, int $anchorIndex, string $direction): ?array
    {
        $placements = [];
        $blanks = $inventory['blanks'];
        $pool = $inventory['letters'];
        $wordLength = count($letters);
        $anchorUsed = false;
        [$rowDelta, $colDelta] = $this->directionDeltas($direction);

        for ($i = 0; $i < $wordLength; $i++) {
            $row = $startRow + ($rowDelta * $i);
            $column = $startColumn + ($colDelta * $i);
            $boardTile = $this->board->tileAtPosition($row, $column);
            $letter = $letters[$i];

            if ($boardTile !== null) {
                if ($boardTile->letter() !== $letter) {
                    return null;
                }

                continue;
            }

            if ($i === $anchorIndex) {
                $anchorUsed = true;
            }

            $isBlank = false;
            if (($pool[$letter] ?? 0) > 0) {
                $pool[$letter]--;
            } elseif ($blanks > 0) {
                $blanks--;
                $isBlank = true;
            } else {
                return null;
            }

            $coord = Board::coordinateKey($row, $column);
            $placements[] = ['coord' => $coord, 'tile' => new Tile($letter, $isBlank)];

            $crossWord = $this->perpendicularWord($row, $column, $letter, $direction, $placements);
            if ($crossWord !== null && !$this->dictionary->has($crossWord)) {
                return null;
            }
        }

        if (!$anchorUsed) {
            return null;
        }

        if ($this->touchesBlockedCell($startRow, $startColumn, $wordLength, $direction)) {
            return null;
        }

        return $placements;
    }

    /**
     * @param array<int, array{coord:string, tile:Tile}> $placements
     */
    private function perpendicularWord(int $row, int $column, string $letter, string $direction, array $placements): ?string
    {
        [$rowDelta, $colDelta] = $this->directionDeltas($direction === 'horizontal' ? 'vertical' : 'horizontal');

        $letters = [];

        $r = $row;
        $c = $column;
        while ($r > 1 || $c > 1) {
            $r -= $rowDelta;
            $c -= $colDelta;
            if ($r < 1 || $c < 1) {
                break;
            }
            $tile = $this->board->tileAtPosition($r, $c);
            if ($tile === null) {
                $tile = $this->tileFromPlacements($placements, $r, $c);
            }
            if ($tile === null) {
                $r += $rowDelta;
                $c += $colDelta;
                break;
            }
            array_unshift($letters, $tile->letter());
        }

        $letters[] = $letter;

        $r = $row;
        $c = $column;
        while ($r < Board::ROWS || $c < Board::COLUMNS) {
            $r += $rowDelta;
            $c += $colDelta;
            if ($r > Board::ROWS || $c > Board::COLUMNS) {
                break;
            }
            $tile = $this->board->tileAtPosition($r, $c);
            if ($tile === null) {
                $tile = $this->tileFromPlacements($placements, $r, $c);
            }
            if ($tile === null) {
                break;
            }
            $letters[] = $tile->letter();
        }

        $word = implode('', $letters);
        return strlen($word) > 1 ? $word : null;
    }

    /**
     * @param array<int, array{coord:string, tile:Tile}> $placements
     */
    private function tileFromPlacements(array $placements, int $row, int $column): ?Tile
    {
        $coord = Board::coordinateKey($row, $column);
        foreach ($placements as $placement) {
            if ($placement['coord'] === $coord) {
                return $placement['tile'];
            }
        }

        return null;
    }

    private function withinBounds(int $startRow, int $startColumn, int $length, string $direction): bool
    {
        [$rowDelta, $colDelta] = $this->directionDeltas($direction);
        $endRow = $startRow + ($rowDelta * ($length - 1));
        $endColumn = $startColumn + ($colDelta * ($length - 1));

        return $endRow >= 1 && $endRow <= Board::ROWS && $endColumn >= 1 && $endColumn <= Board::COLUMNS;
    }

    private function touchesBlockedCell(int $startRow, int $startColumn, int $length, string $direction): bool
    {
        [$rowDelta, $colDelta] = $this->directionDeltas($direction);
        $beforeRow = $startRow - $rowDelta;
        $beforeColumn = $startColumn - $colDelta;
        $afterRow = $startRow + ($rowDelta * $length);
        $afterColumn = $startColumn + ($colDelta * $length);

        $beforeBlocked = $beforeRow >= 1 && $beforeColumn >= 1 && $this->board->tileAtPosition($beforeRow, $beforeColumn) !== null;
        $afterBlocked = $afterRow <= Board::ROWS && $afterColumn <= Board::COLUMNS && $this->board->tileAtPosition($afterRow, $afterColumn) !== null;

        return $beforeBlocked || $afterBlocked;
    }

    private function directionDeltas(string $direction): array
    {
        return strtolower($direction) === 'vertical' ? [1, 0] : [0, 1];
    }

    private function startFromAnchor(int $row, int $column, int $offset, string $direction): array
    {
        if ($direction === 'vertical') {
            return [$row - $offset, $column];
        }

        return [$row, $column - $offset];
    }
}
