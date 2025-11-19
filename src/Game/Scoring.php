<?php

declare(strict_types=1);

namespace TileMasterAI\Game;

class Scoring
{
    /**
     * Base tile values for English-language Scrabble.
     * @return array<string, int>
     */
    public static function tileValues(): array
    {
        return [
            'A' => 1, 'E' => 1, 'I' => 1, 'O' => 1, 'N' => 1, 'R' => 1, 'T' => 1, 'L' => 1, 'S' => 1, 'U' => 1,
            'D' => 2, 'G' => 2,
            'B' => 3, 'C' => 3, 'M' => 3, 'P' => 3,
            'F' => 4, 'H' => 4, 'V' => 4, 'W' => 4, 'Y' => 4,
            'K' => 5,
            'J' => 8, 'X' => 8,
            'Q' => 10, 'Z' => 10,
        ];
    }

    /**
     * Score a set of placements on the board (single word only for now).
     * @param Board $board
     * @param array<int, array{coord: string, tile: Tile}> $placements
     * @return array{total:int, wordMultiplier:int, letterSum:int, placements: array<int, array{coord:string, letter:string, base:int, letterMultiplier:int}>}
     */
    public static function scorePlacements(Board $board, array $placements): array
    {
        $letterSum = 0;
        $wordMultiplier = 1;
        $breakdown = [];

        foreach ($placements as $placement) {
            $coord = strtoupper($placement['coord']);
            $tile = $placement['tile'];
            $premium = $board->premiumAtCoordinate($coord);

            $letterMultiplier = self::letterMultiplier($premium);
            $wordMultiplier *= self::wordMultiplier($premium);
            $baseValue = $tile->isBlank() ? 0 : $tile->value();

            $letterSum += $baseValue * $letterMultiplier;

            $breakdown[] = [
                'coord' => $coord,
                'letter' => $tile->letter(),
                'base' => $baseValue,
                'letterMultiplier' => $letterMultiplier,
            ];
        }

        $total = $letterSum * $wordMultiplier;

        return [
            'total' => $total,
            'wordMultiplier' => $wordMultiplier,
            'letterSum' => $letterSum,
            'placements' => $breakdown,
        ];
    }

    /**
     * Score a move with a main word in the provided direction and optional cross-words.
     * @param Board $board
     * @param array<int, array{coord:string, tile: Tile}> $placements
     * @param string $direction
     * @return array{
     *   total:int,
     *   mainWord: array{word:string, total:int, breakdown: array<int, array{coord:string, letter:string, letterScore:int, letterMultiplier:int}>},
     *   crossWords: array<int, array{word:string, score:int, start:string, direction:string}>
     * }
     */
    public static function scoreMove(Board $board, array $placements, string $direction = 'horizontal'): array
    {
        $axis = strtolower($direction) === 'vertical' ? 'vertical' : 'horizontal';
        $placementMap = [];
        foreach ($placements as $placement) {
            $placementMap[$placement['coord']] = $placement['tile'];
        }

        $mainWordResult = self::scoreWord($board, $placementMap, $axis);
        $crossWords = self::scoreCrossWords($board, $placementMap, $axis);

        $crossTotal = array_sum(array_map(static fn ($item) => $item['score'], $crossWords));

        return [
            'total' => $mainWordResult['total'] + $crossTotal,
            'mainWord' => $mainWordResult,
            'crossWords' => $crossWords,
        ];
    }

    public static function letterMultiplier(string $premium): int
    {
        return match ($premium) {
            'TL' => 3,
            'DL' => 2,
            default => 1,
        };
    }

    public static function wordMultiplier(string $premium): int
    {
        return match ($premium) {
            'TW' => 3,
            'DW' => 2,
            default => 1,
        };
    }

