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
}
