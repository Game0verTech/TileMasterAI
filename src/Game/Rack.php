<?php

declare(strict_types=1);

namespace TileMasterAI\Game;

class Rack
{
    /** @var Tile[] */
    private array $tiles = [];

    public function __construct(array $tiles = [])
    {
        foreach ($tiles as $tile) {
            if ($tile instanceof Tile) {
                $this->tiles[] = $tile;
            }
        }
    }

    /**
     * @param string[] $letters
     */
    public static function fromLetters(array $letters): self
    {
        $tiles = array_map(static fn (string $letter) => Tile::fromLetter($letter, $letter === '?'), $letters);
        return new self($tiles);
    }

    /** @return Tile[] */
    public function tiles(): array
    {
        return $this->tiles;
    }

    public function addTile(Tile $tile): void
    {
        $this->tiles[] = $tile;
    }

    public function removeAt(int $index): ?Tile
    {
        if (!array_key_exists($index, $this->tiles)) {
            return null;
        }

        $tile = $this->tiles[$index];
        array_splice($this->tiles, $index, 1);
        return $tile;
    }

    public function shuffle(): void
    {
        shuffle($this->tiles);
    }
}
