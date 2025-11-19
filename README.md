# TileMasterAI

Bootstrap PHP landing page for TileMasterAI.

## Environment configuration
- Create a `.env` file at the project root (not committed to Git) that includes secrets such as `OPENAI_API_KEY`.
- Environment variables are loaded via `config/env.php`. Include this loader in any PHP entrypoint with:
  ```php
  require __DIR__ . '/config/env.php';
  ```
- Access values using `getenv('OPENAI_API_KEY');` and never echo or commit secret values.

## Local usage
Serve the repository with PHP (e.g., `php -S localhost:8000`) and visit the root to confirm the welcome page and environment status indicator.

## Game engine foundations
- Core domain classes live in `src/Game` (board/rack models, dictionary loader, scoring helpers, move generator).
- Override the default word list by setting `DICTIONARY_PATH` to a readable file; otherwise `data/dictionary-mini.txt` is used for local previews.
- Scoring helpers expose classic Scrabble tile values and apply DL/TL/DW/TW multipliers for single-word calculations.
- `MoveGenerator` performs anchor-based horizontal moves with dictionary cross-checks and uses `Scoring::scoreMove()` to total main and perpendicular words.

### Solver API contract (planned)
```
POST /api/moves
{
  "board": [{"coord": "H8", "letter": "O", "blank": false}],
  "rack": ["T", "I", "L", "E", "M", "A", "?"],
  "limit": 5
}
```
The response should return a ranked `moves` array with `word`, `start`, `direction`, `score`, `mainWord`, `crossWords`, and `placements` mirrors of the in-app preview.

## Roadmap & design
Active tasks and design notes live in [TODO.md](./TODO.md). This checklist will evolve as we shape the UX (board grid, rack input, move list, uploads) and hook in the solver/back-end work.
