<?php

declare(strict_types=1);

namespace TileMasterAI\Game;

class Board
{
    public const ROWS = 15;
    public const COLUMNS = 15;

    /** @var array<string, Tile> */
    private array $tiles = [];

    /**
     * Premium layout keyed by row index then column index.
     * @var array<int, array<int, string>>
     */
    private array $premiumLayout;

    public function __construct(?array $premiumLayout = null)
    {
        $this->premiumLayout = $premiumLayout ?? self::standardLayout();
    }

    public static function standard(): self
    {
        return new self(self::standardLayout());
    }

    /** @return array<int, array<int, string>> */
    public static function standardLayout(): array
    {
        return [
            ['TW', '', '', 'DL', '', '', '', 'TW', '', '', '', 'DL', '', '', 'TW'],
            ['', 'DW', '', '', '', 'TL', '', '', '', 'TL', '', '', '', 'DW', ''],
            ['', '', 'DW', '', '', '', 'DL', '', 'DL', '', '', '', 'DW', '', ''],
            ['DL', '', '', 'DW', '', '', '', 'DL', '', '', '', 'DW', '', '', 'DL'],
            ['', '', '', '', 'DW', '', '', '', '', '', 'DW', '', '', '', ''],
            ['', 'TL', '', '', '', 'TL', '', '', '', 'TL', '', '', '', 'TL', ''],
            ['', '', 'DL', '', '', '', 'DL', '', 'DL', '', '', '', 'DL', '', ''],
            ['TW', '', '', 'DL', '', '', '', 'DW', '', '', '', 'DL', '', '', 'TW'],
            ['', '', 'DL', '', '', '', 'DL', '', 'DL', '', '', '', 'DL', '', ''],
            ['', 'TL', '', '', '', 'TL', '', '', '', 'TL', '', '', '', 'TL', ''],
            ['', '', '', '', 'DW', '', '', '', '', '', 'DW', '', '', '', ''],
            ['DL', '', '', 'DW', '', '', '', 'DL', '', '', '', 'DW', '', '', 'DL'],
            ['', '', 'DW', '', '', '', 'DL', '', 'DL', '', '', '', 'DW', '', ''],
            ['', 'DW', '', '', '', 'TL', '', '', '', 'TL', '', '', '', 'DW', ''],
            ['TW', '', '', 'DL', '', '', '', 'TW', '', '', '', 'DL', '', '', 'TW'],
        ];
    }

    public function placeTile(int $row, int $column, Tile $tile): void
    {
        $this->assertInBounds($row, $column);
        $coord = self::coordinateKey($row, $column);
        $this->tiles[$coord] = $tile;
    }

    public function isEmpty(): bool
    {
        return $this->tiles === [];
    }

    public function placeTileByCoordinate(string $coordinate, Tile $tile): void
    {
        [$row, $column] = self::parseCoordinate($coordinate);
        $this->placeTile($row, $column, $tile);
    }

    public function tileAt(string $coordinate): ?Tile
    {
        $coord = strtoupper($coordinate);
        return $this->tiles[$coord] ?? null;
    }

    public function tileAtPosition(int $row, int $column): ?Tile
    {
        $this->assertInBounds($row, $column);
        $coord = self::coordinateKey($row, $column);
        return $this->tiles[$coord] ?? null;
    }

    /**
     * @return array<string, Tile>
     */
    public function tiles(): array
    {
        return $this->tiles;
    }

    public function premiumAt(int $row, int $column): string
    {
        $this->assertInBounds($row, $column);
        return $this->premiumLayout[$row - 1][$column - 1] ?? '';
    }

    public function premiumAtCoordinate(string $coordinate): string
    {
        [$row, $column] = self::parseCoordinate($coordinate);
        return $this->premiumAt($row, $column);
    }

    public static function coordinateKey(int $row, int $column): string
    {
        return self::rowLabel($row) . $column;
    }

    /** @return array{int, int} */
    public static function parseCoordinate(string $coordinate): array
    {
        $coordinate = strtoupper(trim($coordinate));
        $rowLabel = $coordinate[0] ?? '';
        $column = (int) substr($coordinate, 1);

        if ($rowLabel === '' || $column < 1) {
            throw new \InvalidArgumentException("Invalid coordinate: {$coordinate}");
        }

        $row = ord($rowLabel) - 64; // 'A' => 1
        return [$row, $column];
    }

    private function assertInBounds(int $row, int $column): void
    {
        if ($row < 1 || $row > self::ROWS || $column < 1 || $column > self::COLUMNS) {
            throw new \OutOfBoundsException('Coordinate outside 15x15 grid.');
        }
    }

    private static function rowLabel(int $row): string
    {
        $row = max(1, min(self::ROWS, $row));
        return chr(64 + $row);
    }
}
