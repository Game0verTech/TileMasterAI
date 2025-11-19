<?php
// Simple Hello World page for TileMasterAI
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TileMasterAI | Hello World</title>
  <style>
    :root {
      color-scheme: light dark;
      font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
    }

    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: radial-gradient(circle at 20% 20%, #f2f2f7 0, #dfe7ff 35%, #cdd4ff 60%),
                  radial-gradient(circle at 80% 30%, #fff4e6 0, #ffd6a5 35%, #ffc29e 60%),
                  #0f172a;
      color: #0f172a;
    }

    .card {
      background: rgba(255, 255, 255, 0.88);
      border-radius: 16px;
      box-shadow: 0 25px 50px rgba(15, 23, 42, 0.25);
      padding: 32px 40px;
      text-align: center;
      max-width: 420px;
      backdrop-filter: blur(8px);
      border: 1px solid rgba(15, 23, 42, 0.08);
    }

    h1 {
      margin: 0 0 12px;
      font-size: clamp(32px, 4vw, 40px);
      letter-spacing: -0.5px;
    }

    p {
      margin: 0;
      color: #334155;
      font-size: 17px;
      line-height: 1.5;
    }

    code {
      display: inline-block;
      background: #e2e8f0;
      color: #0f172a;
      padding: 4px 8px;
      border-radius: 8px;
      font-size: 15px;
    }
  </style>
</head>
<body>
  <main class="card" role="main">
    <h1>Hello, World! 👋</h1>
    <p>Welcome to the TileMasterAI PHP app. Your server setup is working.</p>
    <p>Next step: start shaping the experience in <code>index.php</code>.</p>
  </main>
</body>
</html>
