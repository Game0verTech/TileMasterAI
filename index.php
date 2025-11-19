<?php
require __DIR__ . '/config/env.php';

$hasOpenAiKey = getenv('OPENAI_API_KEY') !== false && getenv('OPENAI_API_KEY') !== '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TileMasterAI | Phase 2 Experience Design</title>
  <style>
    :root {
      color-scheme: light;
      font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
      --bg: #f8fafc;
      --card: #ffffff;
      --ink: #0f172a;
      --muted: #475569;
      --accent: #6366f1;
      --accent-strong: #4f46e5;
      --border: #e2e8f0;
      --glow: 0 24px 50px rgba(79, 70, 229, 0.12);
      --radius: 18px;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: radial-gradient(circle at 18% 20%, #eef2ff 0, #eef2ff 35%, transparent 50%),
                  radial-gradient(circle at 82% 18%, #e0f2fe 0, #e0f2fe 30%, transparent 50%),
                  var(--bg);
      color: var(--ink);
      padding: 28px 18px 60px;
      display: flex;
      flex-direction: column;
      gap: 22px;
    }

    header {
      display: flex;
      flex-direction: column;
      gap: 12px;
      max-width: 1200px;
      margin: 0 auto;
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      background: #eef2ff;
      color: #312e81;
      font-weight: 600;
      width: fit-content;
    }

    h1 {
      margin: 0;
      font-size: clamp(32px, 5vw, 48px);
      letter-spacing: -0.6px;
    }

    p.lede {
      margin: 0;
      color: var(--muted);
      font-size: 17px;
      max-width: 720px;
      line-height: 1.6;
    }

    .status {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: #ecfeff;
      color: #0f172a;
      border: 1px solid #67e8f9;
      border-radius: 999px;
      padding: 8px 14px;
      font-weight: 600;
      box-shadow: 0 14px 30px rgba(14, 165, 233, 0.12);
    }

    .status-icon {
      width: 12px;
      height: 12px;
      border-radius: 999px;
      background: <?php echo $hasOpenAiKey ? '#22c55e' : '#f59e0b'; ?>;
      box-shadow: 0 0 0 5px rgba(34, 197, 94, 0.16);
    }

    .grid {
      max-width: 1200px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: 1fr;
      gap: 16px;
    }

    .card {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--border);
      box-shadow: var(--glow);
      padding: 18px 18px 16px;
    }

    .layout-shell {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 16px;
      align-items: start;
    }

    .board-preview {
      background: linear-gradient(135deg, rgba(99, 102, 241, 0.06), rgba(16, 185, 129, 0.06));
      border-radius: var(--radius);
      padding: 14px;
      border: 1px dashed #cbd5e1;
    }

    .board-grid {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 6px;
    }

    .tile {
      aspect-ratio: 1;
      border-radius: 10px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.12);
      display: grid;
      place-items: center;
      font-weight: 700;
      color: #4338ca;
      font-size: 14px;
    }

    .tile:nth-child(3n) { color: #dc2626; box-shadow: inset 0 0 0 1px rgba(220, 38, 38, 0.14); }
    .tile:nth-child(4n) { color: #0284c7; box-shadow: inset 0 0 0 1px rgba(2, 132, 199, 0.14); }

    .rack-bar {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      padding: 10px;
      background: #f8fafc;
      border: 1px dashed #cbd5e1;
      border-radius: 12px;
    }

    .rack-chip {
      background: var(--card);
      border-radius: 12px;
      padding: 8px 12px;
      border: 1px solid var(--border);
      font-weight: 700;
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }

    .actions {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 10px;
    }

    .btn {
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--ink);
      color: #fff;
      font-weight: 700;
      text-align: center;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.18);
    }

    .btn.secondary {
      background: #f8fafc;
      color: var(--ink);
      border-style: dashed;
    }

    .list {
      list-style: none;
      padding: 0;
      margin: 0;
      display: grid;
      gap: 8px;
    }

    .list-item {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 8px;
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #f8fafc;
    }

    .badge {
      padding: 4px 10px;
      border-radius: 999px;
      background: #eef2ff;
      color: #312e81;
      font-weight: 700;
      font-size: 13px;
    }

    .subhead {
      margin: 0 0 8px;
      font-size: 18px;
    }

    .note {
      color: var(--muted);
      font-size: 14px;
      margin: 4px 0 0;
    }

    .flow-grid {
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    }

    .flow-step {
      padding: 12px 14px;
      border-radius: 14px;
      background: #f8fafc;
      border: 1px solid var(--border);
      display: grid;
      gap: 6px;
    }

    .interaction-grid {
      display: grid;
      gap: 10px;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    }

    .upload-card {
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px dashed #cbd5e1;
      background: #f8fafc;
      display: grid;
      gap: 6px;
    }

    @media (min-width: 900px) {
      body { padding: 42px 32px 80px; }
      .grid { grid-template-columns: 2fr 1fr; }
      .grid .card:first-child { grid-column: span 2; }
    }

    @media (max-width: 599px) {
      .board-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      .actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .list-item { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <header>
    <span class="eyebrow">Phase 2 · Experience design</span>
    <h1>Designing the TileMasterAI board experience</h1>
    <p class="lede">Mobile-first scaffolding for the main play surface: board grid, rack, action controls, move insights, and upload stubs. These artifacts guide the upcoming interactive build-out.</p>
    <div class="status" aria-live="polite">
      <span class="status-icon" aria-hidden="true"></span>
      <span><?php echo $hasOpenAiKey ? 'OPENAI_API_KEY detected in environment.' : 'OPENAI_API_KEY not yet configured.'; ?></span>
    </div>
  </header>

  <section class="grid" aria-label="Phase 2 layout preview">
    <article class="card">
      <h2 class="subhead">Primary layout</h2>
      <p class="note">Board sits left, with rack and actions tucked underneath; move results and upload stubs stay to the right on larger viewports and stack gracefully on mobile.</p>
      <div class="layout-shell">
        <div class="board-preview" aria-label="Board preview">
          <div class="board-grid" role="presentation">
            <div class="tile">TW</div><div class="tile">DL</div><div class="tile">DW</div><div class="tile">TL</div><div class="tile">DL</div>
            <div class="tile">A</div><div class="tile">I</div><div class="tile">R</div><div class="tile">TL</div><div class="tile">DW</div>
            <div class="tile">DL</div><div class="tile">DW</div><div class="tile">*</div><div class="tile">DL</div><div class="tile">TL</div>
            <div class="tile">DW</div><div class="tile">TL</div><div class="tile">O</div><div class="tile">N</div><div class="tile">A</div>
            <div class="tile">DL</div><div class="tile">TL</div><div class="tile">DW</div><div class="tile">DL</div><div class="tile">TL</div>
          </div>
        </div>
        <div class="card" style="box-shadow:none; border-style:dashed;">
          <div class="rack-bar" aria-label="Rack preview">
            <span class="rack-chip">T</span>
            <span class="rack-chip">I</span>
            <span class="rack-chip">L</span>
            <span class="rack-chip">E</span>
            <span class="rack-chip">M</span>
            <span class="rack-chip">A</span>
            <span class="rack-chip">*</span>
          </div>
          <div class="actions" aria-label="Action buttons">
            <div class="btn">Run solver</div>
            <div class="btn secondary">Reset board</div>
            <div class="btn secondary">Clear rack</div>
            <div class="btn secondary">Shuffle rack</div>
          </div>
        </div>
      </div>
    </article>

    <article class="card">
      <h2 class="subhead">Top moves (mocked)</h2>
      <p class="note">Placeholder output for the solver: score, notation, and rack consumption for the leading candidates.</p>
      <ul class="list" aria-label="Mock move results">
        <li class="list-item"><div><strong>1) ORATION</strong> · H8 ➜ Down</div><span class="badge">78 pts</span></li>
        <li class="list-item"><div><strong>2) TONE</strong> · F7 ➜ Across</div><span class="badge">38 pts</span></li>
        <li class="list-item"><div><strong>3) MATE</strong> · I5 ➜ Across</div><span class="badge">32 pts</span></li>
        <li class="list-item"><div><strong>4) LATHE</strong> · D9 ➜ Down</div><span class="badge">29 pts</span></li>
        <li class="list-item"><div><strong>5) RAIN</strong> · L4 ➜ Across</div><span class="badge">22 pts</span></li>
      </ul>
    </article>

    <article class="card">
      <h2 class="subhead">Uploads & stubs</h2>
      <p class="note">File and camera affordances stay present but clearly labeled as “coming soon” until OCR lands.</p>
      <div class="interaction-grid">
        <div class="upload-card" aria-label="Board upload stub">
          <strong>Board image</strong>
          <span class="badge">Coming soon</span>
          <p class="note">Drop or tap to select. Preview will show and feed a future OCR parser.</p>
        </div>
        <div class="upload-card" aria-label="Rack upload stub">
          <strong>Rack image</strong>
          <span class="badge">Coming soon</span>
          <p class="note">Camera-friendly surface to capture letters; parsing will stub in demo values.</p>
        </div>
      </div>
    </article>
  </section>

  <section class="grid" aria-label="Experience flows and interactions">
    <article class="card">
      <h2 class="subhead">MVP flow</h2>
      <div class="flow-grid">
        <div class="flow-step">
          <strong>1) Board setup</strong>
          <p class="note">Tap to place tiles, long-press for blanks, or import via image stub.</p>
        </div>
        <div class="flow-step">
          <strong>2) Rack input</strong>
          <p class="note">On-screen rack accepts typing (letters + ? for blank) with optional shuffle/clear.</p>
        </div>
        <div class="flow-step">
          <strong>3) Request best moves</strong>
          <p class="note">Press “Run solver” or hit Enter; results stream into the move list with scores and notation.</p>
        </div>
        <div class="flow-step">
          <strong>4) Optional uploads</strong>
          <p class="note">Board/rack uploads stay opt-in; parsed tiles prefill the grid once OCR is wired.</p>
        </div>
      </div>
    </article>

    <article class="card">
      <h2 class="subhead">Keyboard & touch patterns</h2>
      <div class="interaction-grid">
        <div class="flow-step">
          <strong>Placement</strong>
          <p class="note">Tap to focus a square, type letters to place; arrow keys move focus for speed play.</p>
        </div>
        <div class="flow-step">
          <strong>Drag / drop</strong>
          <p class="note">Drag from rack to board on desktop; touch-hold to pick up a tile and tap a target square on mobile.</p>
        </div>
        <div class="flow-step">
          <strong>Blanks</strong>
          <p class="note">Long-press or hold modifier to assign blank value inline before confirming placement.</p>
        </div>
        <div class="flow-step">
          <strong>Shortcuts</strong>
          <p class="note">Enter runs solver, Ctrl/Cmd+Z undoes last placement, Shift+R shuffles rack, Shift+C clears rack.</p>
        </div>
      </div>
    </article>
  </section>
</body>
</html>
