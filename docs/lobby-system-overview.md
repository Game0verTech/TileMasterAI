# Lobby and session system overview

## Identity and sessions
- The server issues a `player_token` cookie on first visit. This token anchors the player row in the `players` table.
- Player names are stored in the same row; `/api/lobbies.php` `set_name` updates it.
- The cookie plus the name the user enters keeps the session stable across refreshes.

## Data model
- `sessions` table is reused as the lobby container (`code`, `name`, `status`).
- `session_players` tracks lobby membership, host flag, join order, and the new `is_ready`/`ready_at` readiness markers.
- `tile_bag` + `turn_states` seed the Scrabble tile bag and store the final turn order once the game starts.

## Lifecycle
1. **Landing**: `/api/lobbies.php?action=bootstrap` creates/returns the player token, lists open lobbies, and restores any lobby the player is already seated in.
2. **Create/join**: POST to `/api/lobbies.php` with `action=create|join`, `playerName`, and optional `code` or `lobbyName`.
3. **Ready check**: POST `action=toggle_ready` with `ready=true|false`. Readiness is per-player and stored in `session_players`.
4. **Auto-start**: When there are at least two players and everyone is ready, the server seeds the 100-tile bag, draws one tile per player, computes order (blank before A, then A→Z), writes `turn_states`, and marks the lobby `in_game`.
5. **Play**: Clients are redirected to `game.php?code=LOBBYCODE` once the lobby status flips to `in_game`. Late joins are blocked for in-progress games.
6. **Leave**: POST `action=leave` removes the player; empty lobbies are deleted and hosts auto-rotate.

## Front-end flow
- The landing page shows a name box, join-by-code, create button, and the live lobby list.
- When seated, a lobby card shows roster + ready flags, the start readiness banner, and the turn order once generated.
- Polling runs every 3 seconds while in a lobby to sync roster/ready/start events.
