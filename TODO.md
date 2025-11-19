# TileMasterAI To-Do & Design Log

A living checklist to track progress while designing and building TileMasterAI.

## Status legend
- [ ] To do
- [~] In progress
- [x] Done

## Phase 1 – Bootstrap & deployment
- [x] PHP landing page loads `config/env.php` and shows basic status.
- [x] Finalize deployment pipeline (GitHub webhook, server pull/build hooks). *(planned when backend stack is finalized).*

## Phase 2 – Experience design (current focus)
- [x] Define UX flows for the MVP (board setup → rack input → best moves → optional uploads).
- [x] Produce layout structure for the main page (board grid, rack bar, action panel, move list).
- [x] Choose a cohesive visual system (colors/typography) and apply to the base page.
- [x] Create responsive breakpoints for mobile-first board interaction.
- [x] Plan keyboard/touch interactions (drag/drop or tap-to-place, long-press for blanks).
- [x] Draft mock content for move results (top 5), including placement notation.
- [x] Design upload affordances for board/rack images with clear “stub/coming soon” messaging.

## Phase 3 – Game engine foundations
- [x] Model board (15x15 with bonuses) and rack structures in code.
- [x] Load a pluggable dictionary (word list file) and expose lookup utilities.
- [x] Implement scoring helpers (tile values, multipliers, blanks).

## Phase 4 – Move generation & validation
- [ ] Anchor-based move generator with cross-check validation.
- [ ] Scoring integration for main word and cross-words.
- [ ] API contract for requesting top N moves.

## Phase 5 – Frontend implementation
- [ ] Build interactive board and rack UI per design.
- [ ] Show top move suggestions with score and placement details.
- [ ] Controls: reset board, clear rack, run solver.

## Phase 6 – Image upload & OCR stubs
- [ ] File/camera upload endpoints for board and rack images (stubbed parsing response).
- [ ] Frontend hooks to preview uploads and display placeholder parse results.
- [ ] Document future OCR integration points.

## Phase 7 – AI advisor abstraction
- [ ] Stub `aiAdvisor` interface (env-keyed) for future move explanations.
- [ ] Add UI surface for optional AI insights (copy/placeholder only).

## Notes & decisions
- Current stack in repo: PHP landing page; Node/React stack still planned for richer app if/when introduced.
- Environment variables stay outside Git (`.env`), loaded via `config/env.php`; never log or expose secrets.
- Keep UX modern and touch-friendly; prioritize clarity for bonus squares and blank tile selection.
