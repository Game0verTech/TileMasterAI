<?php

declare(strict_types=1);

namespace TileMasterAI\Game;

class Tile
{
    private string $letter;
    private int $value;
    private bool $isBlank;

    public function __construct(string $letter, bool $isBlank = false, ?int $value = null)
    {
        $upper = strtoupper($letter);
        $this->letter = $upper;
        $this->isBlank = $isBlank;
        $this->value = $isBlank ? 0 : ($value ?? Scoring::tileValue($upper));
    }

    public static function fromLetter(string $letter, bool $isBlank = false): self
    {
        return new self($letter, $isBlank);
    }

    public function letter(): string
    {
        return $this->letter;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function isBlank(): bool
    {
        return $this->isBlank;
    }
}
