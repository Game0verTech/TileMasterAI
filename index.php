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
      background:
        radial-gradient(circle at 12% 24%, rgba(99, 102, 241, 0.08), transparent 26%),
        radial-gradient(circle at 82% 10%, rgba(14, 165, 233, 0.12), transparent 26%),
        linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
      min-height: 100vh;
      color: var(--ink);
      padding: 28px 18px 48px;
    }

    header {
      max-width: 1180px;
      margin: 0 auto 20px;
      display: grid;
      gap: 14px;
      grid-template-columns: 1fr auto;
      align-items: center;
    }

    .brand {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .logo {
      width: 56px;
      height: 56px;
      border-radius: 16px;
      background: linear-gradient(135deg, #4f46e5, #22d3ee);
      box-shadow: 0 16px 40px rgba(79, 70, 229, 0.22);
      display: grid;
      place-items: center;
      color: #fff;
      font-weight: 800;
      font-size: 22px;
      letter-spacing: 0.02em;
    }

    h1 { margin: 0; font-size: clamp(26px, 4vw, 34px); }
    p.subtitle { margin: 4px 0 0; color: var(--muted); }

    .status-board {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .pill {
      padding: 9px 12px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: rgba(255, 255, 255, 0.88);
      color: var(--muted);
      font-weight: 700;
      letter-spacing: 0.02em;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .pill.primary { background: linear-gradient(135deg, #6366f1, #22d3ee); color: #fff; border-color: transparent; box-shadow: 0 12px 28px rgba(79, 70, 229, 0.25); }
    .pill.soft { background: #eef2ff; color: #3730a3; }
    .pill.success { background: #ecfdf3; color: #166534; border-color: #bbf7d0; box-shadow: none; }
    .pill.warning { background: #fffbeb; color: #92400e; border-color: #fef3c7; box-shadow: none; }
    .pill.danger { background: #fff1f2; color: #b91c1c; border-color: #fecaca; box-shadow: none; }

    main { max-width: 1180px; margin: 0 auto; display: grid; gap: 18px; }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 18px;
      box-shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
    }

    .section-title {
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 0 0 6px;
      font-size: 14px;
      font-weight: 800;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }

    .panel {
      border: 1px dashed var(--border);
      border-radius: 14px;
      padding: 14px;
      background: #f8fafc;
    }

    .panel.highlight {
      background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(34, 211, 238, 0.08));
      border-color: rgba(99, 102, 241, 0.25);
    }

    form { display: grid; gap: 10px; }
    label { font-weight: 700; color: var(--muted); font-size: 14px; }

    input, button {
      font: inherit;
      border-radius: 12px;
      border: 1px solid var(--border);
      padding: 12px;
    }

    input { background: #f8fafc; }

    .btn {
      background: linear-gradient(135deg, #6366f1, #22d3ee);
      color: #fff;
      font-weight: 800;
      cursor: pointer;
      border: none;
      transition: transform 120ms ease, box-shadow 120ms ease, filter 120ms ease;
      box-shadow: 0 12px 28px rgba(79, 70, 229, 0.25);
    }

    .btn:disabled { opacity: 0.6; cursor: not-allowed; box-shadow: none; filter: grayscale(0.4); }
    .btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 16px 32px rgba(79, 70, 229, 0.28); }

    .ghost { background: #fff; color: var(--ink); border: 1px solid var(--border); box-shadow: none; }
    .danger { color: #b91c1c; border-color: #fecaca; background: #fff1f2; }
    .quiet { background: #e2e8f0; color: #0f172a; border-color: transparent; box-shadow: none; }

    .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .stack { display: grid; gap: 10px; }

    .lobby-list { display: grid; gap: 10px; }
    .lobby-row { padding: 12px; border: 1px solid var(--border); border-radius: 12px; display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; background: #f8fafc; }
    .lobby-meta { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

    .roster { display: grid; gap: 10px; margin-top: 12px; }
    .player { padding: 12px; border: 1px solid var(--border); border-radius: 12px; display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; background: #f8fafc; }
    .badge { padding: 4px 8px; border-radius: 999px; font-weight: 800; background: rgba(99,102,241,0.12); color: #4f46e5; }
    .subtext { color: var(--muted); font-size: 13px; margin-top: 2px; }

    .progress-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .step { padding: 12px; border: 1px solid var(--border); border-radius: 12px; background: #f8fafc; display: grid; gap: 6px; }
    .step .label { font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); font-size: 12px; }
    .step.active { border-color: #c7d2fe; background: #eef2ff; }
    .step.complete { border-color: #bbf7d0; background: #ecfdf3; }

    .waiting-banner {
      margin-top: 10px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px dashed var(--border);
      background: linear-gradient(120deg, rgba(99, 102, 241, 0.12), rgba(34, 211, 238, 0.08));
      color: var(--ink);
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      animation: waitPulse 1.6s ease-in-out infinite;
    }

    .waiting-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: var(--accent);
      box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.5);
      animation: dotBeat 1.4s ease-in-out infinite;
    }

    @keyframes waitPulse {
      0% { transform: translateY(0); }
      50% { transform: translateY(-2px); }
      100% { transform: translateY(0); }
    }

    @keyframes dotBeat {
      0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4); }
      50% { transform: scale(1.05); box-shadow: 0 0 0 8px rgba(99, 102, 241, 0); }
      100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); }
    }

    .log { background: #0f172a; color: #e2e8f0; border-radius: 14px; padding: 12px; min-height: 180px; display: grid; gap: 6px; font-family: "SFMono", ui-monospace, monospace; }
    .log .entry { padding: 8px 10px; background: rgba(255,255,255,0.05); border-radius: 10px; display: flex; gap: 8px; align-items: center; }
    .log .entry strong { color: #a5b4fc; }

    .tile-pop { position: fixed; inset: 0; pointer-events: none; display: grid; place-items: center; }
    .tile { width: 96px; height: 96px; background: #fef3c7; border: 6px solid #f59e0b; border-radius: 18px; display: grid; place-items: center; font-size: 48px; font-weight: 900; box-shadow: 0 26px 60px rgba(0,0,0,0.24); transform: translateY(30px) scale(0.8); opacity: 0; }
    .tile.show { animation: pop 520ms ease forwards; }
    @keyframes pop { 0% { opacity: 0; transform: translateY(30px) scale(0.8); } 40% { opacity: 1; transform: translateY(-8px) scale(1.05); } 100% { opacity: 1; transform: translateY(0) scale(1); } }

    .flash { padding: 12px; border-radius: 12px; border: 1px solid var(--border); background: #f8fafc; font-weight: 700; }
    .flash.success { border-color: #bbf7d0; background: #ecfdf3; color: #15803d; }
    .flash.error { border-color: #fecaca; background: #fff1f2; color: #b91c1c; }

    .meta-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .meta { padding: 12px; border-radius: 12px; border: 1px dashed var(--border); background: #f8fafc; }

    footer { text-align: center; color: var(--muted); margin-top: 16px; }
  </style>
</head>
<body data-page="lobby">
  <header>
    <div class="brand">
      <div class="logo">TM</div>
      <div>
        <h1>TileMasterAI Lobby</h1>
        <p class="subtitle">Guided flow to get everyone seated and into the game fast.</p>
      </div>
    </div>
    <div class="status-board">
      <div class="pill primary" id="connectionState">Connecting…</div>
      <div class="pill" id="sessionStatus">Waiting to join</div>
      <div class="pill soft" id="lastSynced">Syncing…</div>
    </div>
  </header>

  <main>
    <section class="card">
      <p class="section-title">Get started</p>
      <div class="grid">
        <div class="panel highlight stack">
          <div>
            <h2 style="margin:0 0 6px;">Host a new lobby</h2>
            <p class="subtext">Pick a short code and your display name. We'll share status with everyone instantly.</p>
          </div>
          <form id="createSessionForm" aria-label="Create lobby">
            <label for="sessionCode">Lobby code</label>
            <input id="sessionCode" name="sessionCode" placeholder="ABCD" maxlength="8" required />
            <label for="playerName">Your name</label>
            <input id="playerName" name="playerName" placeholder="Maven" maxlength="20" required />
            <div class="actions">
              <button class="btn" type="submit">Create lobby</button>
              <button class="btn ghost" type="button" id="joinSessionBtn">Join existing</button>
              <button class="btn quiet" type="button" id="resumeSessionBtn">Resume saved seat</button>
            </div>
          </form>
          <div class="meta-grid">
            <div class="meta">✅ We'll remember your device so reconnecting is painless.</div>
            <div class="meta">ℹ️ Host draws tiles to set turn order once everyone is ready.</div>
          </div>
          <div id="sessionFlash" class="flash" hidden></div>
          <div id="stuckSession" class="actions" hidden>
            <span class="pill warning">Looks like you're stuck in another lobby.</span>
            <button class="btn ghost danger" type="button" id="forceLeaveBtn">Leave previous lobby</button>
          </div>
        </div>

        <div class="panel stack">
          <div>
            <h3 style="margin:0 0 6px;">Open lobbies</h3>
            <p class="subtext">Tap a card to prefill the code or join directly.</p>
          </div>
          <div class="actions" style="justify-content: space-between;">
            <div class="pill soft" id="liveLobbyHint">Live sync on</div>
            <button class="btn ghost" type="button" id="refreshSessionsBtn">Refresh</button>
          </div>
          <div id="sessionEmpty">No open lobbies yet.</div>
          <div id="sessionList" role="list" class="lobby-list"></div>
        </div>
      </div>
    </section>

    <section class="card" id="lobbyCard" hidden>
      <p class="section-title">Your lobby</p>
      <div class="actions" style="justify-content: space-between; align-items: flex-start; gap: 12px;">
        <div class="stack" style="min-width: 240px;">
          <div class="actions" style="gap: 8px;">
            <h2 id="lobbyTitle" style="margin:0;">Session</h2>
            <span class="pill" id="lobbyStatus">Waiting</span>
            <span class="pill soft" id="lobbyCapacity"></span>
          </div>
          <div class="actions" style="gap:8px; flex-wrap: wrap;">
            <span class="pill soft" id="shareCode">Invite code: —</span>
            <button class="btn ghost" id="copyCodeBtn" type="button">Copy invite</button>
          </div>
        </div>
        <div class="actions" style="gap: 8px;">
          <button class="btn" id="startGameBtn" disabled aria-disabled="true">Start game</button>
          <button class="btn ghost" id="drawTurnOrderBtn" disabled aria-disabled="true">Draw turn order</button>
          <button class="btn ghost danger" id="leaveSessionBtn">Leave</button>
          <button class="btn ghost danger" id="deleteSessionBtn">Delete</button>
        </div>
      </div>

      <div class="progress-grid" id="lobbySteps">
        <div class="step" data-step="seat">
          <span class="label">Step 1</span>
          <strong>Seat everyone</strong>
          <p class="subtext">Players join with the invite code. Live roster updates keep you in sync.</p>
        </div>
        <div class="step" data-step="start">
          <span class="label">Step 2</span>
          <strong>Host starts</strong>
          <p class="subtext">Host enables turn order once at least two players are present.</p>
        </div>
        <div class="step" data-step="draw">
          <span class="label">Step 3</span>
          <strong>Draw tiles</strong>
          <p class="subtext">Everyone watches the live draw to decide who goes first.</p>
        </div>
        <div class="step" data-step="play">
          <span class="label">Step 4</span>
          <strong>Jump into the game</strong>
          <p class="subtext">We auto-open the board once turn order locks in.</p>
        </div>
      </div>

      <div id="waitingForHost" class="waiting-banner" hidden>
        <span class="waiting-dot" aria-hidden="true"></span>
        <span class="waiting-text"></span>
      </div>

      <div class="grid">
        <div class="stack">
          <h3 style="margin:10px 0 4px;">Roster</h3>
          <div class="roster" id="lobbyRoster"></div>
        </div>
        <div class="stack">
          <div class="card" style="margin:0; background:#0f172a; color:#e2e8f0;">
            <p class="section-title" style="color:#cbd5e1;">Turn order</p>
            <div class="log" id="turnOrderLog"></div>
            <p id="turnOrderResult"></p>
          </div>
        </div>
      </div>
    </section>
  </main>

  <dialog id="joinModal">
    <div class="card" style="max-width:420px; padding:22px;">
      <p class="section-title">Join lobby</p>
      <h3 id="joinModalTitle" style="margin:0 0 8px;">Enter your name</h3>
      <form id="joinModalForm" style="display:grid; gap:10px;">
        <input type="text" id="joinModalName" placeholder="Your name" maxlength="20" required />
        <div style="display:flex; gap:10px; justify-content:flex-end;">
          <button type="button" class="btn ghost" id="joinModalCancel">Cancel</button>
          <button type="submit" class="btn" id="joinModalConfirm">Join</button>
        </div>
      </form>
    </div>
  </dialog>

  <div class="tile-pop" aria-hidden="true">
    <div class="tile" id="tilePop"></div>
  </div>

  <footer>Live sync keeps every player on the same page. Host draws tiles to decide who goes first.</footer>

  <script>
    const lobbyWsUrl = <?php
      $explicitWsUrl = getenv('LOBBY_WS_URL') ?: '';
      $wsPort = getenv('LOBBY_WS_PORT') ?: '8090';
      $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
      $computed = $protocol . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ':' . $wsPort;
      echo json_encode($explicitWsUrl ?: $computed);
    ?>;

    document.addEventListener('DOMContentLoaded', () => {
      const createSessionForm = document.getElementById('createSessionForm');
      const sessionCodeInput = document.getElementById('sessionCode');
      const playerNameInput = document.getElementById('playerName');
      const sessionFlash = document.getElementById('sessionFlash');
      const sessionList = document.getElementById('sessionList');
      const sessionEmpty = document.getElementById('sessionEmpty');
      const stuckSession = document.getElementById('stuckSession');
      const forceLeaveBtn = document.getElementById('forceLeaveBtn');
      const lobbyCard = document.getElementById('lobbyCard');
      const lobbyTitle = document.getElementById('lobbyTitle');
      const lobbyStatus = document.getElementById('lobbyStatus');
      const lobbyCapacity = document.getElementById('lobbyCapacity');
      const lobbyRoster = document.getElementById('lobbyRoster');
      const startGameBtn = document.getElementById('startGameBtn');
      const leaveSessionBtn = document.getElementById('leaveSessionBtn');
      const deleteSessionBtn = document.getElementById('deleteSessionBtn');
      const drawTurnOrderBtn = document.getElementById('drawTurnOrderBtn');
      const turnOrderLog = document.getElementById('turnOrderLog');
      const turnOrderResult = document.getElementById('turnOrderResult');
      const sessionStatus = document.getElementById('sessionStatus');
      const tilePop = document.getElementById('tilePop');
      const waitingForHost = document.getElementById('waitingForHost');
      const joinSessionBtn = document.getElementById('joinSessionBtn');
      const joinModal = document.getElementById('joinModal');
      const joinModalForm = document.getElementById('joinModalForm');
      const joinModalName = document.getElementById('joinModalName');
      const joinModalTitle = document.getElementById('joinModalTitle');
      const joinModalCancel = document.getElementById('joinModalCancel');
      const connectionState = document.getElementById('connectionState');
      const lastSynced = document.getElementById('lastSynced');
      const lobbySteps = document.getElementById('lobbySteps');
      const refreshSessionsBtn = document.getElementById('refreshSessionsBtn');
      const resumeSessionBtn = document.getElementById('resumeSessionBtn');
      const shareCode = document.getElementById('shareCode');
      const copyCodeBtn = document.getElementById('copyCodeBtn');
      const liveLobbyHint = document.getElementById('liveLobbyHint');

      const SESSION_STORAGE_KEY = 'tilemaster.session';
      const IDENTITY_STORAGE_KEY = 'tilemaster.identity';
      const MAX_PLAYERS = 4;
      const SYNC_INTERVAL = 6000;

      let lobbySocket = null;
      let lobbyConnected = false;
      let activeSession = null;
      let currentPlayer = null;
      let currentPlayers = [];
      let turnOrderInFlight = false;
      let pendingJoinCode = null;
      let countdownTimer = null;
      let lastConflictSession = null;
      let pendingLobbyRefresh = false;
      let lastSyncedAt = null;
      let pollTimer = null;

      const setConnectionState = (state, text) => {
        if (!connectionState) return;
        connectionState.textContent = text;
        connectionState.className = 'pill primary';
        if (state === 'connected') connectionState.className = 'pill success';
        if (state === 'reconnecting') connectionState.className = 'pill warning';
        if (state === 'offline') connectionState.className = 'pill danger';
      };

      const markSynced = () => {
        lastSyncedAt = new Date();
        if (lastSynced) {
          lastSynced.textContent = `Synced just now`;
        }
      };

      const updateSyncAge = () => {
        if (!lastSyncedAt || !lastSynced) return;
        const diff = Math.round((Date.now() - lastSyncedAt.getTime()) / 1000);
        if (diff < 5) {
          lastSynced.textContent = 'Synced just now';
        } else if (diff < 60) {
          lastSynced.textContent = `Synced ${diff}s ago`;
        } else {
          const mins = Math.floor(diff / 60);
          lastSynced.textContent = `Synced ${mins}m ago`;
        }
      };

      setInterval(updateSyncAge, 5000);

      const setFlash = (text, tone = 'info') => {
        if (!sessionFlash) return;
        sessionFlash.hidden = !text;
        sessionFlash.textContent = text;
        sessionFlash.className = `flash ${tone === 'error' ? 'error' : tone === 'success' ? 'success' : ''}`;
      };

      const setStuckSession = (session) => {
        lastConflictSession = session || null;
        if (!stuckSession || !forceLeaveBtn) return;
        if (!session) {
          stuckSession.hidden = true;
          return;
        }
        stuckSession.hidden = false;
        forceLeaveBtn.textContent = session.code
          ? `Leave lobby ${session.code}`
          : 'Leave previous lobby';
      };

      const requestLobbyRefresh = () => {
        if (lobbySocket?.readyState === WebSocket.OPEN) {
          lobbySocket.send(JSON.stringify({ type: 'lobbies.refresh' }));
          pendingLobbyRefresh = false;
          return;
        }
        pendingLobbyRefresh = true;
        fetchSessions();
      };

      const persistIdentity = (playerName, clientToken) => {
        if (!clientToken) return;
        localStorage.setItem(IDENTITY_STORAGE_KEY, JSON.stringify({ playerName, clientToken }));
      };

      const persistSession = (session, player) => {
        if (!session?.code || !player?.id) return;
        localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify({
          sessionCode: session.code,
          sessionId: session.id,
          playerId: player.id,
          playerName: player.name,
          clientToken: player.client_token,
        }));
        persistIdentity(player.name, player.client_token);
      };

      const loadSavedSession = () => {
        try {
          const raw = localStorage.getItem(SESSION_STORAGE_KEY);
          return raw ? JSON.parse(raw) : null;
        } catch (error) {
          return null;
        }
      };

      const loadIdentity = () => {
        try {
          const raw = localStorage.getItem(IDENTITY_STORAGE_KEY);
          return raw ? JSON.parse(raw) : null;
        } catch (error) {
          return null;
        }
      };

      const updateSteps = (status, players = []) => {
        if (!lobbySteps) return;
        const stepNodes = lobbySteps.querySelectorAll('.step');
        stepNodes.forEach((step) => {
          const key = step.dataset.step;
          step.classList.remove('active', 'complete');
          if (key === 'seat') {
            if (players.length >= 2) step.classList.add('complete'); else step.classList.add('active');
          }
          if (key === 'start') {
            if (status === 'started') step.classList.add('complete');
            else if (players.length >= 2) step.classList.add('active');
          }
          if (key === 'draw') {
            if (status === 'started' && turnOrderResult?.textContent) step.classList.add('complete');
            else if (status === 'started') step.classList.add('active');
          }
          if (key === 'play') {
            if (turnOrderResult?.textContent) step.classList.add('active');
          }
        });
      };

      const updateSessionList = (sessions = []) => {
        sessionList.innerHTML = '';
        if (!sessions.length) {
          sessionEmpty.hidden = false;
          return;
        }
        sessionEmpty.hidden = true;
        sessions.forEach((session) => {
          const row = document.createElement('div');
          row.className = 'lobby-row';
          const meta = document.createElement('div');
          meta.className = 'lobby-meta';
          meta.innerHTML = `<strong>${session.code}</strong><span class="pill soft">${session.player_count}/${MAX_PLAYERS} seated</span><span class="pill soft">${session.status}</span>`;
          const join = document.createElement('div');
          join.className = 'actions';
          const joinBtn = document.createElement('button');
          joinBtn.className = 'btn ghost';
          joinBtn.type = 'button';
          joinBtn.textContent = 'Join';
          joinBtn.addEventListener('click', () => openJoinModal(session.code));
          join.appendChild(joinBtn);
          row.appendChild(meta);
          row.appendChild(join);
          row.addEventListener('click', () => {
            sessionCodeInput.value = session.code;
            setFlash(`Joining ${session.code}? Enter your name.`, 'info');
            playerNameInput.focus();
          });
          sessionList.appendChild(row);
        });
        markSynced();
      };

      const parseSessionDetail = (detail) => {
        if (!detail) return null;
        if (typeof detail === 'object') return detail;
        if (typeof detail === 'string') {
          try {
            return JSON.parse(detail);
          } catch (error) {
            return null;
          }
        }
        return null;
      };

      const renderRoster = ({ sessionCode, players = [], status, maxPlayers, canStart }) => {
        if (!lobbyCard || !lobbyRoster) return;
        currentPlayers = players;
        lobbyCard.hidden = false;
        lobbyTitle.textContent = `Session ${sessionCode}`;
        lobbyStatus.textContent = status;
        lobbyCapacity.textContent = `${players.length}/${maxPlayers} players`;
        shareCode.textContent = `Invite code: ${sessionCode}`;
        lobbyRoster.innerHTML = '';
        if (activeSession) {
          activeSession.status = status;
          activeSession.max_players = maxPlayers;
        }
        players.forEach((player, idx) => {
          const row = document.createElement('div');
          row.className = 'player';
          row.innerHTML = `<div class="badge">${idx + 1}</div><div><strong>${player.name}</strong><div class="subtext">${player.is_host ? 'Host' : 'Guest'}</div></div>`;
          if (player.is_host) {
            const host = document.createElement('span');
            host.className = 'badge';
            host.textContent = 'Host';
            row.appendChild(host);
          }
          lobbyRoster.appendChild(row);
        });

        const hostPlayer = players.find((player) => player.is_host);
        const isHost = Boolean(currentPlayer?.is_host || (hostPlayer && currentPlayer?.id === hostPlayer.id));
        const readyToStart = (typeof canStart === 'boolean' ? canStart : players.length >= 2) && status !== 'started';
        const readyToDraw = players.length >= 2 && status === 'started';
        startGameBtn.disabled = !(isHost && readyToStart);
        startGameBtn.setAttribute('aria-disabled', startGameBtn.disabled ? 'true' : 'false');
        drawTurnOrderBtn.disabled = !(readyToDraw && !turnOrderInFlight);
        drawTurnOrderBtn.setAttribute('aria-disabled', drawTurnOrderBtn.disabled ? 'true' : 'false');
        sessionStatus.textContent = `In ${sessionCode} • ${players.length}/${maxPlayers}`;

        const hostName = players.find((player) => player.is_host)?.name || 'the host';
        if (waitingForHost) {
          const textEl = waitingForHost.querySelector('.waiting-text');
          if (status === 'started') {
            waitingForHost.hidden = true;
          } else {
            waitingForHost.hidden = false;
            if (textEl) {
              textEl.textContent = `Waiting for ${hostName} to start the game...`;
            }
          }
        }
        updateSteps(status, players);
      };

      const logTurn = (text) => {
        if (!turnOrderLog) return;
        const entry = document.createElement('div');
        entry.className = 'entry';
        entry.textContent = text;
        turnOrderLog.appendChild(entry);
        turnOrderLog.scrollTop = turnOrderLog.scrollHeight;
      };

      const showTilePop = (letter, playerName) => {
        if (!tilePop) return;
        tilePop.textContent = letter;
        tilePop.classList.remove('show');
        void tilePop.offsetWidth;
        tilePop.classList.add('show');
        if (playerName) {
          logTurn(`${playerName} drew ${letter}`);
        }
      };

      const resetTurnOrderUi = () => {
        turnOrderLog.innerHTML = '';
        logTurn('Waiting to draw…');
        turnOrderResult.textContent = '';
        updateSteps(activeSession?.status || 'active', currentPlayers);
      };

      const stopCountdown = () => {
        if (countdownTimer) {
          clearInterval(countdownTimer);
          countdownTimer = null;
        }
      };

      const setActiveSession = (session, player) => {
        activeSession = session;
        currentPlayer = player;
        setStuckSession(null);
        persistSession(session, player);
        renderRoster({ sessionCode: session.code, players: [], status: session.status, maxPlayers: session.max_players || MAX_PLAYERS });
        if (lobbySocket?.readyState === WebSocket.OPEN) {
          lobbySocket.send(JSON.stringify({ type: 'subscribe', sessionCode: session.code }));
          lobbySocket.send(JSON.stringify({ type: 'refresh', sessionCode: session.code }));
        }
      };

      const fetchSessions = async () => {
        try {
          const response = await fetch('/api/sessions.php');
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load sessions');
          const sessions = data.sessions || [];
          updateSessionList(sessions);
          markSynced();
        } catch (error) {
          sessionEmpty.hidden = false;
          setConnectionState('offline', 'Sync paused');
        }
      };

      const refreshActiveSession = async () => {
        if (!activeSession?.code) return;
        try {
          const response = await fetch(`/api/session_players.php?code=${encodeURIComponent(activeSession.code)}`);
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'Unable to sync lobby');
          renderRoster({
            sessionCode: data.session.code,
            status: data.session.status,
            players: data.players,
            maxPlayers: data.session.max_players,
            canStart: data.players.length >= 2,
          });
          markSynced();
        } catch (error) {
          setFlash(error.message, 'error');
        }
      };

      const startPolling = () => {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(() => {
          fetchSessions();
          refreshActiveSession();
        }, SYNC_INTERVAL);
      };

      const openLobbySocket = () => {
        if (lobbySocket && lobbyConnected) return lobbySocket;
        setConnectionState('reconnecting', 'Connecting…');
        lobbySocket = new WebSocket(lobbyWsUrl);
        lobbySocket.addEventListener('open', () => {
          lobbyConnected = true;
          setConnectionState('connected', 'Live sync on');
          lobbySocket.send(JSON.stringify({ type: 'lobbies.subscribe' }));
          if (pendingLobbyRefresh) {
            lobbySocket.send(JSON.stringify({ type: 'lobbies.refresh' }));
            pendingLobbyRefresh = false;
          }
          if (activeSession?.code) {
            lobbySocket.send(JSON.stringify({ type: 'subscribe', sessionCode: activeSession.code }));
            lobbySocket.send(JSON.stringify({ type: 'refresh', sessionCode: activeSession.code }));
          }
        });

        lobbySocket.addEventListener('close', () => {
          lobbyConnected = false;
          setConnectionState('reconnecting', 'Reconnecting…');
          setTimeout(openLobbySocket, 1000);
        });

        lobbySocket.addEventListener('message', (event) => {
          const payload = JSON.parse(event.data || '{}');
          if (payload.type === 'session.roster') {
            renderRoster({
              sessionCode: payload.sessionCode,
              players: payload.players,
              status: payload.status,
              maxPlayers: payload.maxPlayers,
            });
            markSynced();
          }

          if (payload.type === 'lobbies.list') {
            updateSessionList(payload.sessions || []);
            markSynced();
          }

          if (payload.type === 'session.started') {
            lobbyStatus.textContent = 'started';
            if (activeSession) {
              activeSession.status = 'started';
            }
            if (activeSession?.code) {
              renderRoster({
                sessionCode: activeSession.code,
                players: currentPlayers,
                status: 'started',
                maxPlayers: activeSession.max_players || MAX_PLAYERS,
              });
            }
            markSynced();
          }

          if (payload.type === 'turnorder.drawn') {
            showTilePop(payload.tile?.letter || '?', payload.player?.name || 'Player');
            markSynced();
          }

          if (payload.type === 'turnorder.tie') {
            logTurn(`Tie on letter ${String.fromCharCode(65 + (payload.distance || 0))} — redraw for ${payload.players.map((p) => p.name).join(', ')}`);
            markSynced();
          }

          if (payload.type === 'turnorder.resolved') {
            const order = payload.order || [];
            if (order.length) {
              const lead = order[0];
              const countdownSeconds = 4;
              turnOrderResult.innerHTML = `<strong>${lead.player.name}</strong> will start. Then ${order.slice(1).map((o) => o.player.name).join(', ')}`;
              setFlash(`Turn order set! Launching game in ${countdownSeconds}s`, 'success');
              let remaining = countdownSeconds;
              stopCountdown();
              countdownTimer = setInterval(() => {
                remaining -= 1;
                setFlash(`Turn order set! Launching game in ${remaining}s`, 'success');
                if (remaining <= 0) {
                  stopCountdown();
                  window.location.href = `/game.php?code=${encodeURIComponent(payload.sessionCode)}`;
                }
              }, 1000);
            }
            turnOrderInFlight = false;
            drawTurnOrderBtn.disabled = false;
            markSynced();
          }

          if (payload.type === 'turnorder.error') {
            setFlash(payload.message || 'Unable to draw turn order', 'error');
            turnOrderInFlight = false;
            drawTurnOrderBtn.disabled = false;
          }
        });
        return lobbySocket;
      };

      const handleCreateSession = async (event) => {
        event.preventDefault();
        const code = (sessionCodeInput.value || '').toUpperCase();
        const playerName = (playerNameInput.value || '').trim();
        resolveIdentity();
        if (!code || !playerName) {
          setFlash('Add a code and your name.', 'error');
          return;
        }
        try {
          const response = await fetch('/api/sessions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code, playerName }),
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            setStuckSession(parseSessionDetail(data.detail));
            throw new Error(data.message || 'Unable to create session');
          }
          setFlash('Lobby created.', 'success');
          setActiveSession(data.session, data.player);
          renderRoster({
            sessionCode: data.session.code,
            status: data.session.status,
            players: [data.player],
            maxPlayers: data.session.max_players,
          });
          requestLobbyRefresh();
          openLobbySocket();
          markSynced();
        } catch (error) {
          setFlash(error.message, 'error');
        }
      };

      const handleJoinSession = async () => {
        const code = (pendingJoinCode || sessionCodeInput.value || '').toUpperCase();
        const playerName = (joinModalName?.value || playerNameInput.value || '').trim();
        const storedIdentity = loadIdentity();
        const clientToken = storedIdentity?.clientToken || undefined;
        resolveIdentity();
        if (!code || !playerName) {
          setFlash('Enter code and name to join.', 'error');
          return;
        }
        playerNameInput.value = playerName;
        sessionCodeInput.value = code;
        try {
          const response = await fetch('/api/session_players.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'join', code, playerName, clientToken }),
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            setStuckSession(parseSessionDetail(data.detail));
            throw new Error(data.message || 'Unable to join');
          }
          setFlash(`Joined ${data.session.code}.`, 'success');
          setActiveSession(data.session, data.player);
          closeJoinModal();
          renderRoster({
            sessionCode: data.session.code,
            status: data.session.status,
            players: data.players,
            maxPlayers: data.session.max_players,
          });
          requestLobbyRefresh();
          openLobbySocket();
          markSynced();
        } catch (error) {
          setFlash(error.message, 'error');
        }
      };

      const handleStartGame = async () => {
        if (!activeSession?.code || !currentPlayer?.id) return;
        try {
          const response = await fetch('/api/session_players.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'start', code: activeSession.code, playerId: currentPlayer.id }),
          });
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'Unable to start game');
          lobbyStatus.textContent = 'started';
          activeSession.status = 'started';
          setFlash('Game started. Host can now draw for turn order.', 'success');
          renderRoster({
            sessionCode: activeSession.code,
            players: currentPlayers,
            status: 'started',
            maxPlayers: activeSession.max_players || MAX_PLAYERS,
          });
          if (lobbySocket?.readyState === WebSocket.OPEN) {
            lobbySocket.send(JSON.stringify({ type: 'session.start', sessionCode: activeSession.code, by: currentPlayer.id }));
            lobbySocket.send(JSON.stringify({ type: 'refresh', sessionCode: activeSession.code }));
          }
          markSynced();
        } catch (error) {
          setFlash(error.message, 'error');
        }
      };

      const startTurnOrderDraw = () => {
        if (!activeSession?.code || !lobbySocket || lobbySocket.readyState !== WebSocket.OPEN) {
          setFlash('Connect to lobby first.', 'error');
          return;
        }
        turnOrderInFlight = true;
        drawTurnOrderBtn.disabled = true;
        resetTurnOrderUi();
        lobbySocket.send(JSON.stringify({ type: 'turnorder.draw', sessionCode: activeSession.code }));
        logTurn('Drawing tiles…');
      };

      const leaveSession = async () => {
        const saved = loadSavedSession();
        if (!saved?.clientToken) return;
        try {
          await fetch('/api/session_players.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: activeSession?.code, clientToken: saved.clientToken, playerName: playerNameInput.value }),
          });
        } catch (error) {
          // ignore
        }
        activeSession = null;
        currentPlayer = null;
        lobbyCard.hidden = true;
        setFlash('Left the lobby.', 'info');
        localStorage.removeItem(SESSION_STORAGE_KEY);
        requestLobbyRefresh();
      };

      const deleteSession = async () => {
        const saved = loadSavedSession();
        if (!saved?.clientToken || !activeSession?.code) return;
        const confirmed = window.confirm(`Delete session ${activeSession.code}?`);
        if (!confirmed) return;
        try {
          await fetch('/api/sessions.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: activeSession.code, clientToken: saved.clientToken, playerName: playerNameInput.value || currentPlayer?.name }),
          });
          setFlash('Lobby deleted.', 'success');
          lobbyCard.hidden = true;
          localStorage.removeItem(SESSION_STORAGE_KEY);
          requestLobbyRefresh();
        } catch (error) {
          setFlash('Unable to delete lobby.', 'error');
        }
      };

      const forceLeavePreviousSession = async () => {
        const identity = loadIdentity();
        if (!identity?.clientToken) {
          setFlash('No saved player identity to leave a previous lobby.', 'error');
          return;
        }
        try {
          const response = await fetch('/api/session_players.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'force_leave', clientToken: identity.clientToken }),
          });
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'Unable to leave previous lobby');
          setFlash(data.message || 'Left previous lobby.', 'success');
          setStuckSession(null);
          activeSession = null;
          currentPlayer = null;
          lobbyCard.hidden = true;
          localStorage.removeItem(SESSION_STORAGE_KEY);
          requestLobbyRefresh();
          fetchSessions();
        } catch (error) {
          setFlash(error.message, 'error');
        }
      };

      const attemptResume = async () => {
        const saved = loadSavedSession();
        if (!saved?.sessionCode || !saved.clientToken) return;
        try {
          const response = await fetch('/api/session_players.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'rejoin', code: saved.sessionCode, clientToken: saved.clientToken }),
          });
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'Unable to resume');
          setActiveSession(data.session, data.player);
          renderRoster({ sessionCode: data.session.code, status: data.session.status, players: data.players, maxPlayers: data.session.max_players });
          setFlash(`Rejoined ${data.session.code}.`, 'success');
          openLobbySocket();
          markSynced();
        } catch (error) {
          setFlash('No saved lobby.', 'info');
        }
      };

      const resolveIdentity = () => {
        const name = (playerNameInput.value || '').trim();
        const stored = loadIdentity();
        const clientToken = stored?.clientToken || crypto.randomUUID().replace(/-/g, '');
        if (name) {
          persistIdentity(name, clientToken);
        }
        return { playerName: name, clientToken };
      };

      const populateIdentity = () => {
        const stored = loadIdentity();
        if (stored?.playerName && playerNameInput) {
          playerNameInput.value = stored.playerName;
        }
        if (stored?.playerName && joinModalName) {
          joinModalName.value = stored.playerName;
        }
      };

      const openJoinModal = (code) => {
        pendingJoinCode = code;
        sessionCodeInput.value = code;
        if (joinModalTitle) joinModalTitle.textContent = `Join lobby ${code}`;
        populateIdentity();
        if (typeof joinModal?.showModal === 'function') {
          joinModal.showModal();
        }
        if (joinModalName) {
          joinModalName.focus();
          joinModalName.select();
        }
      };

      const closeJoinModal = () => {
        pendingJoinCode = null;
        if (typeof joinModal?.close === 'function') {
          joinModal.close();
        }
      };

      const copyCode = () => {
        if (!activeSession?.code) return;
        navigator.clipboard.writeText(activeSession.code).then(() => {
          setFlash('Invite code copied.', 'success');
        }).catch(() => {
          setFlash('Unable to copy code.', 'error');
        });
      };

      if (createSessionForm) createSessionForm.addEventListener('submit', handleCreateSession);
      if (joinSessionBtn) joinSessionBtn.addEventListener('click', handleJoinSession);
      if (playerNameInput) playerNameInput.addEventListener('change', resolveIdentity);
      if (joinModalForm) joinModalForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (joinModalName) {
          playerNameInput.value = joinModalName.value;
        }
        handleJoinSession();
      });
      if (joinModalCancel) joinModalCancel.addEventListener('click', closeJoinModal);
      if (startGameBtn) startGameBtn.addEventListener('click', handleStartGame);
      if (drawTurnOrderBtn) drawTurnOrderBtn.addEventListener('click', startTurnOrderDraw);
      if (leaveSessionBtn) leaveSessionBtn.addEventListener('click', leaveSession);
      if (deleteSessionBtn) deleteSessionBtn.addEventListener('click', deleteSession);
      if (forceLeaveBtn) forceLeaveBtn.addEventListener('click', forceLeavePreviousSession);
      if (refreshSessionsBtn) refreshSessionsBtn.addEventListener('click', () => { fetchSessions(); requestLobbyRefresh(); });
      if (resumeSessionBtn) resumeSessionBtn.addEventListener('click', attemptResume);
      if (copyCodeBtn) copyCodeBtn.addEventListener('click', copyCode);
      if (liveLobbyHint) liveLobbyHint.textContent = 'Live updates connected';

      populateIdentity();
      fetchSessions();
      attemptResume();
      openLobbySocket();
      startPolling();
      resetTurnOrderUi();
    });
  </script>
</body>
</html>
