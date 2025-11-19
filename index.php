<?php
require __DIR__ . '/config/env.php';
require __DIR__ . '/src/bootstrap.php';

use TileMasterAI\Game\Board;
use TileMasterAI\Game\Dictionary;
use TileMasterAI\Game\MoveGenerator;
use TileMasterAI\Game\Rack;
use TileMasterAI\Game\Scoring;
use TileMasterAI\Game\Tile;

$hasOpenAiKey = getenv('OPENAI_API_KEY') !== false && getenv('OPENAI_API_KEY') !== '';

$premiumBoard = [
  ['TW', '', '', 'DL', '', '', '', 'TW', '', '', '', 'DL', '', '', 'TW'],
  ['', 'DW', '', '', '', 'TL', '', '', '', 'TL', '', '', '', 'DW', ''],
  ['', '', 'DW', '', '', '', 'DL', '', 'DL', '', '', '', 'DW', '', ''],
  ['DL', '', '', 'DW', '', '', '', 'DL', '', '', '', 'DW', '', '', 'DL'],
  ['', '', '', '', 'DW', '', '', '', '', '', 'DW', '', '', '', ''],
  ['', 'TL', '', '', '', 'TL', '', '', '', 'TL', '', '', '', 'TL', ''],
  ['', '', 'DL', '', '', '', 'DL', '', 'DL', '', '', '', 'DL', '', ''],
  ['TW', '', '', 'DL', '', '', '', 'DW', '', '', '', 'DL', '', '', 'TW'],
  ['', '', 'DL', '', '', '', 'DL', '', 'DL', '', '', '', 'DL', '', ''],
  ['', 'TL', '', '', '', 'TL', '', '', '', 'TL', '', '', '', 'TL', ''],
  ['', '', '', '', 'DW', '', '', '', '', '', 'DW', '', '', '', ''],
  ['DL', '', '', 'DW', '', '', '', 'DL', '', '', '', 'DW', '', '', 'DL'],
  ['', '', 'DW', '', '', '', 'DL', '', 'DL', '', '', '', 'DW', '', ''],
  ['', 'DW', '', '', '', 'TL', '', '', '', 'TL', '', '', '', 'DW', ''],
  ['TW', '', '', 'DL', '', '', '', 'TW', '', '', '', 'DL', '', '', 'TW'],
];

$rowLabels = range('A', 'O');
$columnLabels = range(1, 15);

$sampleTiles = [
  'H8' => ['letter' => 'O'],
  'I8' => ['letter' => 'R'],
  'J8' => ['letter' => 'A'],
  'K8' => ['letter' => 'T'],
  'L8' => ['letter' => 'I'],
  'M8' => ['letter' => 'O'],
  'N8' => ['letter' => 'N'],
  'F7' => ['letter' => 'T'],
  'F8' => ['letter' => 'O'],
  'F9' => ['letter' => 'N'],
  'F10' => ['letter' => 'E'],
];

foreach ($sampleTiles as $coordinate => &$tileData) {
  $tileData['value'] = Scoring::tileValue($tileData['letter']);
}
unset($tileData);

$rackLetters = ['T', 'I', 'L', 'E', 'M', 'A', '?'];
$rackTiles = array_map(static fn ($letter) => [
  'letter' => $letter,
  'value' => Scoring::tileValue($letter),
], $rackLetters);

$tileDistribution = Scoring::tileDistribution();
$totalTiles = array_sum(array_map(static fn ($entry) => $entry['count'], $tileDistribution));
$blankCount = $tileDistribution['?']['count'] ?? 0;
$distributionValid = $totalTiles === 100 && $blankCount === 2;
$tileValuesAligned = array_reduce(array_keys($tileDistribution), static function ($carry, $letter) use ($tileDistribution) {
  if ($carry === false) {
    return false;
  }

  return $tileDistribution[$letter]['value'] === Scoring::tileValue($letter);
}, true);

