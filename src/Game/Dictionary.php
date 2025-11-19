<?php

declare(strict_types=1);

namespace TileMasterAI\Game;

class Dictionary
{
    private string $path;

    /** @var array<string, bool> */
    private array $words = [];

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function count(): int
    {
        return count($this->words);
    }

    public function has(string $word): bool
    {
        $key = strtoupper(trim($word));
        return $key !== '' && isset($this->words[$key]);
    }

    private function load(): void
    {
        if (!is_readable($this->path)) {
            return;
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $word = strtoupper(trim($line));
            if ($word !== '') {
                $this->words[$word] = true;
            }
        }
    }
}
