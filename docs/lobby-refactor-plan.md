# Lobby and session refactor plan

## Current state (before refactor)
- Sessions and lobbies are represented by `sessions` rows with short codes and a `status` string (`pending`, `active`, `started`).
- Players persist via a `client_token` saved in localStorage and mirrored in the `players` table.
- Lobby membership is stored in `session_players` with host + join order only; no ready tracking.
- REST endpoints:
  - `api/sessions.php` to list/create/delete lobbies by code.
  - `api/session_players.php` to join/leave/start a lobby and poll roster/turn state.
- Front-end lobby UI lives in `index.php` with a WebSocket-based refresh hook; readiness and deterministic game start are not implemented.

## Pain points
- No ready state or automatic game start; host manually triggers start without tile draw ordering.
- Multiple overlapping endpoints and ad-hoc WebSocket pings lead to scattered lobby state.
- Identity is client-token-only with no server-managed session cookie and no welcome flow when first landing.
- Turn order is not seeded from a Scrabble tile draw before the game begins.

## Refactor plan
- Introduce a unified `api/lobbies.php` endpoint handling bootstrap, lobby listing/details, create/join/leave, ready toggles, and game start.
- Establish a lightweight player session via an HTTP cookie generated server-side; keep names synced to the `players` table.
- Extend the data model with ready flags on `session_players` and optional lobby names while reusing existing tables.
- Implement lobby readiness rules: when >=2 players and all ready, draw tiles from the 100-tile bag to set turn order, persist turn state, and move lobby to `in_game`.
- Replace the landing page JS with a welcome flow (enter name, list/create/join lobby) and a lobby view showing roster + ready controls using polling for updates.
- Document the new lobby lifecycle and data shapes for future maintenance.