$premiumLayout = Board::standardLayout();
$layoutSymmetric = true;
for ($r = 0; $r < Board::ROWS; $r++) {
  for ($c = 0; $c < Board::COLUMNS; $c++) {
    if ($premiumLayout[$r][$c] !== $premiumLayout[Board::ROWS - $r - 1][Board::COLUMNS - $c - 1]) {
      $layoutSymmetric = false;
      break 2;
    }
  }
}
$centerPremium = $premiumLayout[7][7] ?? '';

$boardModel = Board::standard();
foreach ($sampleTiles as $coordinate => $tileData) {
  $boardModel->placeTileByCoordinate(
    $coordinate,
    new Tile($tileData['letter'], $tileData['letter'] === '?', $tileData['value'])
  );
}

$dictionaryPath = getenv('DICTIONARY_PATH') ?: __DIR__ . '/data/dictionary-mini.txt';
$dictionary = new Dictionary($dictionaryPath);
$demoWord = 'ORATION';

$rackModel = Rack::fromLetters(array_map(static fn ($tile) => $tile['letter'], $rackTiles));
$moveGenerator = new MoveGenerator($boardModel, $dictionary);
$moveSuggestions = $moveGenerator->generateMoves($rackModel, 5);

$demoPlacements = [];
foreach (['H8', 'I8', 'J8', 'K8', 'L8', 'M8', 'N8'] as $coordinate) {
  $letter = $sampleTiles[$coordinate]['letter'] ?? '';
  if ($letter === '') {
    continue;
  }
  $demoPlacements[] = ['coord' => $coordinate, 'tile' => Tile::fromLetter($letter)];
}

$demoScore = Scoring::scorePlacements($boardModel, $demoPlacements);
$dictionaryHasDemoWord = $dictionary->has($demoWord);

$sanityChecks = [
  [
    'label' => 'Tile distribution',
    'status' => $distributionValid ? '100 tiles accounted for' : 'Check counts',
    'detail' => "Total {$totalTiles} tiles • {$blankCount} blanks • values " . ($tileValuesAligned ? 'aligned' : 'mismatch'),
  ],
  [
    'label' => 'Premium layout symmetry',
    'status' => $layoutSymmetric ? 'Mirrors correctly' : 'Needs attention',
    'detail' => 'Layout mirrors across center; center square is ' . ($centerPremium ?: 'unset'),
  ],
  [
    'label' => 'Center start star',
    'status' => $centerPremium === 'DW' ? 'DW anchor ready' : 'Center premium off',
    'detail' => 'H8 is marked as the first play double word.',
  ],
  [
    'label' => 'Dictionary health',
    'status' => $dictionaryHasDemoWord ? 'Word lookups OK' : 'Dictionary missing samples',
    'detail' => basename($dictionaryPath) . ' • ' . number_format($dictionary->count()) . ' entries',
  ],
  [
    'label' => 'AI key readiness',
    'status' => $hasOpenAiKey ? 'OPENAI_API_KEY loaded' : 'Add OPENAI_API_KEY',
    'detail' => $hasOpenAiKey ? 'Ready to call OpenAI for move advice.' : 'Store the key in .env and keep it server-side.',
  ],
];

