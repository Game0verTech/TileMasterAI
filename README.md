# TileMasterAI

Bootstrap PHP landing page for TileMasterAI.

## Environment configuration
- Create a `.env` file at the project root (not committed to Git) that includes secrets such as `OPENAI_API_KEY`.
- Environment variables are loaded via `config/env.php`. Include this loader in any PHP entrypoint with:
  ```php
  require __DIR__ . '/config/env.php';
  ```
- Access values using `getenv('OPENAI_API_KEY');` and never echo or commit secret values.

### Database configuration
- The app now ships with a lightweight PDO wrapper that defaults to SQLite at `data/tilemaster.sqlite`.
- To switch to MySQL without code changes, provide environment variables:
  - `DB_CONNECTION=mysql`
  - `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (or a full `DB_DSN` override)
- The connection layer will auto-bootstrap the schema if the `sessions` table is missing. You can also run migrations manually with `php scripts/migrate.php` and seed local data with `php scripts/seed.php`.

## Local usage
Serve the repository with PHP (e.g., `php -S localhost:8000`) and visit the root to confirm the welcome page and environment status indicator.

## Scrabble sanity checks
- `Scoring::tileDistribution()` enumerates the full 100-tile English set (including blanks) with their official point values.
- The landing page lists live sanity checks for tile counts, premium-grid symmetry, center start square, dictionary health, and OpenAI key presence.
- Board and rack samples derive tile values from `Scoring::tileValue()` so the face values always match classic scoring.

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

## UI layout overview
- `game.php` renders the live board experience with a two-column layout (board on the left, insights on the right) beneath a sticky header.
- The bottom control bar groups Submit/Shuffle/Pass/Exchange/AI controls with the rack in a raised panel for emphasis.
- View tools sit alongside the board header so zoom/fit/center options stay connected to the play area, while a sidebar log captures recent turn feedback.
