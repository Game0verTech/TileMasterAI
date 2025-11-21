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
    :root {
      color-scheme: dark;
      --bg: #0b1224;
      --card: #0f172a;
      --border: #1e293b;
      --accent: #6366f1;
      --muted: #94a3b8;
      --text: #e2e8f0;
      --danger: #ef4444;
      --success: #22c55e;
    }

    * { box-sizing: border-box; }

    body { font-family: "Inter", system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 24px; }
    .card { background: var(--card); border-radius: 16px; padding: 20px; max-width: 1100px; margin: 0 auto 18px; border: 1px solid var(--border); box-shadow: 0 16px 36px rgba(0,0,0,0.25); }
    h1 { margin-top: 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 8px; text-align: left; border-bottom: 1px solid var(--border); }
    th { color: #cbd5e1; }
    .pill { padding: 4px 10px; border-radius: 999px; font-weight: 700; }
    .pill.ready { background: #10b981; color: #022c22; }
    .pill.turn { background: #6366f1; color: #eef2ff; }
    a { color: #38bdf8; }
    .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    button { border: none; border-radius: 12px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
    .btn-primary { background: var(--accent); color: #fff; }
    .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text); }
    .notice { color: var(--muted); }
    .muted { color: var(--muted); }
    .section-title { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
    .grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }

    .board-shell { background: #0c162d; border: 1px solid var(--border); border-radius: 18px; padding: 16px; }
    .board { display: grid; grid-template-columns: repeat(15, 1fr); gap: 4px; }
    .cell { width: 36px; height: 36px; border-radius: 6px; background: #0b203d; border: 1px solid #1e293b; display: grid; place-items: center; color: #cbd5e1; font-weight: 700; cursor: pointer; }
    .cell.special { background: #172554; color: #93c5fd; border-color: #1d4ed8; font-size: 11px; text-align: center; line-height: 1.1; padding: 4px; }
    .cell.filled { background: #0ea5e9; color: #0b1224; }
    .rack { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; background: #0c162d; border: 1px solid var(--border); border-radius: 12px; padding: 10px; }
    .tile { background: #e2e8f0; color: #0b1224; font-weight: 800; font-size: 18px; border-radius: 8px; display: grid; place-items: center; height: 42px; cursor: pointer; box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
    .tile.empty { background: #1f2937; color: #64748b; cursor: not-allowed; }
    .board-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }

    .modal-backdrop { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6); display: grid; place-items: center; z-index: 20; }
    .modal { background: var(--card); border-radius: 16px; padding: 20px; max-width: 480px; width: 90%; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4); border: 1px solid var(--border); }
    .modal h3 { margin-top: 0; }
    .modal-footer { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
    .hidden { display: none; }
    .draw-display { font-size: 72px; font-weight: 900; text-align: center; letter-spacing: 4px; margin: 12px 0; }
    .countdown { font-size: 32px; font-weight: 800; text-align: center; }
  </style>
</head>
<body>
  <div class="card">
    <div class="section-title">
      <div>
        <a href="/">← Back to lobby</a>
        <h1>Game room</h1>
        <p class="notice">Draw tiles here to set turn order, then play on the board.</p>
      </div>
      <div id="userBadge" class="pill turn"></div>
    </div>
    <p id="gameStatus">Loading...</p>

    <div class="grid">
      <div class="board-shell" id="drawStage">
        <div class="board-header">
          <div>
            <h3>Tile draw</h3>
            <p class="notice" id="drawNotice">Waiting for players to draw...</p>
          </div>
          <button class="btn-primary" id="drawTileBtn">Draw tile</button>
        </div>
        <table>
          <thead><tr><th>Player</th><th>Status</th><th>Tile</th></tr></thead>
          <tbody id="drawTable"></tbody>
        </table>
      </div>

      <div class="board-shell" id="orderStage">
        <div class="board-header">
          <div>
            <h3>Turn order</h3>
            <p class="notice">Final order appears when everyone draws.</p>
          </div>
        </div>
        <table>
          <thead><tr><th>#</th><th>Player</th><th>Tile</th></tr></thead>
          <tbody id="orderTable"></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card hidden" id="boardCard">
    <div class="board-header">
      <div>
        <h2>Scrabble board</h2>
        <p class="notice">Place tiles from the upper or lower racks.</p>
      </div>
      <div id="turnHint" class="pill turn">Waiting...</div>
    </div>
    <div class="stack" style="display:grid; gap:12px;">
      <div>
        <p class="notice">Upper dock</p>
        <div class="rack" id="upperRack"></div>
      </div>
      <div class="board" id="boardGrid"></div>
      <div>
        <p class="notice">Lower dock</p>
        <div class="rack" id="lowerRack"></div>
      </div>
    </div>
  </div>

  <div class="modal-backdrop hidden" id="drawModal">
    <div class="modal">
      <h3>Drawing tile...</h3>
      <div class="draw-display" id="drawTicker">?</div>
      <p class="notice" id="drawResultText"></p>
    </div>
  </div>

  <div class="modal-backdrop hidden" id="startModal">
    <div class="modal">
      <h3 id="startModalTitle">Game starting</h3>
      <p id="startModalMessage" class="notice"></p>
      <div class="countdown" id="startCountdown">3</div>
    </div>
  </div>

  <script>
    const params = new URLSearchParams(window.location.search);
    const lobbyId = params.get('lobbyId');

    const state = {
      user: null,
      lobby: null,
      game: null,
      players: [],
      boardReady: false,
      announced: false,
      board: [],
      upperRack: [],
      lowerRack: [],
      selectedTile: null,
    };

    const drawStage = document.getElementById('drawStage');
    const orderStage = document.getElementById('orderStage');
    const drawNotice = document.getElementById('drawNotice');
    const drawTable = document.getElementById('drawTable');
    const orderTable = document.getElementById('orderTable');
    const drawModal = document.getElementById('drawModal');
    const drawTicker = document.getElementById('drawTicker');
    const drawResultText = document.getElementById('drawResultText');
    const startModal = document.getElementById('startModal');
    const startModalTitle = document.getElementById('startModalTitle');
    const startModalMessage = document.getElementById('startModalMessage');
    const startCountdown = document.getElementById('startCountdown');
    const boardCard = document.getElementById('boardCard');
    const boardGrid = document.getElementById('boardGrid');
    const upperRack = document.getElementById('upperRack');
    const lowerRack = document.getElementById('lowerRack');
    const turnHint = document.getElementById('turnHint');

    function showModal(el) { el.classList.remove('hidden'); }
    function hideModal(el) { el.classList.add('hidden'); }

    async function fetchUser() {
      const res = await fetch('/api/auth.php');
      if (!res.ok) return;
      const data = await res.json();
      state.user = data.user;
      document.getElementById('userBadge').textContent = state.user ? `${state.user.username} (${state.user.role})` : '';
    }

    async function loadGame() {
      const res = await fetch(`/api/game.php?lobbyId=${encodeURIComponent(lobbyId)}`);
      const data = await res.json();
      if (!data.success) {
        document.getElementById('gameStatus').textContent = data.message || 'Unable to load game.';
        return;
      }
      state.lobby = data.lobby;
      state.game = data.game;
      state.players = data.players || [];
      document.getElementById('gameStatus').textContent = `Lobby ${data.lobby.code} • status ${data.lobby.status}`;
      renderDraws();
      renderTurnOrder();
      handleStartCountdown();
    }

    function renderDraws() {
      drawTable.innerHTML = '';
      const draws = state.game?.draws || [];
      state.players.forEach((player) => {
        const drawn = draws.find((d) => d.user_id === player.user_id);
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${player.username}${player.user_id === state.lobby.owner_user_id ? ' (owner)' : ''}</td>`
          + `<td>${player.is_ready ? '<span class="pill ready">ready</span>' : 'not ready'}</td>`
          + `<td>${drawn ? `<span class="pill turn">${drawn.tile}</span>` : 'Pending'}</td>`;
        drawTable.appendChild(tr);
      });

      const myDraw = draws.find((d) => d.user_id === state.user?.id);
      const drawBtn = document.getElementById('drawTileBtn');
      drawBtn.disabled = !!myDraw || state.lobby.status !== 'drawing';
      drawNotice.textContent = draws.length === state.players.length
        ? 'All players have drawn. Finalizing order...'
        : `${draws.length}/${state.players.length} players have drawn.`;
      drawStage.style.opacity = state.lobby.status === 'drawing' ? '1' : '0.5';
    }

    function renderTurnOrder() {
      orderTable.innerHTML = '';
      (state.game?.turn_order || []).forEach((entry, index) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${index + 1}</td><td>${entry.username}</td><td class="pill turn">${entry.tile}</td>`;
        orderTable.appendChild(tr);
      });
    }

    function buildBoardState() {
      state.board = Array.from({ length: 15 }, () => Array(15).fill(''));
      const specials = ['DW','TW','TL','DL'];
      boardGrid.innerHTML = '';
      for (let r = 0; r < 15; r += 1) {
        for (let c = 0; c < 15; c += 1) {
          const cell = document.createElement('div');
          cell.className = 'cell';
          if ((r === c && (r % 7 === 0 || r === 7)) || (r + c === 14 && (r % 7 === 0 || r === 7))) {
            cell.classList.add('special');
            cell.textContent = 'TW';
          } else if (r === 7 && c === 7) {
            cell.classList.add('special');
            cell.textContent = '★';
          } else if ((r === 1 || r === 13 || r === 5 || r === 9) && (c === 5 || c === 9)) {
            cell.classList.add('special');
            cell.textContent = 'TL';
          } else if ((r === 3 || r === 11) && (c === 0 || c === 7 || c === 14)) {
            cell.classList.add('special');
            cell.textContent = 'DW';
          } else if ((c === 3 || c === 11) && (r === 0 || r === 7 || r === 14)) {
            cell.classList.add('special');
            cell.textContent = 'DW';
          } else if ((r === 6 || r === 8) && (c === 2 || c === 6 || c === 8 || c === 12)) {
            cell.classList.add('special');
            cell.textContent = 'DL';
          } else if ((c === 6 || c === 8) && (r === 2 || r === 6 || r === 8 || r === 12)) {
            cell.classList.add('special');
            cell.textContent = 'DL';
          }
          cell.dataset.row = r;
          cell.dataset.col = c;
          cell.onclick = () => placeSelected(cell);
          boardGrid.appendChild(cell);
        }
      }
    }

    function randomRackFromBag() {
      const bag = (state.game?.draw_pool || []).slice();
      const rack = [];
      while (rack.length < 7 && bag.length) {
        const idx = Math.floor(Math.random() * bag.length);
        rack.push(bag.splice(idx, 1)[0]);
      }
      while (rack.length < 7) rack.push('');
      return rack;
    }

    function ensureRacks() {
      const keyUpper = `tmai-upper-${lobbyId}`;
      const keyLower = `tmai-lower-${lobbyId}`;
      const cachedUpper = localStorage.getItem(keyUpper);
      const cachedLower = localStorage.getItem(keyLower);
      state.upperRack = cachedUpper ? JSON.parse(cachedUpper) : randomRackFromBag();
      state.lowerRack = cachedLower ? JSON.parse(cachedLower) : randomRackFromBag();
      localStorage.setItem(keyUpper, JSON.stringify(state.upperRack));
      localStorage.setItem(keyLower, JSON.stringify(state.lowerRack));
      renderRacks();
    }

    function renderRacks() {
      upperRack.innerHTML = '';
      lowerRack.innerHTML = '';
      state.upperRack.forEach((tile, idx) => {
        const div = document.createElement('div');
        div.className = tile ? 'tile' : 'tile empty';
        div.textContent = tile || '·';
        div.onclick = () => selectTile('upper', idx);
        upperRack.appendChild(div);
      });
      state.lowerRack.forEach((tile, idx) => {
        const div = document.createElement('div');
        div.className = tile ? 'tile' : 'tile empty';
        div.textContent = tile || '·';
        div.onclick = () => selectTile('lower', idx);
        lowerRack.appendChild(div);
      });
    }

    function selectTile(rack, idx) {
      const tile = rack === 'upper' ? state.upperRack[idx] : state.lowerRack[idx];
      if (!tile) return;
      state.selectedTile = { rack, idx, tile };
      turnHint.textContent = `Selected ${tile}. Click a board cell to place.`;
    }

    function placeSelected(cell) {
      if (!state.selectedTile || !cell || cell.classList.contains('filled')) return;
      cell.textContent = state.selectedTile.tile;
      cell.classList.add('filled');
      if (state.selectedTile.rack === 'upper') {
        state.upperRack[state.selectedTile.idx] = '';
      } else {
        state.lowerRack[state.selectedTile.idx] = '';
      }
      state.selectedTile = null;
      renderRacks();
      turnHint.textContent = 'Placed tile. Select another from a dock.';
    }

    function startDrawAnimation(finalTile) {
      showModal(drawModal);
      drawResultText.textContent = 'Shuffling tiles...';
      let delay = 40;
      let spins = 16;

      const spin = () => {
        drawTicker.textContent = String.fromCharCode(65 + Math.floor(Math.random() * 26));
        if (spins <= 0) {
          drawTicker.textContent = finalTile;
          drawResultText.textContent = `You drew ${finalTile}`;
          setTimeout(() => hideModal(drawModal), 2200);
          return;
        }
        spins -= 1;
        delay = Math.min(delay + 30, 200);
        setTimeout(spin, delay);
      };

      spin();
    }

    async function submitDraw() {
      showModal(drawModal);
      drawResultText.textContent = 'Drawing...';
      const res = await fetch('/api/game.php', { method: 'POST', body: JSON.stringify({ action: 'draw', lobbyId }) });
      const data = await res.json();
      if (data.success) {
        const tile = data.result.tile;
        startDrawAnimation(tile);
        await loadGame();
      } else {
        hideModal(drawModal);
        alert(data.message || 'Unable to draw tile.');
      }
    }

    function showBoard() {
      if (state.boardReady) return;
      state.boardReady = true;
      buildBoardState();
      ensureRacks();
      document.getElementById('boardCard').classList.remove('hidden');
      turnHint.textContent = `First turn: ${state.game.turn_order?.[0]?.username || 'Player'}`;
    }

    function handleStartCountdown() {
      if (state.lobby.status !== 'in_game' || !state.game.turn_order?.length || state.announced) return;
      state.announced = true;
      const first = state.game.turn_order[0]?.username || 'A player';
      startModalTitle.textContent = `${first} will go first`;
      startModalMessage.textContent = 'Get ready — launching the board in 3 seconds.';
      let count = 3;
      startCountdown.textContent = count;
      showModal(startModal);
      const timer = setInterval(() => {
        count -= 1;
        startCountdown.textContent = count;
        if (count <= 0) {
          clearInterval(timer);
          hideModal(startModal);
          showBoard();
        }
      }, 1000);
    }

    document.getElementById('drawTileBtn').onclick = submitDraw;

    fetchUser();
    loadGame();
    setInterval(loadGame, 2500);
  </script>
</body>
</html>