$aiSetupNotes = [
  'Keep OPENAI_API_KEY in the server-side .env; never ship it to the browser.',
  'Expose a POST /api/moves endpoint that validates board/rack payloads before calling OpenAI.',
  'Use the move generator output as function-call arguments so GPT can rank or explain candidates.',
  'Cache dictionary lookups and rate-limit the API route to avoid accidental overuse.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TileMasterAI | Phase 4 Move Generation Preview</title>
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

    pre.code {
      background: #0f172a;
      color: #e2e8f0;
      padding: 12px;
      border-radius: 12px;
      overflow: auto;
      font-size: 13px;
      border: 1px solid #0f172a;
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
      padding: 12px;
      border: 1px dashed #cbd5e1;
      overflow-x: auto;
    }

    .board-grid {
      display: grid;
      grid-template-columns: repeat(15, minmax(20px, 1fr));
      gap: 5px;
      background: #e2e8f0;
      padding: 8px;
      border-radius: 14px;
      border: 1px solid #cbd5e1;
      width: min(100%, 720px);
      min-width: 420px;
      margin: 0 auto;
    }

    .cell {
      position: relative;
      aspect-ratio: 1;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      display: grid;
      place-items: center;
      font-weight: 700;
      color: #0f172a;
      font-size: 12px;
      text-transform: uppercase;
      overflow: hidden;
    }

    .cell::after {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: 8px;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
      pointer-events: none;
    }

    .cell.triple-word { background: #fecdd3; color: #7f1d1d; }
    .cell.double-word { background: #ffe4e6; color: #9f1239; }
    .cell.triple-letter { background: #bfdbfe; color: #1d4ed8; }
    .cell.double-letter { background: #e0f2fe; color: #075985; }
    .cell.center-star { background: #ffe4e6; color: #9f1239; }

    .cell-label {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.2px;
    }

    .coordinate {
      position: absolute;
      font-size: 10px;
      font-weight: 700;
      color: #94a3b8;
      pointer-events: none;
    }

    .coordinate.col { top: 6px; right: 8px; }
    .coordinate.row { bottom: 6px; left: 8px; }

    .tile {
      width: calc(100% - 8px);
      height: calc(100% - 8px);
      background: linear-gradient(135deg, #f5e0c3, #e6c89f);
      border-radius: 6px;
      border: 1px solid #d4a373;
      box-shadow: 0 4px 10px rgba(15, 23, 42, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
      display: grid;
      align-items: center;
      justify-items: center;
      grid-template-rows: 1fr auto;
      padding: 4px 6px;
      color: #0f172a;
    }

    .tile .letter { font-size: 18px; font-weight: 800; }
    .tile .value { font-size: 10px; font-weight: 700; justify-self: end; }

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

    .rack-tile {
      width: 56px;
      height: 56px;
      display: grid;
      align-items: center;
      justify-items: center;
      grid-template-rows: 1fr auto;
      background: linear-gradient(135deg, #f5e0c3, #e6c89f);
      border-radius: 8px;
      border: 1px solid #d4a373;
      box-shadow: 0 6px 16px rgba(15, 23, 42, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
      color: #0f172a;
      font-weight: 800;
    }

    .rack-tile .letter { font-size: 18px; }
    .rack-tile .value { font-size: 11px; justify-self: end; font-weight: 700; }

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
      .board-preview { padding: 10px; }
      .board-grid { min-width: 360px; }
      .actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .list-item { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <header>
    <span class="eyebrow">Phase 2 · Experience design</span>
    <h1>Designing the TileMasterAI board experience</h1>
    <p class="lede">Mobile-first scaffolding for a Scrabble-standard play surface: authentic 15x15 bonus layout, wooden tile styling, rack, action controls, move insights, and upload stubs. These artifacts guide the upcoming interactive build-out.</p>
    <div class="status" aria-live="polite">
      <span class="status-icon" aria-hidden="true"></span>
      <span><?php echo $hasOpenAiKey ? 'OPENAI_API_KEY detected in environment.' : 'OPENAI_API_KEY not yet configured.'; ?></span>
    </div>
  </header>

  <section class="grid" aria-label="Sanity checks and AI readiness">
    <article class="card">
      <h2 class="subhead">Scrabble sanity checks</h2>
      <p class="note">Quick validation passes keep the layout, tiles, and dictionary aligned to standard rules.</p>
      <div class="list" aria-label="Sanity check results">
        <?php foreach ($sanityChecks as $check): ?>
          <div class="list-item">
            <div><strong><?php echo $check['label']; ?></strong><br><span class="note"><?php echo $check['detail']; ?></span></div>
            <span class="badge"><?php echo $check['status']; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </article>

    <article class="card">
      <h2 class="subhead">AI helper setup</h2>
      <p class="note">Guardrails for letting OpenAI rank moves or explain choices without leaking secrets.</p>
      <div class="list" aria-label="AI setup guidance">
        <?php foreach ($aiSetupNotes as $note): ?>
          <div class="list-item">
            <div><span class="note"><?php echo $note; ?></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
  </section>

  <section class="grid" aria-label="Phase 2 layout preview">
    <article class="card">
      <h2 class="subhead">Primary layout</h2>
      <p class="note">Standard 15x15 Scrabble grid with the authentic premium pattern, a centered star on the H8 double word, and sample tiles placed using real coordinates.</p>
      <div class="layout-shell">
        <div class="board-preview" aria-label="Board preview">
          <div class="board-grid" role="presentation">
            <?php foreach ($premiumBoard as $rowIndex => $row): ?>
              <?php foreach ($row as $colIndex => $cellType):
                $rowLabel = $rowLabels[$rowIndex];
                $colLabel = $columnLabels[$colIndex];
                $coordKey = $rowLabel . $colLabel;
                $tile = $sampleTiles[$coordKey] ?? null;
                $isCenter = $rowIndex === 7 && $colIndex === 7;
                $classes = 'cell';

                if ($cellType === 'TW') { $classes .= ' triple-word'; }
                if ($cellType === 'DW') { $classes .= ' double-word'; }
                if ($cellType === 'TL') { $classes .= ' triple-letter'; }
                if ($cellType === 'DL') { $classes .= ' double-letter'; }
                if ($isCenter) { $classes .= ' center-star'; }

                $cellName = match ($cellType) {
                  'TW' => 'triple word',
                  'DW' => 'double word',
                  'TL' => 'triple letter',
                  'DL' => 'double letter',
                  default => 'regular'
                };

                $ariaParts = ["{$rowLabel}{$colLabel}", $cellName];
                if ($isCenter) { $ariaParts[] = 'start star'; }
                if ($tile) { $ariaParts[] = "tile {$tile['letter']} ({$tile['value']} pt)"; }
                $ariaLabel = implode(' · ', $ariaParts);
              ?>
              <div class="<?php echo $classes; ?>" aria-label="<?php echo $ariaLabel; ?>">
                <?php if ($rowIndex === 0): ?><span class="coordinate col"><?php echo $colLabel; ?></span><?php endif; ?>
                <?php if ($colIndex === 0): ?><span class="coordinate row"><?php echo $rowLabel; ?></span><?php endif; ?>

                <?php if ($tile): ?>
                  <div class="tile" aria-hidden="true">
                    <span class="letter"><?php echo $tile['letter']; ?></span>
                    <span class="value"><?php echo $tile['value']; ?></span>
                  </div>
                <?php elseif ($isCenter): ?>
                  <span class="cell-label">★ DW</span>
                <?php elseif ($cellType !== ''): ?>
                  <span class="cell-label"><?php echo $cellType; ?></span>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card" style="box-shadow:none; border-style:dashed;">
          <div class="rack-bar" aria-label="Rack preview">
            <?php foreach ($rackTiles as $rackTile): ?>
              <div class="rack-tile" aria-label="Rack tile <?php echo $rackTile['letter']; ?>">
                <span class="letter"><?php echo $rackTile['letter']; ?></span>
                <span class="value"><?php echo $rackTile['value']; ?></span>
              </div>
            <?php endforeach; ?>
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
      <h2 class="subhead">Phase 4: anchor-based move generation</h2>
      <p class="note">Suggestions are generated from board anchors (adjacent empties) using the demo rack, dictionary validation, and scoring with cross-checks.</p>
      <ul class="list" aria-label="Generated move results">
        <?php if ($moveSuggestions === []): ?>
          <li class="list-item"><div>No legal moves found with the current rack.</div><span class="badge">–</span></li>
        <?php else: ?>
          <?php foreach ($moveSuggestions as $index => $move): ?>
            <?php $crossCount = count($move['crossWords']); ?>
            <li class="list-item">
              <div>
                <strong><?php echo ($index + 1) . ') ' . $move['word']; ?></strong> · <?php echo $move['start']; ?> ➜ <?php echo ucfirst($move['direction']); ?><br>
                <span class="note">Main: <?php echo $move['mainWordScore']; ?> pts<?php if ($crossCount > 0): ?> · Cross-words: <?php echo $crossCount; ?><?php endif; ?></span>
              </div>
              <span class="badge"><?php echo $move['score']; ?> pts</span>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
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

  <section class="grid" aria-label="Phase 3 game engine foundations">
    <article class="card">
      <h2 class="subhead">Phase 3 kickoff: board state & scoring helpers</h2>
      <p class="note">Backend-friendly PHP domain classes now describe the 15x15 board, rack tiles, dictionary lookups, and baseline scoring (tile values + letter/word multipliers).</p>
      <div class="list" aria-label="Engine milestones">
        <div class="list-item">
          <div><strong>Board &amp; rack models</strong><br><span class="note">Coordinate parsing (A1–O15), premium lookup, tile storage, and rack containers.</span></div>
          <span class="badge">Board::standard()</span>
        </div>
        <div class="list-item">
          <div><strong>Dictionary</strong><br><span class="note">Pluggable wordlist driven by <code>DICTIONARY_PATH</code> (defaults to <code>data/dictionary-mini.txt</code>).</span></div>
          <span class="badge"><?php echo number_format($dictionary->count()); ?> entries</span>
        </div>
        <div class="list-item">
          <div><strong>Scoring helpers</strong><br><span class="note">Tile values + DL/TL/DW/TW multipliers ready for solver integration.</span></div>
          <span class="badge">Helpers: Scoring::tileValues()</span>
        </div>
      </div>
      <div class="list" aria-label="Dictionary and scoring preview" style="margin-top: 12px;">
        <div class="list-item">
          <div><strong><?php echo $demoWord; ?></strong> lookup</div>
          <span class="badge"><?php echo $dictionaryHasDemoWord ? 'In wordlist' : 'Missing'; ?></span>
        </div>
        <div class="list-item">
          <div><strong>Move score preview</strong><br><span class="note"><?php echo $demoWord; ?> on H8 across (DW on start) using tile values + multipliers.</span></div>
          <span class="badge"><?php echo $demoScore['total']; ?> pts</span>
        </div>
      </div>
    </article>
  </section>

  <section class="grid" aria-label="Phase 4 move generation">
    <article class="card">
      <h2 class="subhead">Phase 4 kickoff: anchor & cross-check solver loop</h2>
      <div class="list" aria-label="Solver highlights">
        <div class="list-item">
          <div><strong>Anchors & adjacency</strong><br><span class="note">Empty squares touching existing tiles (plus center start) seed the horizontal generator.</span></div>
          <span class="badge">Board-aware</span>
        </div>
        <div class="list-item">
          <div><strong>Dictionary validation</strong><br><span class="note">Main word and all perpendicular cross-words must appear in the active dictionary.</span></div>
          <span class="badge"><?php echo number_format($dictionary->count()); ?> words</span>
        </div>
        <div class="list-item">
          <div><strong>Scoring integration</strong><br><span class="note">Letter/word multipliers apply only to newly placed tiles; cross-word totals are added.</span></div>
          <span class="badge">Scoring::scoreMove()</span>
        </div>
      </div>
    </article>

    <article class="card">
      <h2 class="subhead">API contract: request top N moves</h2>
      <p class="note">Planned endpoint signature for the solver surface (JSON). Takes current board state, rack letters, and desired limit; returns ranked moves with scoring breakdowns.</p>
      <pre class="code" aria-label="API contract example">POST /api/moves
{
  "board": "array of placed tiles [{coord: 'H8', letter: 'O', blank: false}]",
  "rack": ["T", "I", "L", "E", "M", "A", "?"],
  "limit": 5
}

Response
{
  "moves": [
    {
      "word": "ORATION",
      "start": "H8",
      "direction": "horizontal",
      "score": 78,
      "mainWord": {"word": "ORATION", "total": 78},
      "crossWords": []
    }
  ]
}</pre>
    </article>
  </section>
</body>
</html>
