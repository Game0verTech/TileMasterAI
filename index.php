<?php
require __DIR__ . '/config/env.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TileMasterAI Lobby</title>
  <style>
    :root {
      color-scheme: light;
      font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
      --ink: #0f172a;
      --muted: #475569;
      --border: #e2e8f0;
      --card: #ffffff;
      --bg: #f8fafc;
      --accent: #6366f1;
      --accent-strong: #4f46e5;
      --success: #16a34a;
      --danger: #dc2626;
      --amber: #f59e0b;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      background: radial-gradient(circle at 20% 20%, #e0e7ff 0%, rgba(224, 231, 255, 0) 32%),
        radial-gradient(circle at 80% 0%, #fce7f3 0%, rgba(252, 231, 243, 0) 30%),
        linear-gradient(180deg, #f8fafc 0%, #eef2ff 60%, #e2e8f0 100%);
      min-height: 100vh;
      color: var(--ink);
      padding: 28px 18px 48px;
    }

    header {
      max-width: 960px;
      margin: 0 auto 20px;
      display: grid;
      gap: 14px;
      grid-template-columns: 1fr auto;
      align-items: center;
    }

    .brand { display: flex; gap: 12px; align-items: center; }
    .logo { width: 56px; height: 56px; border-radius: 16px; background: linear-gradient(135deg, #4f46e5, #22d3ee); box-shadow: 0 16px 40px rgba(79, 70, 229, 0.22); display: grid; place-items: center; color: #fff; font-weight: 800; font-size: 22px; letter-spacing: 0.02em; }
    h1 { margin: 0; font-size: clamp(26px, 4vw, 34px); }
    p.subtitle { margin: 4px 0 0; color: var(--muted); }

    .status-board { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
    .pill { padding: 9px 12px; border-radius: 999px; border: 1px solid var(--border); background: rgba(255, 255, 255, 0.88); color: var(--muted); font-weight: 700; letter-spacing: 0.02em; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06); display: inline-flex; align-items: center; gap: 8px; }
    .pill.primary { background: linear-gradient(135deg, #6366f1, #22d3ee); color: #fff; border-color: transparent; box-shadow: 0 12px 28px rgba(79, 70, 229, 0.25); }
    .pill.success { background: #ecfdf3; color: #166534; border-color: #bbf7d0; box-shadow: none; }
    .pill.danger { background: #fff1f2; color: #b91c1c; border-color: #fecaca; box-shadow: none; }

    main { max-width: 960px; margin: 0 auto; display: grid; gap: 18px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 18px; padding: 18px; box-shadow: 0 14px 30px rgba(15, 23, 42, 0.08); }
    .section-title { display: flex; align-items: center; gap: 8px; margin: 0 0 6px; font-size: 14px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: var(--muted); }
    .grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }

    input, button { font: inherit; border-radius: 12px; border: 1px solid var(--border); padding: 12px; }
    input { background: #f8fafc; width: 100%; }
    label { font-weight: 700; color: var(--muted); font-size: 14px; }
    .btn { background: linear-gradient(135deg, #6366f1, #22d3ee); color: #fff; font-weight: 800; cursor: pointer; border: none; transition: transform 120ms ease, box-shadow 120ms ease, filter 120ms ease; box-shadow: 0 12px 28px rgba(79, 70, 229, 0.25); }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; box-shadow: none; filter: grayscale(0.4); }
    .btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 16px 32px rgba(79, 70, 229, 0.28); }
    .ghost { background: #fff; color: var(--ink); border: 1px solid var(--border); box-shadow: none; }
    .danger { color: #b91c1c; border-color: #fecaca; background: #fff1f2; }

    .stack { display: grid; gap: 10px; }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

    .lobby-list { display: grid; gap: 10px; }
    .lobby-row { padding: 12px; border: 1px solid var(--border); border-radius: 12px; display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; background: #f8fafc; }
    .lobby-meta { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

    .player-list { display: grid; gap: 10px; margin-top: 10px; }
    .player { padding: 12px; border: 1px solid var(--border); border-radius: 12px; display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; background: #f8fafc; }
    .badge { padding: 4px 8px; border-radius: 999px; font-weight: 800; background: rgba(99,102,241,0.12); color: #4f46e5; }
    .subtext { color: var(--muted); font-size: 13px; margin-top: 2px; }

    .flash { padding: 12px; border-radius: 12px; border: 1px solid var(--border); background: #f8fafc; font-weight: 700; }
    .flash.success { border-color: #bbf7d0; background: #ecfdf3; color: #15803d; }
    .flash.error { border-color: #fecaca; background: #fff1f2; color: #b91c1c; }

    footer { text-align: center; color: var(--muted); margin-top: 16px; }
  </style>
</head>
<body>
  <header>
    <div class="brand">
      <div class="logo">TM</div>
      <div>
        <h1>TileMasterAI</h1>
        <p class="subtitle">Welcome to the new lobby flow: name → lobby → ready → tile draw → play.</p>
      </div>
    </div>
    <div class="status-board">
      <div class="pill" id="playerStatus">Loading player…</div>
      <div class="pill" id="lobbyStatus">No lobby selected</div>
    </div>
  </header>

  <main>
    <section id="welcomeCard" class="card">
      <div class="section-title">Welcome</div>
      <div class="grid">
        <div class="stack">
          <label for="playerName">Your name</label>
          <input id="playerName" name="playerName" placeholder="Player name" autocomplete="off" />
          <div class="actions">
            <button class="btn" id="createLobbyBtn">Create lobby</button>
            <button class="ghost" id="saveNameBtn">Save name</button>
          </div>
        </div>
        <div class="stack">
          <label for="joinCode">Join a lobby</label>
          <input id="joinCode" name="joinCode" placeholder="Enter lobby code" maxlength="8" />
          <div class="actions">
            <button class="btn" id="joinLobbyBtn">Join lobby</button>
            <button class="ghost" id="refreshBtn">Refresh list</button>
          </div>
        </div>
      </div>
      <div id="flash" class="flash" hidden></div>
    </section>

    <section class="card">
      <div class="section-title">Open lobbies</div>
      <div id="noLobbies" class="subtext">No open lobbies yet. Create one to get started.</div>
      <div id="lobbyList" class="lobby-list"></div>
    </section>

    <section id="activeLobbyCard" class="card" hidden>
      <div class="section-title">Lobby</div>
      <div class="actions" style="justify-content: space-between;">
        <div>
          <div id="activeLobbyTitle" style="font-weight: 800; font-size: 18px;">Not joined</div>
          <div class="subtext" id="activeLobbyMeta"></div>
        </div>
        <div class="actions">
          <button class="ghost" id="readyToggle">Ready up</button>
          <button class="ghost danger" id="leaveLobbyBtn">Leave lobby</button>
        </div>
      </div>
      <div id="startStatus" class="flash" style="margin-top: 12px;" hidden></div>
      <div class="player-list" id="lobbyPlayers"></div>
      <div id="turnOrder" class="subtext" hidden></div>
      <div class="actions" style="margin-top: 12px;">
        <button class="btn" id="goToGame" hidden>Go to board</button>
      </div>
    </section>
  </main>

  <footer>Lobby state is refreshed every 3 seconds while you are seated.</footer>

  <script>
    const API_BASE = '/api/lobbies.php';
    const POLL_INTERVAL = 3000;

    const playerNameInput = document.getElementById('playerName');
    const joinCodeInput = document.getElementById('joinCode');
    const flash = document.getElementById('flash');
    const lobbyList = document.getElementById('lobbyList');
    const noLobbies = document.getElementById('noLobbies');
    const playerStatus = document.getElementById('playerStatus');
    const lobbyStatus = document.getElementById('lobbyStatus');
    const activeLobbyCard = document.getElementById('activeLobbyCard');
    const activeLobbyTitle = document.getElementById('activeLobbyTitle');
    const activeLobbyMeta = document.getElementById('activeLobbyMeta');
    const lobbyPlayers = document.getElementById('lobbyPlayers');
    const readyToggle = document.getElementById('readyToggle');
    const leaveLobbyBtn = document.getElementById('leaveLobbyBtn');
    const startStatus = document.getElementById('startStatus');
    const turnOrder = document.getElementById('turnOrder');
    const goToGame = document.getElementById('goToGame');

    const createLobbyBtn = document.getElementById('createLobbyBtn');
    const joinLobbyBtn = document.getElementById('joinLobbyBtn');
    const refreshBtn = document.getElementById('refreshBtn');
    const saveNameBtn = document.getElementById('saveNameBtn');

    let player = null;
    let activeLobby = null;
    let activePlayers = [];
    let pollHandle = null;

    const setFlash = (text, tone = 'info') => {
      flash.hidden = !text;
      flash.textContent = text || '';
      flash.className = `flash ${tone === 'error' ? 'error' : tone === 'success' ? 'success' : ''}`;
    };

    const fetchJson = async (url, options = {}) => {
      const response = await fetch(url, { cache: 'no-store', ...options });
      const data = await response.json();
      if (!response.ok || data.success === false) {
        const message = data.message || 'Request failed.';
        throw new Error(message);
      }
      return data;
    };

    const toTurnOrder = (turnState) => {
      if (!turnState || !Array.isArray(turnState.sequence)) return null;
      return { sequence: turnState.sequence };
    };

    const renderLobbies = (lobbies = []) => {
      lobbyList.innerHTML = '';
      if (!lobbies.length) {
        noLobbies.hidden = false;
        return;
      }
      noLobbies.hidden = true;

      lobbies.forEach((lobby) => {
        const row = document.createElement('div');
        row.className = 'lobby-row';

        const info = document.createElement('div');
        info.className = 'lobby-meta';
        info.innerHTML = `<strong>${lobby.name || 'Lobby ' + lobby.code}</strong>`
          + `<span class="pill">${lobby.code}</span>`
          + `<span class="pill">${lobby.player_count} players</span>`
          + `<span class="pill ${lobby.status === 'in_game' ? 'danger' : ''}">${lobby.status}</span>`;

        const join = document.createElement('button');
        join.className = 'btn';
        join.textContent = lobby.status === 'in_game' ? 'In game' : 'Join';
        join.disabled = lobby.status === 'in_game';
        join.addEventListener('click', () => {
          joinCodeInput.value = lobby.code;
          joinLobby();
        });

        row.appendChild(info);
        row.appendChild(join);
        lobbyList.appendChild(row);
      });
    };

    const renderActiveLobby = (lobby, players = [], extras = {}) => {
      activeLobby = lobby ? { ...lobby } : null;
      activePlayers = players || [];
      if (!lobby) {
        activeLobbyCard.hidden = true;
        lobbyStatus.textContent = 'No lobby selected';
        return;
      }

      activeLobbyCard.hidden = false;
      activeLobbyTitle.textContent = `${lobby.name || 'Lobby'} (${lobby.code})`;
      activeLobbyMeta.textContent = `${players.length} player${players.length === 1 ? '' : 's'} · ${lobby.status}`;
      lobbyStatus.textContent = `In ${lobby.code}`;

      lobbyPlayers.innerHTML = '';
      activePlayers.forEach((p) => {
        const row = document.createElement('div');
        row.className = 'player';
        const name = document.createElement('div');
        name.innerHTML = `<strong>${p.name}</strong><div class="subtext">${p.is_host ? 'Host' : 'Player'}</div>`;
        const ready = document.createElement('span');
        ready.className = 'badge';
        ready.textContent = p.is_ready ? 'Ready' : 'Not ready';
        ready.style.background = p.is_ready ? '#ecfdf3' : '#fff1f2';
        ready.style.color = p.is_ready ? '#15803d' : '#b91c1c';
        row.appendChild(name);
        row.appendChild(ready);
        lobbyPlayers.appendChild(row);
      });

      const me = activePlayers.find((p) => p.id === (player?.id || null));
      const isReady = !!me?.is_ready;
      readyToggle.textContent = isReady ? 'Unready' : 'Ready up';

      const allReady = activePlayers.length >= 2 && activePlayers.every((p) => p.is_ready);
      startStatus.hidden = false;
      if (lobby.status === 'in_game') {
        startStatus.textContent = 'Game is live! Opening the board…';
        startStatus.className = 'flash success';
      } else if (allReady) {
        startStatus.textContent = 'Everyone is ready. Drawing tiles for turn order…';
        startStatus.className = 'flash success';
      } else {
        startStatus.textContent = 'Waiting for all players to ready up (need at least two players).';
        startStatus.className = 'flash';
      }

      turnOrder.hidden = true;
      turnOrder.textContent = '';
      if (extras.turn_order) {
        const orderNames = extras.turn_order.sequence
          .map((id) => activePlayers.find((p) => p.id === id)?.name)
          .filter(Boolean);
        turnOrder.hidden = orderNames.length === 0;
        if (orderNames.length) {
          turnOrder.textContent = `Turn order: ${orderNames.join(' → ')}`;
        }
      } else {
        turnOrder.hidden = true;
      }

      if (lobby.status === 'in_game') {
        goToGame.hidden = false;
        goToGame.onclick = () => window.location.href = `/game.php?code=${encodeURIComponent(lobby.code)}`;
      } else {
        goToGame.hidden = true;
      }
    };

    const stopPolling = () => {
      if (pollHandle) {
        clearInterval(pollHandle);
        pollHandle = null;
      }
    };

    const pollLobby = () => {
      stopPolling();
      if (!activeLobby) return;
      pollHandle = setInterval(async () => {
        try {
          const data = await fetchJson(`${API_BASE}?action=detail&code=${encodeURIComponent(activeLobby.code)}`);
          if (!data.lobby) return;
          const extras = { turn_state: data.turn_state, turn_order: toTurnOrder(data.turn_state) };
          renderActiveLobby(data.lobby, data.players || [], extras);
          if (data.lobby.status === 'in_game') {
            stopPolling();
            goToGame.hidden = false;
            goToGame.onclick = () => window.location.href = `/game.php?code=${encodeURIComponent(data.lobby.code)}`;
          }
        } catch (error) {
          console.error(error);
        }
      }, POLL_INTERVAL);
    };

    const bootstrap = async () => {
      try {
        const data = await fetchJson(`${API_BASE}?action=bootstrap`);
        player = data.player;
        playerStatus.textContent = `You are ${player.name}`;
        if (playerNameInput && !playerNameInput.value) {
          playerNameInput.value = player.name || '';
        }
        renderLobbies(data.lobbies || []);

        if (data.activeLobby) {
          const extras = { turn_state: data.activeLobby.turn_state, turn_order: toTurnOrder(data.activeLobby.turn_state) };
          renderActiveLobby(data.activeLobby, data.activeLobby.players || [], extras);
          pollLobby();
          if ((data.activeLobby.status || '') === 'in_game') {
            goToGame.hidden = false;
            goToGame.onclick = () => window.location.href = `/game.php?code=${encodeURIComponent(data.activeLobby.code)}`;
          }
        }
      } catch (error) {
        setFlash(error.message, 'error');
      }
    };

    const createLobby = async () => {
      const name = (playerNameInput.value || '').trim();
      if (!name) {
        setFlash('Enter your name first.', 'error');
        return;
      }

      try {
        const data = await fetchJson(API_BASE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'create', playerName: name }),
        });
        player = data.player || player;
        renderActiveLobby(data.lobby, data.players || [], { turn_state: data.turn_state });
        setFlash(`Created lobby ${data.lobby.code}.`, 'success');
        renderLobbies([]);
        pollLobby();
      } catch (error) {
        setFlash(error.message, 'error');
      }
    };

    const joinLobby = async () => {
      const code = (joinCodeInput.value || '').trim().toUpperCase();
      const name = (playerNameInput.value || '').trim();
      if (!code || !name) {
        setFlash('Enter both your name and a lobby code to join.', 'error');
        return;
      }

      try {
        const data = await fetchJson(API_BASE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'join', code, playerName: name }),
        });
        player = data.player || player;
        renderActiveLobby(data.lobby, data.players || [], { turn_state: data.turn_state });
        setFlash(`Joined lobby ${code}.`, 'success');
        pollLobby();
      } catch (error) {
        setFlash(error.message, 'error');
      }
    };

    const leaveLobby = async () => {
      if (!activeLobby) return;
      try {
        const data = await fetchJson(API_BASE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'leave', code: activeLobby.code }),
        });
        setFlash('Left lobby.', 'success');
        activeLobby = null;
        activePlayers = [];
        renderActiveLobby(null, []);
        stopPolling();
        bootstrap();
      } catch (error) {
        setFlash(error.message, 'error');
      }
    };

    const toggleReady = async () => {
      if (!activeLobby) return;
      const me = activePlayers.find((p) => p.id === player?.id);
      const desired = !(me?.is_ready);
      try {
        const data = await fetchJson(API_BASE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'toggle_ready', code: activeLobby.code, ready: desired, playerName: playerNameInput.value || player?.name }),
        });
        const extras = { turn_order: data.turn_order, turn_state: data.turn_state };
        renderActiveLobby(data.lobby, data.players || [], extras);
        if (data.lobby.status === 'in_game') {
          goToGame.hidden = false;
          goToGame.onclick = () => window.location.href = `/game.php?code=${encodeURIComponent(data.lobby.code)}`;
        }
      } catch (error) {
        setFlash(error.message, 'error');
      }
    };

    const saveName = async () => {
      const name = (playerNameInput.value || '').trim();
      if (!name) {
        setFlash('Name cannot be empty.', 'error');
        return;
      }
      try {
        const data = await fetchJson(API_BASE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'set_name', playerName: name }),
        });
        player = data.player;
        playerStatus.textContent = `You are ${player.name}`;
        setFlash('Name saved.', 'success');
      } catch (error) {
        setFlash(error.message, 'error');
      }
    };

    createLobbyBtn?.addEventListener('click', createLobby);
    joinLobbyBtn?.addEventListener('click', joinLobby);
    leaveLobbyBtn?.addEventListener('click', leaveLobby);
    readyToggle?.addEventListener('click', toggleReady);
    refreshBtn?.addEventListener('click', bootstrap);
    saveNameBtn?.addEventListener('click', saveName);

    bootstrap();
  </script>
</body>
</html>
