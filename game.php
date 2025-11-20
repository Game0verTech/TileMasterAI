<?php
require __DIR__ . '/config/env.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TileMasterAI Game</title>
  <style>
    body { font-family: "Inter", system-ui, -apple-system, sans-serif; background: #0b1224; color: #e2e8f0; margin: 0; padding: 24px; }
    .card { background: #0f172a; border-radius: 16px; padding: 20px; max-width: 900px; margin: 0 auto; border: 1px solid #1e293b; }
    h1 { margin-top: 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 8px; text-align: left; border-bottom: 1px solid #1e293b; }
    th { color: #cbd5e1; }
    .pill { padding: 4px 10px; border-radius: 999px; font-weight: 700; }
    .pill.ready { background: #10b981; color: #022c22; }
    .pill.turn { background: #6366f1; color: #eef2ff; }
    a { color: #38bdf8; }
  </style>
</head>
<body>
  <div class="card">
    <a href="/">← Back to lobby</a>
    <h1>Game lobby</h1>
    <p id="gameStatus">Loading...</p>
    <table>
      <thead><tr><th>Turn order</th><th>Player</th><th>Tile</th></tr></thead>
      <tbody id="orderTable"></tbody>
    </table>
  </div>

  <script>
    const params = new URLSearchParams(window.location.search);
    const lobbyId = params.get('lobbyId');
    async function loadGame() {
      const res = await fetch(`/api/game.php?lobbyId=${encodeURIComponent(lobbyId)}`);
      const data = await res.json();
      if (!data.success) {
        document.getElementById('gameStatus').textContent = data.message || 'Unable to load game.';
        return;
      }
      document.getElementById('gameStatus').textContent = `Lobby ${data.lobby.code} • status ${data.lobby.status}`;
      const tbody = document.getElementById('orderTable');
      tbody.innerHTML = '';
      (data.game.turn_order || []).forEach((entry, index) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${index + 1}</td><td>${entry.username}</td><td class="pill turn">${entry.tile}</td>`;
        tbody.appendChild(tr);
      });
    }

    loadGame();
  </script>
</body>
</html>