    /**
     * @param array<string, Tile> $placementMap
     * @return array{word:string, total:int, breakdown: array<int, array{coord:string, letter:string, letterScore:int, letterMultiplier:int}>}
     */
    private static function scoreWord(Board $board, array $placementMap, string $axis): array
    {
        $coords = array_keys($placementMap);
        if ($coords === []) {
            return ['word' => '', 'total' => 0, 'breakdown' => []];
        }

        [$rows, $columns] = self::sortedSpan($coords);
        $isHorizontal = $axis === 'horizontal';

        $startRow = $rows['min'];
        $startColumn = $columns['min'];

        // Extend to include existing tiles that precede the placement range.
        while (true) {
            $nextRow = $isHorizontal ? $startRow : $startRow - 1;
            $nextColumn = $isHorizontal ? $startColumn - 1 : $startColumn;
            if ($nextRow < 1 || $nextColumn < 1) {
                break;
            }
            if ($board->tileAtPosition($nextRow, $nextColumn) === null) {
                break;
            }
            $startRow = $nextRow;
            $startColumn = $nextColumn;
        }

        $letters = [];
        $total = 0;
        $wordMultiplier = 1;
        $breakdown = [];

        $endRow = $rows['max'];
        $endColumn = $columns['max'];

        while (true) {
            $nextRow = $isHorizontal ? $startRow : $endRow + 1;
            $nextColumn = $isHorizontal ? $endColumn + 1 : $startColumn;
            if ($nextRow > Board::ROWS || $nextColumn > Board::COLUMNS) {
                break;
            }
            if ($board->tileAtPosition($nextRow, $nextColumn) === null) {
                break;
            }
            $endRow = $isHorizontal ? $startRow : $nextRow;
            $endColumn = $isHorizontal ? $nextColumn : $startColumn;
        }

        $length = $isHorizontal ? ($endColumn - $startColumn + 1) : ($endRow - $startRow + 1);
        for ($i = 0; $i < $length; $i++) {
            $row = $startRow + ($isHorizontal ? 0 : $i);
            $column = $startColumn + ($isHorizontal ? $i : 0);
            $coord = Board::coordinateKey($row, $column);
            $tile = $placementMap[$coord] ?? $board->tileAtPosition($row, $column);
            if ($tile === null) {
                // Skip gaps between placements.
                continue;
            }

            $premium = isset($placementMap[$coord]) ? $board->premiumAt($row, $column) : '';
            $letterMultiplier = self::letterMultiplier($premium);
            $wordMultiplier *= isset($placementMap[$coord]) ? self::wordMultiplier($premium) : 1;
            $letterScore = $tile->isBlank() ? 0 : $tile->value();

            $total += $letterScore * $letterMultiplier;
            $letters[] = $tile->letter();
            $breakdown[] = [
                'coord' => $coord,
                'letter' => $tile->letter(),
                'letterScore' => $letterScore,
                'letterMultiplier' => $letterMultiplier,
            ];
        }

        return [
            'word' => implode('', $letters),
            'total' => $total * $wordMultiplier,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @param array<string, Tile> $placementMap
     * @return array<int, array{word:string, score:int, start:string, direction:string}>
     */
    private static function scoreCrossWords(Board $board, array $placementMap, string $axis): array
    {
        $isHorizontal = $axis === 'horizontal';
        $results = [];

        foreach ($placementMap as $coord => $tile) {
            [$row, $column] = Board::parseCoordinate($coord);
            $wordData = self::perpendicularWordData($board, $placementMap, $row, $column, $tile, $isHorizontal);

            if ($wordData['letters'] === [] || count($wordData['letters']) === 1) {
                continue;
            }

            $letters = $wordData['letters'];
            $wordMultiplier = $wordData['wordMultiplier'];
            $scoreSum = $wordData['score'];

            $results[] = [
                'word' => implode('', $letters),
                'score' => $scoreSum * $wordMultiplier,
                'start' => Board::coordinateKey($wordData['startRow'], $wordData['startColumn']),
                'direction' => $isHorizontal ? 'vertical' : 'horizontal',
            ];
        }

        return $results;
    }

    /**
     * @param array<string, Tile> $placementMap
     * @return array{letters: string[], score:int, wordMultiplier:int, startRow:int, startColumn:int}
     */
    private static function perpendicularWordData(Board $board, array $placementMap, int $row, int $column, Tile $centerTile, bool $isHorizontal): array
    {
        [$rowDelta, $colDelta] = $isHorizontal ? [1, 0] : [0, 1];
        $letters = [];
        $score = 0;
        $wordMultiplier = 1;

        $startRow = $row;
        $startColumn = $column;

        $r = $row;
        $c = $column;
        while (true) {
            $r -= $rowDelta;
            $c -= $colDelta;
            if ($r < 1 || $c < 1) {
                break;
            }
            $tile = $board->tileAtPosition($r, $c) ?? $placementMap[Board::coordinateKey($r, $c)] ?? null;
            if ($tile === null) {
                break;
            }
            $startRow = $r;
            $startColumn = $c;
            array_unshift($letters, $tile->letter());
            $score += $tile->isBlank() ? 0 : $tile->value();
        }

        $letters[] = $centerTile->letter();
        $centerPremium = $board->premiumAt($row, $column);
        $score += ($centerTile->isBlank() ? 0 : $centerTile->value()) * self::letterMultiplier($centerPremium);
        $wordMultiplier *= self::wordMultiplier($centerPremium);

        $r = $row;
        $c = $column;
        while (true) {
            $r += $rowDelta;
            $c += $colDelta;
            if ($r > Board::ROWS || $c > Board::COLUMNS) {
                break;
            }
            $tile = $board->tileAtPosition($r, $c) ?? $placementMap[Board::coordinateKey($r, $c)] ?? null;
            if ($tile === null) {
                break;
            }
            $letters[] = $tile->letter();
            $score += $tile->isBlank() ? 0 : $tile->value();
        }

        return [
            'letters' => $letters,
            'score' => $score,
            'wordMultiplier' => $wordMultiplier,
            'startRow' => $startRow,
            'startColumn' => $startColumn,
        ];
    }

    /** @return array{array{min:int, max:int}, array{min:int, max:int}} */
    private static function sortedSpan(array $coords): array
    {
        $rows = [];
        $columns = [];
        foreach ($coords as $coord) {
            [$row, $column] = Board::parseCoordinate($coord);
            $rows[] = $row;
            $columns[] = $column;
        }

        return [
            ['min' => min($rows), 'max' => max($rows)],
            ['min' => min($columns), 'max' => max($columns)],
        ];
    }
}
