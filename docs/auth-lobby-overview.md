# Auth + lobby overhaul summary

## Stack recap
- PHP front end with lightweight JSON endpoints.
- SQLite (default) or MySQL via env vars through `src/Server/Db/Connection.php`.

## What's new
- Account-based authentication with registration, login, logout (`api/auth.php`).
- First registered user is automatically promoted to `admin`.
- Admin dashboard (`/api/admin.php` + UI) lists users, lobbies, and active sessions and allows deletion.
- New lobby model (`lobbies`, `lobby_players`, `games`) replaces the previous session-based lobby flow.
- Game launch draws Scrabble tiles to establish deterministic turn order before redirecting to `game.php`.

## Typical flow
1. Register a new account on the landing page; the first account gains admin rights.
2. Log in to reveal the lobby list and admin dashboard (if applicable).
3. Create or join a lobby by code, ready up, and wait for all players (min 2) to ready.
4. When everyone is ready, the lobby transitions to `in_game`, the game record is created, and players are redirected to `game.php` to view the turn order.
5. Admins can delete users, lobbies, or active sessions from the dashboard.

## Running locally
- Ensure PHP is available. The default SQLite database lives under `data/tilemaster.sqlite`.
- Start a PHP dev server: `php -S 0.0.0.0:8000` from the repository root.
- Open `http://localhost:8000` to register, log in, and manage lobbies.

## Future improvements
- Persist full board state and in-progress turns (currently only turn order is captured).
- Add real-time updates via WebSockets instead of polling.
- Harden validation (password complexity, rate limiting) and add automated tests.
