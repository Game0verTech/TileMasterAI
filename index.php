<?php
require __DIR__ . '/config/env.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TileMasterAI</title>
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
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      background: var(--bg);
      color: var(--ink);
      padding: 24px 16px 48px;
    }

    header {
      max-width: 960px;
      margin: 0 auto 16px;
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
    }

    .brand { display: flex; gap: 12px; align-items: center; }
    .logo { width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, #4f46e5, #22d3ee); box-shadow: 0 12px 26px rgba(79, 70, 229, 0.25); display: grid; place-items: center; color: #fff; font-weight: 800; font-size: 20px; letter-spacing: 0.02em; }
    h1 { margin: 0; font-size: clamp(24px, 4vw, 32px); }
    p.subtitle { margin: 4px 0 0; color: var(--muted); }

    main { max-width: 960px; margin: 0 auto; display: grid; gap: 16px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 18px; box-shadow: 0 12px 28px rgba(15, 23, 42, 0.04); }
    .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }

    label { display: block; font-weight: 600; margin-bottom: 6px; }
    input { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 10px; font-size: 15px; }
    button { border: none; border-radius: 12px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
    .btn-primary { background: var(--accent); color: #fff; }
    .btn-ghost { background: transparent; border: 1px solid var(--border); }
    .btn-danger { background: var(--danger); color: #fff; }
    .stack { display: grid; gap: 10px; }
    .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

    .lobby-list { display: grid; gap: 10px; }
    .lobby { border: 1px solid var(--border); padding: 12px; border-radius: 12px; background: #f8fafc; }
    .pill { padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
    .pill.success { background: #dcfce7; color: #166534; }
    .pill.warning { background: #fef9c3; color: #92400e; }
    .pill.info { background: #e0e7ff; color: #312e81; }

    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 8px; border-bottom: 1px solid var(--border); }
    th { background: #f1f5f9; }

    .muted { color: var(--muted); }
    .notice { color: var(--muted); font-size: 14px; }
  </style>
</head>
<body>
  <header>
    <div class="brand">
      <div class="logo">TM</div>
      <div>
        <h1>TileMasterAI</h1>
        <p class="subtitle">Account-based lobby and game launcher</p>
      </div>
    </div>
    <div class="row" id="userActions"></div>
  </header>

  <main>
    <section class="card" id="authCard">
      <div class="grid">
        <div>
          <h2>Login</h2>
          <div class="stack">
            <label for="loginUsername">Username</label>
            <input id="loginUsername" type="text" />
            <label for="loginPassword">Password</label>
            <input id="loginPassword" type="password" />
            <button class="btn-primary" id="loginBtn">Sign in</button>
          </div>
        </div>
        <div>
          <h2>Register</h2>
          <div class="stack">
            <label for="registerUsername">Username</label>
            <input id="registerUsername" type="text" />
            <label for="registerPassword">Password</label>
            <input id="registerPassword" type="password" />
            <label for="registerConfirm">Confirm password</label>
            <input id="registerConfirm" type="password" />
            <button class="btn-primary" id="registerBtn">Create account</button>
            <p class="notice">First account becomes admin automatically.</p>
          </div>
        </div>
      </div>
      <p class="notice" id="authStatus"></p>
    </section>

    <section class="card" id="lobbyCard" hidden>
      <div class="row" style="justify-content: space-between; align-items: flex-start;">
        <div>
          <h2>Lobby list</h2>
          <p class="notice">Join an existing lobby or create your own.</p>
        </div>
        <button class="btn-primary" id="createLobbyBtn">Create lobby</button>
      </div>
      <div class="stack" style="margin-top: 12px;">
        <div class="row">
          <input id="joinCode" placeholder="Lobby code" style="max-width: 200px;" />
          <button class="btn-ghost" id="joinLobbyBtn">Join lobby</button>
        </div>
        <div class="lobby-list" id="lobbyList"></div>
        <p class="notice" id="lobbyEmpty">No lobbies yet.</p>
      </div>
    </section>

    <section class="card" id="activeLobbyCard" hidden>
      <div class="row" style="justify-content: space-between; align-items: center;">
        <div>
          <h2 id="activeLobbyTitle">Lobby</h2>
          <p class="notice" id="activeLobbyMeta"></p>
        </div>
        <div class="row">
          <button class="btn-ghost" id="leaveLobbyBtn">Leave lobby</button>
          <button class="btn-primary" id="toggleReadyBtn">Ready up</button>
          <button class="btn-primary" id="startGameBtn" hidden>Force start</button>
        </div>
      </div>
      <table>
        <thead>
          <tr><th>Player</th><th>Status</th><th>Joined</th></tr>
        </thead>
        <tbody id="playerTable"></tbody>
      </table>
      <p class="notice" id="startHint"></p>
    </section>

    <section class="card" id="adminCard" hidden>
      <h2>Admin dashboard</h2>
      <div class="grid">
        <div>
          <h3>Users</h3>
          <table id="adminUsers"></table>
        </div>
        <div>
          <h3>Lobbies</h3>
          <table id="adminLobbies"></table>
        </div>
        <div>
          <h3>Sessions</h3>
          <table id="adminSessions"></table>
        </div>
      </div>
    </section>
  </main>

  <script>
    const state = {
      user: null,
      lobbies: [],
      activeLobby: null,
      playerReady: false,
    };

    const authStatus = document.getElementById('authStatus');
    const lobbyCard = document.getElementById('lobbyCard');
    const activeLobbyCard = document.getElementById('activeLobbyCard');
    const lobbyList = document.getElementById('lobbyList');
    const lobbyEmpty = document.getElementById('lobbyEmpty');
    const userActions = document.getElementById('userActions');
    const adminCard = document.getElementById('adminCard');

    async function fetchMe() {
      const res = await fetch('/api/auth.php');
      const data = await res.json();
      state.user = data.user;
      renderUserActions();
      if (state.user) {
        document.getElementById('authCard').hidden = true;
        lobbyCard.hidden = false;
        loadLobbies();
        if (state.user.role === 'admin') loadAdmin();
      }
    }

    async function loadLobbies() {
      const res = await fetch('/api/lobbies.php');
      if (!res.ok) return;
      const data = await res.json();
      state.lobbies = data.lobbies || [];
      renderLobbies();
    }

    function renderUserActions() {
      userActions.innerHTML = '';
      if (!state.user) return;
      const span = document.createElement('span');
      span.textContent = `Signed in as ${state.user.username} (${state.user.role})`;
      const logoutBtn = document.createElement('button');
      logoutBtn.textContent = 'Log out';
      logoutBtn.className = 'btn-ghost';
      logoutBtn.onclick = async () => {
        await fetch('/api/auth.php', { method: 'POST', body: JSON.stringify({ action: 'logout' }) });
        location.reload();
      };
      userActions.append(span, logoutBtn);
    }

    function renderLobbies() {
      lobbyList.innerHTML = '';
      if (!state.lobbies.length) {
        lobbyEmpty.hidden = false;
        return;
      }
      lobbyEmpty.hidden = true;
      state.lobbies.forEach((lobby) => {
        const div = document.createElement('div');
        div.className = 'lobby';
        const owner = lobby.players.find((p) => p.user_id === lobby.owner_user_id);
        div.innerHTML = `<div class="row" style="justify-content: space-between;">`
          + `<div><strong>Code ${lobby.code}</strong><br/><span class="muted">Owner: ${owner?.username || 'n/a'}</span></div>`
          + `<div class="pill ${lobby.status === 'in_game' ? 'warning' : 'info'}">${lobby.status}</div>`
          + `</div>`
          + `<p class="notice">${lobby.players.length} player(s)</p>`;
        div.onclick = () => joinLobby(lobby.code);
        lobbyList.appendChild(div);
      });
    }

    async function joinLobby(code) {
      const res = await fetch('/api/lobbies.php', { method: 'POST', body: JSON.stringify({ action: 'join', code }) });
      const data = await res.json();
      if (data.success) {
        state.activeLobby = data.lobby;
        loadLobbies();
        refreshLobby();
      }
    }

    async function refreshLobby() {
      if (!state.activeLobby) return;
      const latest = state.lobbies.find((l) => l.id === state.activeLobby.id);
      if (!latest) {
        activeLobbyCard.hidden = true;
        return;
      }
      const players = latest.players || [];
      state.playerReady = !!players.find((p) => p.user_id === state.user.id && p.is_ready);
      document.getElementById('activeLobbyTitle').textContent = `Lobby ${latest.code}`;
      document.getElementById('activeLobbyMeta').textContent = `${players.length} players • status ${latest.status}`;
      document.getElementById('startGameBtn').hidden = !(state.user.id === latest.owner_user_id);
      const tbody = document.getElementById('playerTable');
      tbody.innerHTML = '';
      players.forEach((player) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${player.username}${player.user_id === latest.owner_user_id ? ' (owner)' : ''}</td>`
          + `<td>${player.is_ready ? '<span class="pill success">ready</span>' : 'not ready'}</td>`
          + `<td class="muted">${player.joined_at || ''}</td>`;
        tbody.appendChild(tr);
      });
      document.getElementById('toggleReadyBtn').textContent = state.playerReady ? 'Unready' : 'Ready up';
      document.getElementById('startHint').textContent = latest.status === 'in_game'
        ? 'Game is starting…'
        : 'Everyone must be ready and at least two players to begin.';
      activeLobbyCard.hidden = false;
      if (latest.status === 'in_game') {
        window.location.href = `/game.php?lobbyId=${latest.id}`;
      }
    }

    document.getElementById('registerBtn').onclick = async () => {
      const username = document.getElementById('registerUsername').value.trim();
      const password = document.getElementById('registerPassword').value;
      const confirm = document.getElementById('registerConfirm').value;
      const res = await fetch('/api/auth.php', { method: 'POST', body: JSON.stringify({ action: 'register', username, password, confirm }) });
      const data = await res.json();
      authStatus.textContent = data.message || '';
      if (data.success) { location.reload(); }
    };

    document.getElementById('loginBtn').onclick = async () => {
      const username = document.getElementById('loginUsername').value.trim();
      const password = document.getElementById('loginPassword').value;
      const res = await fetch('/api/auth.php', { method: 'POST', body: JSON.stringify({ action: 'login', username, password }) });
      const data = await res.json();
      authStatus.textContent = data.message || '';
      if (data.success) { location.reload(); }
    };

    document.getElementById('createLobbyBtn').onclick = async () => {
      const res = await fetch('/api/lobbies.php', { method: 'POST', body: JSON.stringify({ action: 'create' }) });
      const data = await res.json();
      if (data.success) {
        state.activeLobby = data.lobby;
        loadLobbies();
        refreshLobby();
      }
    };

    document.getElementById('joinLobbyBtn').onclick = () => {
      const code = document.getElementById('joinCode').value.trim();
      if (code) joinLobby(code);
    };

    document.getElementById('toggleReadyBtn').onclick = async () => {
      if (!state.activeLobby) return;
      const res = await fetch('/api/lobbies.php', { method: 'POST', body: JSON.stringify({ action: 'ready', lobbyId: state.activeLobby.id, ready: !state.playerReady }) });
      if (res.ok) {
        await loadLobbies();
        refreshLobby();
      }
    };

    document.getElementById('leaveLobbyBtn').onclick = async () => {
      if (!state.activeLobby) return;
      await fetch('/api/lobbies.php', { method: 'POST', body: JSON.stringify({ action: 'leave', lobbyId: state.activeLobby.id }) });
      state.activeLobby = null;
      activeLobbyCard.hidden = true;
      loadLobbies();
    };

    document.getElementById('startGameBtn').onclick = async () => {
      if (!state.activeLobby) return;
      await fetch('/api/lobbies.php', { method: 'POST', body: JSON.stringify({ action: 'start', lobbyId: state.activeLobby.id }) });
      await loadLobbies();
      refreshLobby();
    };

    async function loadAdmin() {
      const res = await fetch('/api/admin.php');
      if (!res.ok) return;
      const data = await res.json();
      adminCard.hidden = false;
      const userTable = document.getElementById('adminUsers');
      userTable.innerHTML = '<tr><th>Id</th><th>Username</th><th>Role</th><th></th></tr>' +
        data.users.map((u) => `<tr><td>${u.id}</td><td>${u.username}</td><td>${u.role}</td><td><button class="btn-danger" data-user="${u.id}">Delete</button></td></tr>`).join('');
      const lobbyTable = document.getElementById('adminLobbies');
      lobbyTable.innerHTML = '<tr><th>Code</th><th>Status</th><th>Players</th><th></th></tr>' +
        data.lobbies.map((l) => `<tr><td>${l.code}</td><td>${l.status}</td><td>${l.players.length}</td><td><button class="btn-danger" data-lobby="${l.id}">Delete</button></td></tr>`).join('');
      const sessionTable = document.getElementById('adminSessions');
      sessionTable.innerHTML = '<tr><th>Session</th><th>User</th><th>Last seen</th><th></th></tr>' +
        data.sessions.map((s) => `<tr><td>${s.id}</td><td>${s.user_id}</td><td>${s.last_seen || ''}</td><td><button class="btn-danger" data-session="${s.id}">Terminate</button></td></tr>`).join('');

      userTable.querySelectorAll('button[data-user]').forEach((btn) => {
        btn.onclick = async () => {
          await fetch('/api/admin.php', { method: 'DELETE', body: JSON.stringify({ action: 'user', userId: btn.dataset.user }) });
          loadAdmin();
        };
      });
      lobbyTable.querySelectorAll('button[data-lobby]').forEach((btn) => {
        btn.onclick = async () => {
          await fetch('/api/admin.php', { method: 'DELETE', body: JSON.stringify({ action: 'lobby', lobbyId: btn.dataset.lobby }) });
          loadAdmin();
        };
      });
      sessionTable.querySelectorAll('button[data-session]').forEach((btn) => {
        btn.onclick = async () => {
          await fetch('/api/admin.php', { method: 'DELETE', body: JSON.stringify({ action: 'session', sessionId: btn.dataset.session }) });
          loadAdmin();
        };
      });
    }

    setInterval(loadLobbies, 3000);
    fetchMe();
  </script>
</body>
</html>
