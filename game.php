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

$rackLetters = ['T', 'I', 'L', 'E', 'M', 'A', '?'];
$rackModel = Rack::fromLetters($rackLetters);
$rackTiles = array_map(static function (Tile $tile) {
  $letter = $tile->letter();
  $isBlank = $tile->isBlank();

  return [
    'letter' => $letter,
    'value' => $tile->value(),
    'isBlank' => $isBlank,
    'displayLetter' => $isBlank && $letter === '?' ? '' : $letter,
  ];
}, $rackModel->tiles());

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

$dictionaryPath = getenv('DICTIONARY_PATH') ?: __DIR__ . '/data/dictionary-mini.txt';
$dictionary = new Dictionary($dictionaryPath);
$demoWord = 'ORATION';

$moveGenerator = new MoveGenerator($boardModel, $dictionary);
$moveSuggestions = $moveGenerator->generateMoves($rackModel, 5);

$normalizeMove = static function (array $move): array {
  return [
    'word' => $move['word'] ?? '',
    'direction' => $move['direction'] ?? '',
    'start' => $move['start'] ?? '',
    'score' => $move['score'] ?? 0,
    'mainWordScore' => $move['mainWordScore'] ?? 0,
    'crossWords' => array_map(static function ($cross) {
      return [
        'word' => $cross['word'] ?? '',
        'score' => $cross['score'] ?? 0,
      ];
    }, $move['crossWords'] ?? []),
    'placements' => array_map(static function ($placement) {
      return [
        'coord' => $placement['coord'] ?? '',
        'letter' => ($placement['tile']->letter() ?? ''),
        'isBlank' => $placement['tile']->isBlank() ?? false,
      ];
    }, $move['placements'] ?? []),
  ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'suggestions') {
  $input = json_decode(file_get_contents('php://input'), true) ?? [];
  $rackLetters = array_map(static fn ($letter) => strtoupper((string) $letter), $input['rack'] ?? []);
  $boardPayload = $input['board'] ?? [];

  $boardForMoveGen = Board::standard();
  foreach ($boardPayload as $placement) {
    $row = (int) ($placement['row'] ?? -1) + 1; // convert 0-index from client
    $col = (int) ($placement['col'] ?? -1) + 1;
    $letter = strtoupper((string) ($placement['letter'] ?? ''));
    $assigned = strtoupper((string) ($placement['assignedLetter'] ?? ''));
    $isBlank = (bool) ($placement['isBlank'] ?? false);

    if ($row < 1 || $col < 1 || $row > Board::ROWS || $col > Board::COLUMNS || $letter === '') {
      continue;
    }

    $tileLetter = $isBlank ? ($assigned ?: '?') : $letter;
    $tile = Tile::fromLetter($tileLetter, $isBlank);
    $boardForMoveGen->placeTile($row, $col, $tile);
  }

  $rackForMoveGen = Rack::fromLetters($rackLetters);
  $generator = new MoveGenerator($boardForMoveGen, $dictionary);
  $suggestions = $generator->generateMoves($rackForMoveGen, 10);
  $normalizedSuggestions = array_map($normalizeMove, $suggestions);

  header('Content-Type: application/json');
  echo json_encode(['suggestions' => $normalizedSuggestions]);
  exit;
}

$demoPlacements = [];
$demoBoard = Board::standard();
$demoStartRow = 8;
$demoStartColumn = 8;
foreach (str_split($demoWord) as $index => $letter) {
  $coord = Board::coordinateKey($demoStartRow, $demoStartColumn + $index);
  $demoPlacements[] = ['coord' => $coord, 'tile' => Tile::fromLetter($letter)];
}

$demoScore = Scoring::scorePlacements($demoBoard, $demoPlacements);
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
      --tile-wood: linear-gradient(135deg, rgba(255, 255, 255, 0.65), rgba(255, 255, 255, 0)),
        linear-gradient(115deg, rgba(210, 167, 110, 0.35), rgba(210, 167, 110, 0)),
        repeating-linear-gradient(
          22deg,
          rgba(255, 255, 255, 0.28) 0px,
          rgba(255, 255, 255, 0.28) 10px,
          rgba(0, 0, 0, 0.035) 10px,
          rgba(0, 0, 0, 0.035) 18px
        ),
        linear-gradient(135deg, #f2d5a2 0%, #dfbd84 55%, #f3d8a2 100%);
      --tile-wood-border: #b9874c;
      --glow: 0 24px 50px rgba(79, 70, 229, 0.12);
      --radius: 18px;
      --cell-size: clamp(40px, 4vw + 10px, 56px);
      --cell-gap: clamp(3px, 1vw, 6px);
      --tile-size: calc(var(--cell-size) - 8px);
      --top-dock-height: 76px;
      --bottom-dock-height: 132px;
      --board-toolbar: rgba(15, 23, 42, 0.86);
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
      padding: var(--top-dock-height) 0 var(--bottom-dock-height);
      display: flex;
      flex-direction: column;
      gap: 0;
      overflow: hidden;
    }

    .hidden { display: none !important; }

    .draw-overlay {
      position: fixed;
      inset: 0;
      background: radial-gradient(circle at 20% 20%, rgba(99, 102, 241, 0.25), transparent 45%),
                  radial-gradient(circle at 80% 15%, rgba(14, 165, 233, 0.22), transparent 40%),
                  rgba(15, 23, 42, 0.86);
      display: grid;
      place-items: center;
      padding: 24px;
      z-index: 2000;
      backdrop-filter: blur(6px);
    }

    .draw-panel {
      background: linear-gradient(180deg, rgba(10, 14, 27, 0.9), rgba(9, 12, 22, 0.98));
      border: 1px solid #1e293b;
      border-radius: 18px;
      padding: 18px;
      width: min(100%, 940px);
      color: #e2e8f0;
      box-shadow: 0 24px 48px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(148, 163, 184, 0.06);
      display: grid;
      gap: 14px;
      margin: 0 auto;
      text-align: center;
    }

    .draw-hero { display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr); align-items: center; justify-items: center; }
    .draw-stage { display: flex; align-items: stretch; justify-content: center; gap: 16px; padding: 12px; background: rgba(255,255,255,0.04); border-radius: 14px; border: 1px solid rgba(148, 163, 184, 0.12); box-shadow: inset 0 1px 0 rgba(255,255,255,0.05); flex-wrap: wrap; width: min(940px, 100%); margin: 0 auto; }
    .draw-bag { width: 200px; min-height: 200px; position: relative; display: grid; place-items: center; cursor: pointer; }
    .draw-bag.disabled { pointer-events: none; opacity: 0.65; }
    .bag-img { width: 100%; max-width: 200px; filter: drop-shadow(0 16px 28px rgba(0,0,0,0.32)); transition: transform 280ms ease, filter 280ms ease; }
    .bag-img:hover { transform: translateY(-4px); filter: drop-shadow(0 18px 32px rgba(99,102,241,0.25)); }
    .bag-open .bag-img { transform: rotate(-6deg) translateY(2px); }
    .bag-pop .bag-img { animation: bag-pop 480ms ease; }
    .bag-burst { position: absolute; inset: 0; pointer-events: none; filter: drop-shadow(0 8px 24px rgba(0,0,0,0.22)); opacity:0; }
    .bag-burst::before, .bag-burst::after { content: ""; position: absolute; width: 32px; height: 32px; background: radial-gradient(circle, rgba(255,255,255,0.8), transparent 60%); border-radius: 50%; opacity: 0.8; }
    .bag-burst::before { top: 20%; left: 8%; }
    .bag-burst::after { top: 12%; right: 6%; }
    .bag-pop .bag-burst { animation: burst 520ms ease; }
    .draw-spill { flex: 1; min-width: 280px; display: grid; gap: 10px; align-content: start; }
    .spill-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 10px; position: relative; min-height: 120px; }
    .spill-tile { position: relative; width: 100%; aspect-ratio: 1; border: none; border-radius: 12px; background: var(--tile-wood); box-shadow: 0 16px 32px rgba(0,0,0,0.32), 0 0 0 1px rgba(148, 163, 184, 0.24); cursor: pointer; transform: translate3d(0, 10px, 0) rotate(calc(var(--angle, 0deg))); transition: transform 360ms cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 160ms ease, opacity 260ms ease; overflow: hidden; }
    .spill-tile::after { content: ""; position: absolute; inset: 0; border-radius: inherit; box-shadow: inset 0 1px 0 rgba(255,255,255,0.15), inset 0 -6px 18px rgba(0,0,0,0.15); pointer-events: none; }
    .spill-tile .tile-back { position: absolute; inset: 0; display: grid; place-items: center; background: linear-gradient(140deg, rgba(255,255,255,0.25), rgba(0,0,0,0.05)), var(--tile-wood); color: #0f172a; font-size: 20px; font-weight: 800; letter-spacing: 0.6px; }
    .spill-tile .tile-front { position: absolute; inset: 0; display: grid; place-items: center; background: var(--tile-wood); color: #0f172a; font-size: 34px; font-weight: 900; transform: rotateY(90deg); backface-visibility: hidden; box-shadow: inset 0 1px 0 rgba(255,255,255,0.4); }
    .spill-tile.landed { transform: translate3d(var(--tx, 0px), var(--ty, 0px), 0) rotate(calc(var(--angle, 0deg))); box-shadow: 0 18px 36px rgba(0,0,0,0.3), 0 0 0 1px rgba(148, 163, 184, 0.22); }
    .spill-tile:hover { box-shadow: 0 16px 32px rgba(79, 70, 229, 0.2), 0 0 0 1px rgba(99, 102, 241, 0.4); transform: translate3d(var(--tx, 0px), var(--ty, 0px), 0) scale(1.03) rotate(calc(var(--angle, 0deg))); }
    .spill-tile.revealed { animation: tile-pop 360ms ease; }
    .spill-tile.revealed .tile-front { animation: tile-flip 400ms ease forwards; }
    .spill-tile.revealed .tile-back { opacity: 0; }
    .spill-hint { margin: 0; color: #cbd5e1; }
    .draw-reveal { display: grid; grid-template-columns: auto 1fr; gap: 10px; align-items: center; padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,0.04); border: 1px solid rgba(148, 163, 184, 0.12); }
    .draw-chip { width: 64px; height: 64px; border-radius: 14px; background: var(--tile-wood); border: 1px solid #b9874c; display: grid; place-items: center; font-size: 30px; font-weight: 900; color: #0f172a; box-shadow: 0 12px 22px rgba(0,0,0,0.28); }
    .draw-chip.revealed { animation: card-pop 420ms ease; }
    .draw-reveal-copy { display: grid; gap: 4px; }
    .draw-bubble { flex: 1; display: grid; gap: 6px; }
    .draw-meter { display: grid; gap: 6px; }
    .meter-track { width: 100%; height: 12px; border-radius: 999px; background: rgba(148, 163, 184, 0.2); overflow: hidden; }
    .meter-fill { display: block; height: 100%; width: 0; background: linear-gradient(90deg, #22c55e, #16a34a); border-radius: inherit; transition: width 240ms ease; }

    .draw-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
    .draw-grid table { width: 100%; border-collapse: collapse; }
    .draw-grid th, .draw-grid td { padding: 8px; text-align: left; border-bottom: 1px solid #1e293b; }
    .draw-grid th { color: #cbd5e1; }

    .draw-actions { display: flex; justify-content: center; align-items: center; gap: 10px; flex-wrap: wrap; text-align: center; }
    .draw-actions button { border: none; border-radius: 12px; padding: 10px 14px; font-weight: 700; cursor: pointer; background: #6366f1; color: #fff; box-shadow: 0 10px 20px rgba(99,102,241,0.25); }
    .draw-actions button:disabled { opacity: 0.5; cursor: not-allowed; }

    .draw-modal {
      position: fixed;
      inset: 0;
      display: grid;
      place-items: center;
      background: rgba(0, 0, 0, 0.7);
      z-index: 2100;
    }

    .draw-modal .modal-card {
      background: #0f172a;
      border-radius: 16px;
      border: 1px solid #1e293b;
      padding: 20px;
      width: min(100%, 420px);
      color: #e2e8f0;
      text-align: center;
      box-shadow: 0 18px 32px rgba(0, 0, 0, 0.35);
    }

    .celebration {
      position: absolute;
      inset: -10px;
      overflow: hidden;
      pointer-events: none;
    }

    .confetti-piece {
      position: absolute;
      width: 10px;
      height: 18px;
      background: linear-gradient(135deg, #fbbf24, #f472b6);
      border-radius: 4px;
      animation: confetti-fall 1.6s ease-in infinite;
      opacity: 0.8;
    }

    .confetti-piece:nth-child(3n) { background: linear-gradient(135deg, #4f46e5, #06b6d4); }
    .confetti-piece:nth-child(4n) { background: linear-gradient(135deg, #22c55e, #84cc16); }

    @keyframes confetti-fall {
      0% { transform: translate3d(0, -40px, 0) rotate(0deg); opacity: 0; }
      25% { opacity: 1; }
      100% { transform: translate3d(0, 260px, 0) rotate(260deg); opacity: 0; }
    }

    .winner-face {
      width: 72px;
      height: 72px;
      border-radius: 18px;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, #22c55e, #14b8a6);
      color: #fff;
      font-size: 34px;
      box-shadow: 0 18px 38px rgba(20, 184, 166, 0.35);
      margin: 0 auto;
    }

    .runnerup-face {
      background: linear-gradient(135deg, #94a3b8, #cbd5e1);
      box-shadow: 0 12px 28px rgba(148, 163, 184, 0.4);
    }

    .winner-copy { text-align: center; display: grid; gap: 6px; }

    .draw-ticker { font-size: 64px; font-weight: 900; letter-spacing: 6px; margin: 8px 0; }
    .countdown { font-size: 36px; font-weight: 800; margin-top: 10px; }

    @keyframes card-pop {
      0% { transform: translateY(18px) scale(0.96) rotate(-8deg); opacity: 0; }
      60% { transform: translateY(-6px) scale(1.05) rotate(-2deg); opacity: 1; }
      100% { transform: translateY(0) scale(1) rotate(-6deg); opacity: 1; }
    }

    @keyframes bag-pop {
      0% { transform: translateY(10px) scale(0.96); }
      40% { transform: translateY(-12px) scale(1.04); }
      100% { transform: translateY(0) scale(1); }
    }

    @keyframes burst {
      0% { transform: scale(0.6); opacity: 0; }
      40% { transform: scale(1.05); opacity: 1; }
      100% { transform: scale(1); opacity: 0; }
    }

    @keyframes tile-pop {
      0% { transform: translate3d(var(--tx, 0px), var(--ty, 0px), 0) scale(0.95); }
      70% { transform: translate3d(calc(var(--tx, 0px) * 0.9), calc(var(--ty, 0px) * 0.9), 0) scale(1.06); }
      100% { transform: translate3d(var(--tx, 0px), var(--ty, 0px), 0) scale(1); }
    }

    @keyframes tile-flip {
      0% { transform: rotateY(90deg); }
      60% { transform: rotateY(-10deg) scale(1.04); }
      100% { transform: rotateY(0deg) scale(1); }
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
      grid-template-columns: 1fr;
      gap: 16px;
      align-items: start;
    }

    .board-preview {
      background: linear-gradient(135deg, rgba(99, 102, 241, 0.06), rgba(16, 185, 129, 0.06));
      border-radius: var(--radius);
      padding: 12px;
      border: 1px dashed #cbd5e1;
      overflow: hidden;
      width: min(1100px, 100%);
      display: flex;
      justify-content: center;
      max-width: 100%;
    }

    .board-grid {
      display: grid;
      grid-template-columns: repeat(15, var(--cell-size));
      gap: var(--cell-gap);
      background: #e2e8f0;
      padding: 8px;
      border-radius: 14px;
      border: 1px solid #cbd5e1;
      width: max-content;
      max-width: min(100%, 960px);
      margin: 0 auto;
    }

    .cell {
      position: relative;
      width: var(--cell-size);
      height: var(--cell-size);
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
      grid-template-rows: 1fr;
    }

    .cell > * { grid-area: 1 / 1 / 2 / 2; }

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

    .cell.show-premium {
      grid-template-rows: auto 1fr;
      align-items: start;
    }

    .cell.show-premium > .cell-label { align-self: start; }
    .cell.show-premium > .tile { grid-area: 2 / 1 / 3 / 2; }
    .cell.premium-used > .cell-label { display: none; }

    .cell-label {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.2px;
    }

    .tile {
      width: var(--tile-size);
      height: var(--tile-size);
      background: var(--tile-wood);
      border-radius: 5px;
      border: 1px solid var(--tile-wood-border);
      box-shadow: 0 4px 8px rgba(15, 23, 42, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.7);
      position: relative;
      display: grid;
      align-items: center;
      justify-items: center;
      grid-template-areas: "stack";
      padding: 6px 8px;
      color: #0f172a;
    }

    .tile.blank,
    .rack-tile.blank {
      background: var(--tile-wood);
      border-color: var(--tile-wood-border);
    }

    .tile .letter,
    .tile .value {
      grid-area: stack;
    }

    .tile .letter {
      font-size: 22px;
      font-weight: 800;
      letter-spacing: 0.3px;
      line-height: 1;
    }

    .tile .value {
      align-self: end;
      justify-self: end;
      margin: 0 4px 4px 0;
      font-size: 11px;
      font-weight: 700;
      line-height: 1;
    }

    .letter.blank-empty { color: transparent; }
    .letter.blank-assigned { color: #2563eb; }

    .tile.blank .value,
    .rack-tile.blank .value { display: none; }

    .tile-ghost {
      position: fixed;
      z-index: 1200;
      pointer-events: none;
      transition: transform 0.38s ease, opacity 0.38s ease;
      box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
    }

    .rack-wrap {
      display: grid;
      grid-template-areas: 'help rack actions';
      grid-template-columns: auto 1fr auto;
      align-items: center;
      gap: 10px;
      position: relative;
      width: 100%;
      min-width: 0;
    }

    .rack-wrap > .dock-help { grid-area: help; }

    .dock-help {
      width: 34px;
      height: 34px;
      border-radius: 12px;
      border: 1px solid rgba(226, 232, 240, 0.6);
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), rgba(148, 163, 184, 0.14));
      color: #e2e8f0;
      box-shadow: 0 10px 24px rgba(14, 165, 233, 0.22);
      display: grid;
      place-items: center;
      cursor: pointer;
      transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
    }

    .dock-help:hover { transform: translateY(-1px); border-color: rgba(125, 211, 252, 0.9); }

    .dock-tooltip {
      position: absolute;
      left: 0;
      bottom: calc(100% + 10px);
      width: min(320px, 90vw);
      background: rgba(15, 23, 42, 0.96);
      color: #e2e8f0;
      border: 1px solid rgba(148, 163, 184, 0.5);
      border-radius: 14px;
      padding: 12px 14px;
      box-shadow: 0 14px 32px rgba(79, 70, 229, 0.25);
      display: grid;
      gap: 6px;
      opacity: 0;
      transform: translateY(-6px);
      pointer-events: none;
      transition: opacity 150ms ease, transform 150ms ease;
      z-index: 20;
    }

    .dock-tooltip::after {
      content: '';
      position: absolute;
      left: 14px;
      bottom: -8px;
      border-width: 8px 8px 0;
      border-style: solid;
      border-color: rgba(148, 163, 184, 0.5) transparent transparent transparent;
      filter: drop-shadow(0 2px 4px rgba(15, 23, 42, 0.3));
    }

    .dock-tooltip strong { font-size: 14px; letter-spacing: 0.2px; }

    .dock-tooltip.show {
      opacity: 1;
      transform: translateY(0);
      pointer-events: auto;
    }

    .rack-bar {
      display: flex;
      gap: 6px;
      align-items: center;
      flex-wrap: nowrap;
      padding: 8px 10px;
      background: radial-gradient(circle at 10% 10%, rgba(236, 254, 255, 0.18), transparent 40%),
        linear-gradient(135deg, rgba(14, 165, 233, 0.18), rgba(99, 102, 241, 0.2));
      border: 1px solid rgba(148, 163, 184, 0.5);
      border-radius: 12px;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.14), 0 10px 22px rgba(79, 70, 229, 0.2);
      justify-content: center;
      min-height: 72px;
      overflow-x: auto;
      scrollbar-width: thin;
      grid-area: rack;
    }

    .rack-actions {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 0;
      grid-area: actions;
    }

    .rack-actions .btn { flex: 1 1 120px; min-width: 0; }

    .rack-shuffle {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 12px;
      background: linear-gradient(135deg, #0ea5e9, #6366f1);
      color: #fff;
      border-color: #0ea5e9;
      box-shadow: 0 12px 24px rgba(14, 165, 233, 0.28);
    }

    .rack-shuffle:hover { transform: translateY(-1px); }

    .rack-tile {
      width: var(--tile-size);
      height: var(--tile-size);
      position: relative;
      display: grid;
      align-items: center;
      justify-items: center;
      grid-template-areas: "stack";
      background: var(--tile-wood);
      border-radius: 5px;
      border: 1px solid var(--tile-wood-border);
      box-shadow: 0 6px 16px rgba(15, 23, 42, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
      color: #0f172a;
      font-weight: 800;
    }

    .rack-tile .letter,
    .rack-tile .value {
      grid-area: stack;
    }

    .rack-tile .letter {
      font-size: 22px;
      letter-spacing: 0.3px;
      line-height: 1;
    }

    .rack-tile .value {
      align-self: end;
      justify-self: end;
      margin: 0 4px 4px 0;
      font-size: 11px;
      font-weight: 700;
      line-height: 1;
    }

    .message {
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #f8fafc;
      color: var(--muted);
      font-weight: 600;
    }
    .message.waiting {
      border-color: #bfdbfe;
      background: #eff6ff;
      color: #1d4ed8;
      animation: pulse 1.4s ease-in-out infinite;
    }

    .message.error { border-color: #fecdd3; background: #fff1f2; color: #9f1239; }
    .message.success { border-color: #bbf7d0; background: #f0fdf4; color: #166534; }

    .cell.invalid {
      outline: 2px solid #ef4444;
      outline-offset: -2px;
      box-shadow: inset 0 0 0 2px #fee2e2;
    }

    .cell[data-tooltip]:hover::before {
      content: attr(data-tooltip);
      position: absolute;
      bottom: calc(100% + 6px);
      left: 50%;
      transform: translateX(-50%);
      background: #0f172a;
      color: #fff;
      padding: 6px 8px;
      border-radius: 8px;
      white-space: nowrap;
      font-size: 12px;
      z-index: 5;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.18);
    }

    .cell[data-tooltip]:hover::after { display: none; }

    .score-line { display: flex; align-items: center; gap: 6px; }

    .board-grid .cell.drag-target {
      border-color: #6366f1;
      box-shadow: inset 0 0 0 2px #c7d2fe;
    }

    .actions {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 10px;
    }

    .turn-panel {
      display: grid;
      gap: 12px;
    }

    .status-strip {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      justify-content: space-between;
    }

    .pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 12px;
      border-radius: 999px;
      background: #f8fafc;
      border: 1px solid var(--border);
      font-weight: 700;
      color: var(--ink);
    }

    .pill strong { font-size: 15px; }

    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
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

    .btn.danger {
      background: #be123c;
      border-color: #fecdd3;
      color: #fff;
      box-shadow: 0 12px 30px rgba(190, 18, 60, 0.25);
    }

    .btn[disabled] {
      opacity: 0.5;
      cursor: not-allowed;
      box-shadow: none;
    }

    .sessions-card {
      display: grid;
      gap: 12px;
      margin: 18px auto 0;
      width: min(1200px, 100%);
    }

    .session-header {
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      align-items: start;
    }

    .btn.small { padding: 10px 12px; font-size: 14px; }

    .btn.ghost {
      background: #fff;
      color: var(--ink);
      border-style: dashed;
    }

    .btn.ghost.danger {
      color: #b91c1c;
      border-color: #fecdd3;
      background: #fff1f2;
      box-shadow: none;
    }

    .lobby-card {
      margin-top: 18px;
      padding: 18px;
      border: 1px solid var(--border);
      border-radius: 14px;
      background: var(--card);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
    }

    .turnorder-panel {
      margin-top: 14px;
      padding: 14px;
      border: 1px dashed var(--border);
      border-radius: 12px;
      background: #f8fafc;
    }

    .turnorder-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .turnorder-label { margin: 0; color: var(--muted); }

    .turnorder-log {
      margin-top: 10px;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: #fff;
      max-height: 220px;
      overflow: auto;
    }

    .turnorder-log .entry {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      gap: 10px;
      align-items: center;
    }

    .turnorder-log .entry:last-child { border-bottom: none; }

    .turnorder-result {
      margin-top: 12px;
      padding: 12px;
      border-radius: 10px;
      background: #eef2ff;
      color: #312e81;
      font-weight: 700;
    }

    .turnorder-result strong { font-size: 15px; }

    .lobby-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .lobby-meta {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
      color: var(--muted);
    }

    .lobby-roster {
      display: grid;
      gap: 10px;
      margin-top: 14px;
    }

    .lobby-player {
      display: grid;
      grid-template-columns: auto 1fr auto;
      gap: 10px;
      align-items: center;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: #f8fafc;
    }

    .lobby-order {
      width: 30px;
      height: 30px;
      border-radius: 8px;
      background: #e2e8f0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      color: var(--muted);
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 8px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 12px;
    }

    .badge.host {
      background: rgba(99, 102, 241, 0.12);
      color: var(--accent-strong);
      border: 1px solid rgba(99, 102, 241, 0.24);
    }

    .badge.self {
      background: rgba(15, 23, 42, 0.08);
      color: var(--ink);
      border: 1px solid rgba(15, 23, 42, 0.12);
    }

    .badge.admin {
      background: rgba(239, 68, 68, 0.12);
      color: #b91c1c;
      border: 1px solid rgba(239, 68, 68, 0.28);
    }

    .lobby-actions {
      margin-top: 12px;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
    }

    .lede.small { font-size: 15px; }

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

    .rules-btn {
      justify-self: start;
      width: fit-content;
      cursor: pointer;
    }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: radial-gradient(circle at 20% 20%, rgba(99, 102, 241, 0.16), rgba(15, 23, 42, 0.28)), rgba(15, 23, 42, 0.36);
      backdrop-filter: blur(3px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 18px;
      z-index: 10;
      opacity: 0;
      pointer-events: none;
      transition: opacity 240ms ease;
    }

    .modal-backdrop.active {
      opacity: 1;
      pointer-events: auto;
    }

    .modal {
      background: #fff;
      max-width: 720px;
      width: min(720px, 100%);
      max-height: min(880px, calc(100vh - 32px));
      border-radius: 16px;
      box-shadow: 0 28px 60px rgba(15, 23, 42, 0.24);
      border: 1px solid #e2e8f0;
      padding: 18px 18px 20px;
      display: grid;
      gap: 10px;
      overflow: hidden;
      transform: translateY(10px) scale(0.98);
      opacity: 0;
      transition: transform 260ms cubic-bezier(0.22, 1, 0.36, 1), opacity 200ms ease;
    }

    .modal-backdrop.active .modal {
      transform: translateY(0) scale(1);
      opacity: 1;
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }

    .modal h3 { margin: 0; }

    .modal-close {
      background: transparent;
      border: none;
      font-size: 20px;
      cursor: pointer;
      color: var(--muted);
    }

    .rules-list {
      padding-left: 18px;
      margin: 0;
      display: grid;
      gap: 8px;
      color: var(--muted);
    }

    .rules-highlight {
      background: #eef2ff;
      color: #312e81;
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid #c7d2fe;
      font-weight: 600;
    }

    .modal-footer-note {
      margin: 0;
      color: var(--muted);
      font-size: 13px;
    }

    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .ai-status {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 12px;
      padding: 14px 16px;
      border-radius: 14px;
      background: radial-gradient(circle at 20% 30%, rgba(14, 165, 233, 0.16), rgba(99, 102, 241, 0.08)),
        linear-gradient(135deg, rgba(14, 165, 233, 0.08), rgba(99, 102, 241, 0.08));
      border: 1px solid #cbd5e1;
      font-weight: 600;
      color: #0f172a;
      align-items: center;
    }

    .ai-status.hidden { display: none; }

    .ai-status.ai-complete {
      background: #f8fafc;
    }

    .ai-visual {
      width: 140px;
      height: 140px;
      position: relative;
      display: grid;
      place-items: center;
    }

    .ai-visual.hidden { display: none; }

    .ai-orbital {
      position: absolute;
      inset: 0;
      border-radius: 50%;
      border: 1px dashed rgba(99, 102, 241, 0.35);
      animation: orbit 8s linear infinite;
    }

    .ai-orbital:nth-child(2) { inset: 10px; animation-duration: 6.5s; animation-direction: reverse; }
    .ai-orbital:nth-child(3) { inset: 20px; animation-duration: 5.2s; filter: hue-rotate(40deg); }

    .ai-orbital::after {
      content: '';
      position: absolute;
      top: -6px;
      left: 50%;
      transform: translateX(-50%);
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: linear-gradient(135deg, #22d3ee, #6366f1);
      box-shadow: 0 8px 18px rgba(99, 102, 241, 0.4);
    }

    .ai-core {
      width: 78px;
      height: 78px;
      border-radius: 18px;
      background: conic-gradient(from 45deg, rgba(14, 165, 233, 0.16), rgba(99, 102, 241, 0.38), rgba(14, 165, 233, 0.16));
      border: 1px solid rgba(99, 102, 241, 0.3);
      box-shadow: 0 12px 26px rgba(15, 23, 42, 0.12);
      display: grid;
      place-items: center;
      position: relative;
      overflow: hidden;
      animation: breathe 2.4s ease-in-out infinite;
    }

    .ai-core::before {
      content: '';
      position: absolute;
      inset: -40%;
      background: radial-gradient(circle at 40% 40%, rgba(255, 255, 255, 0.6), transparent 46%),
        radial-gradient(circle at 65% 65%, rgba(255, 255, 255, 0.45), transparent 40%);
      filter: blur(12px);
      animation: shimmer 2.1s infinite ease-in-out;
    }

    .ai-core span {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #0f172a;
      position: relative;
      z-index: 1;
    }

    .ai-copy { display: grid; gap: 8px; }

    .ai-step { font-size: 15px; }

    .ai-dots {
      display: inline-flex;
      gap: 6px;
      align-items: center;
    }

    .ai-dots span {
      width: 10px;
      height: 10px;
      background: linear-gradient(135deg, #22d3ee, #6366f1);
      border-radius: 50%;
      animation: pulse 1.1s infinite ease-in-out;
      box-shadow: 0 8px 16px rgba(14, 165, 233, 0.3);
    }

    .ai-dots span:nth-child(2) { animation-delay: 0.16s; }
    .ai-dots span:nth-child(3) { animation-delay: 0.32s; }

    @keyframes pulse {
      0%, 100% { opacity: 0.2; transform: translateY(0); }
      50% { opacity: 1; transform: translateY(-2px); }
    }

    @keyframes orbit {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    @keyframes shimmer {
      0% { transform: translateX(-140%); }
      50% { transform: translateX(20%); }
      100% { transform: translateX(140%); }
    }

    @keyframes breathe {
      0%, 100% { opacity: 0.6; }
      50% { opacity: 1; }
    }

    .ai-list {
      display: grid;
      gap: 8px;
      margin: 0;
      padding: 0 4px 0 0;
      list-style: none;
      max-height: 420px;
      overflow-y: auto;
      scrollbar-width: thin;
    }

    .ai-list::-webkit-scrollbar {
      width: 8px;
    }

    .ai-list::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 999px;
    }

    .ai-card {
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 12px;
      background: #fff;
      box-shadow: 0 12px 26px rgba(15, 23, 42, 0.08);
      display: grid;
      gap: 4px;
      cursor: pointer;
      transition: transform 150ms ease, box-shadow 150ms ease, border-color 150ms ease;
    }

    .ai-card:hover {
      transform: translateY(-2px);
      border-color: #cbd5e1;
      box-shadow: 0 16px 30px rgba(14, 165, 233, 0.16);
    }

    .ai-card h4 {
      margin: 0;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 16px;
    }

    .ai-meta { color: var(--muted); margin: 0; font-size: 13px; }

    body.modal-open { overflow: hidden; }

    .app-shell {
      width: 100%;
      max-width: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 12px;
      min-height: calc(100vh - var(--top-dock-height) - var(--bottom-dock-height));
      height: calc(100vh - var(--top-dock-height) - var(--bottom-dock-height));
      grid-template-rows: 1fr;
      grid-template-columns: 1fr;
      justify-items: stretch;
      align-items: stretch;
    }

    .board-viewport {
      position: relative;
      width: 100%;
      margin: 0;
      overflow: hidden;
      touch-action: none;
      cursor: grab;
      background: linear-gradient(135deg, rgba(226, 232, 240, 0.35), rgba(226, 232, 240, 0.15));
      border-radius: 16px;
      border: 1px solid rgba(226, 232, 240, 0.8);
      padding: 8px;
      overscroll-behavior: contain;
      min-height: 320px;
      height: calc(100vh - var(--top-dock-height) - var(--bottom-dock-height));
      max-height: calc(100vh - var(--top-dock-height) - var(--bottom-dock-height) + 24px);
    }

    .board-viewport.dragging { cursor: grabbing; }

    .board-scale {
      position: absolute;
      inset: 0;
      transform-origin: center;
      will-change: transform;
      display: block;
    }

    .board-frame {
      position: absolute;
      top: 0;
      left: 0;
      display: grid;
      place-items: center;
      width: max-content;
      height: max-content;
      max-width: 100%;
      max-height: 100%;
    }

    .board-chrome {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--border);
      box-shadow: var(--glow);
      padding: 12px 12px 10px;
      display: grid;
      gap: 12px;
      justify-items: center;
      width: max-content;
      max-width: 100%;
      max-height: 100%;
    }

    .board-chrome.locked {
      pointer-events: none;
      opacity: 0.85;
      filter: saturate(0.7);
    }

    .board-toolbar {
      position: absolute;
      top: 12px;
      right: 12px;
      display: inline-flex;
      gap: 8px;
      align-items: center;
      background: rgba(15, 23, 42, 0.5);
      color: #e2e8f0;
      padding: 8px 10px;
      border-radius: 14px;
      border: 1px solid rgba(255, 255, 255, 0.14);
      box-shadow: 0 14px 34px rgba(15, 23, 42, 0.28);
      backdrop-filter: blur(10px);
      z-index: 40;
      transition: opacity 140ms ease, transform 140ms ease;
    }

    .board-toolbar.collapsed {
      gap: 0;
      padding: 6px 8px;
      transform: translateY(-2px);
    }

    .board-toolbar .toolbar-buttons { display: inline-flex; gap: 6px; align-items: center; }
    .board-toolbar.collapsed .toolbar-buttons { display: none; }

    .toolbar-btn {
      border: 1px solid rgba(226, 232, 240, 0.8);
      background: rgba(255, 255, 255, 0.12);
      color: #f8fafc;
      border-radius: 10px;
      padding: 6px 8px;
      font-weight: 800;
      letter-spacing: 0.2px;
      cursor: pointer;
      transition: transform 120ms ease, background 120ms ease, border-color 120ms ease;
      min-width: 36px;
      line-height: 1;
    }

    .toolbar-btn:hover { transform: translateY(-1px); background: rgba(255, 255, 255, 0.18); }
    .toolbar-btn:active { transform: translateY(0); background: rgba(255, 255, 255, 0.24); }

    .toolbar-toggle {
      border: 1px solid rgba(226, 232, 240, 0.65);
      background: rgba(15, 23, 42, 0.6);
      color: #e2e8f0;
      border-radius: 10px;
      padding: 6px 10px;
      font-weight: 700;
      cursor: pointer;
    }

    .toolbar-toggle:hover { background: rgba(15, 23, 42, 0.72); }

    .hud-dock {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 900;
      background: linear-gradient(135deg, rgba(15, 23, 42, 0.92), rgba(30, 41, 59, 0.9));
      border-bottom: 1px solid #0ea5e9;
      box-shadow: 0 14px 30px rgba(14, 165, 233, 0.18);
      backdrop-filter: blur(12px);
    }

    .hud-inner {
      width: min(1200px, 100%);
      margin: 0 auto;
      padding: 8px 14px 8px;
      display: grid;
      grid-template-columns: auto 1fr auto;
      align-items: center;
      gap: 10px;
    }

    .hud-right {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      min-width: 0;
      flex-wrap: nowrap;
    }

    .hud-meta { display: flex; flex-wrap: nowrap; gap: 8px; align-items: center; justify-content: flex-end; }
    .hud-menu { flex-shrink: 0; }
    .brand { order: 1; }
    .hud-right { order: 3; }

    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .score-strip {
      display: inline-flex;
      gap: 8px;
      padding: 6px 10px;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 14px;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.03);
      overflow-x: auto;
      scrollbar-width: thin;
      max-width: min(640px, 100%);
    }

    .score-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 10px;
      background: rgba(15, 23, 42, 0.35);
      border-radius: 12px;
      color: #e2e8f0;
      border: 1px solid rgba(255, 255, 255, 0.08);
      min-width: 0;
      flex: 0 0 auto;
    }

    .score-chip.leader { border-color: #22c55e; box-shadow: 0 6px 14px rgba(34, 197, 94, 0.3); }
    .score-chip .avatar { width: 32px; height: 32px; border-radius: 10px; background: linear-gradient(135deg, #4f46e5, #06b6d4); display: grid; place-items: center; font-weight: 800; color: #fff; }
    .score-chip .meta { display: grid; gap: 2px; min-width: 0; }
    .score-chip .meta strong { font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .score-chip .meta span { font-size: 12px; color: #cbd5e1; }

    .brand-mark {
      width: 40px;
      height: 40px;
      border-radius: 12px;
      background: linear-gradient(135deg, #4f46e5, #22c55e);
      display: grid;
      place-items: center;
      color: #fff;
      font-weight: 900;
      letter-spacing: 0.4px;
      box-shadow: 0 12px 24px rgba(79, 70, 229, 0.2);
    }

    .hud-text { display: grid; gap: 3px; }

    .app-title {
      margin: 0;
      font-size: clamp(22px, 4vw, 30px);
      color: #e2e8f0;
    }

    .hud-eyebrow {
      margin: 0;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.08);
      color: #c7d2fe;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-size: 11px;
      width: fit-content;
    }

    .hud-meta {
      display: inline-flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }

    .hud-menu {
      position: relative;
    }

    .menu-toggle {
      padding: 9px 13px;
      border-radius: 12px;
      border: 1px solid rgba(148, 163, 184, 0.5);
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(148, 163, 184, 0.08));
      color: #e2e8f0;
      font-weight: 800;
      letter-spacing: 0.2px;
      display: inline-flex;
      gap: 8px;
      align-items: center;
      backdrop-filter: blur(6px);
      box-shadow: 0 10px 30px rgba(14, 165, 233, 0.18);
      cursor: pointer;
      transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
    }

    .menu-toggle:hover {
      transform: translateY(-1px);
      border-color: rgba(125, 211, 252, 0.8);
      box-shadow: 0 14px 36px rgba(14, 165, 233, 0.24);
    }

    .menu-toggle .chevron {
      display: inline-block;
      transition: transform 150ms ease;
    }

    .hud-menu.open .menu-toggle .chevron { transform: rotate(180deg); }

    .menu-panel {
      position: absolute;
      right: 0;
      top: calc(100% + 10px);
      background: rgba(15, 23, 42, 0.95);
      border: 1px solid rgba(148, 163, 184, 0.4);
      border-radius: 14px;
      box-shadow: 0 18px 40px rgba(15, 23, 42, 0.32);
      padding: 10px;
      min-width: 190px;
      display: grid;
      gap: 8px;
      opacity: 0;
      pointer-events: none;
      transform: translateY(-6px);
      transition: opacity 150ms ease, transform 150ms ease;
      z-index: 2;
    }

    .hud-menu.open .menu-panel {
      opacity: 1;
      pointer-events: auto;
      transform: translateY(0);
    }

    .menu-item {
      background: linear-gradient(135deg, rgba(59, 130, 246, 0.18), rgba(14, 165, 233, 0.18));
      border: 1px solid rgba(148, 163, 184, 0.5);
      border-radius: 12px;
      color: #e2e8f0;
      padding: 10px 12px;
      text-align: left;
      font-weight: 700;
      cursor: pointer;
      transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
    }

    .menu-item:hover {
      transform: translateY(-1px);
      border-color: rgba(125, 211, 252, 0.8);
      box-shadow: 0 12px 28px rgba(14, 165, 233, 0.28);
    }

    .menu-item.danger {
      background: linear-gradient(135deg, rgba(248, 113, 113, 0.2), rgba(239, 68, 68, 0.18));
      border-color: rgba(248, 113, 113, 0.8);
      color: #fee2e2;
    }

    .hud-pill {
      background: linear-gradient(135deg, rgba(14, 165, 233, 0.2), rgba(99, 102, 241, 0.2));
      border: 1px solid rgba(148, 163, 184, 0.35);
      color: #e2e8f0;
      border-radius: 12px;
      padding: 7px 10px;
      display: inline-flex;
      gap: 7px;
      align-items: center;
      font-weight: 700;
      box-shadow: 0 12px 26px rgba(14, 165, 233, 0.18);
    }

      .board-preview {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.06), rgba(16, 185, 129, 0.06));
        border-radius: var(--radius);
        padding: 12px;
        border: 1px dashed #cbd5e1;
        overflow: hidden;
        width: min(1100px, 100%);
        display: flex;
        justify-content: center;
      }

    .turn-dock {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: linear-gradient(135deg, rgba(15, 23, 42, 0.94), rgba(79, 70, 229, 0.9));
      backdrop-filter: blur(12px);
      border-top: 1px solid #0ea5e9;
      box-shadow: 0 -18px 38px rgba(79, 70, 229, 0.24);
      z-index: 800;
    }

    .dock-inner {
      width: min(1200px, 100%);
      margin: 0 auto;
      padding: 10px 12px 12px;
      display: grid;
      gap: 10px;
    }

    .dock-row {
      display: grid;
      grid-template-columns: auto 1fr auto;
      grid-template-areas: 'cta rack ai';
      align-items: center;
      gap: 10px;
    }

    .dock-cta {
      display: flex;
      justify-content: center;
      align-items: center;
      min-width: 180px;
      grid-area: cta;
    }

    .dock-ai {
      grid-area: ai;
      display: flex;
      justify-content: flex-end;
      min-width: 0;
    }

    .ai-cta {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: linear-gradient(135deg, #f97316, #f43f5e);
      color: #fff;
      border-color: #ea580c;
      box-shadow: 0 18px 38px rgba(244, 63, 94, 0.32), inset 0 1px 0 rgba(255, 255, 255, 0.22);
      font-weight: 800;
      letter-spacing: 0.2px;
      padding-inline: 12px 14px;
      border-radius: 12px;
      margin-left: 0;
      flex-shrink: 0;
      justify-self: start;
    }

    .ai-cta.disabled,
    .ai-cta:disabled {
      background: linear-gradient(135deg, #cbd5e1, #94a3b8);
      border-color: #94a3b8;
      box-shadow: none;
      cursor: not-allowed;
      transform: none;
    }

    .ai-cta:hover {
      background: linear-gradient(135deg, #f43f5e, #e11d48);
      transform: translateY(-1px);
      box-shadow: 0 20px 42px rgba(225, 29, 72, 0.36);
    }

    .ai-icon {
      width: 24px;
      height: 24px;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.16);
      display: grid;
      place-items: center;
      font-size: 14px;
      line-height: 1;
    }

    .turn-toggle {
      position: relative;
      overflow: hidden;
      min-width: clamp(240px, 52vw, 420px);
      padding: 12px 20px;
      border-radius: 14px;
      font-size: 19px;
      letter-spacing: 0.3px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      transition: transform 150ms ease, box-shadow 150ms ease;
      isolation: isolate;
    }

    .turn-toggle::before,
    .turn-toggle::after {
      content: "";
      position: absolute;
      inset: -2px;
      z-index: 0;
      pointer-events: none;
    }

    .turn-toggle::before {
      background: radial-gradient(circle at 20% 20%, rgba(255, 255, 255, 0.32), transparent 45%),
        radial-gradient(circle at 80% 0%, rgba(255, 255, 255, 0.18), transparent 45%);
      opacity: 0.75;
      animation: breathe 3s ease-in-out infinite;
    }

    .turn-toggle::after {
      background: linear-gradient(120deg, transparent 10%, rgba(255, 255, 255, 0.6) 40%, transparent 70%);
      transform: translateX(-120%);
      animation: shimmer 2.6s ease-in-out infinite;
      mix-blend-mode: screen;
      opacity: 0.7;
    }

    .turn-toggle:hover { transform: translateY(-2px); }

    .turn-label {
      display: grid;
      gap: 4px;
      position: relative;
      z-index: 1;
      text-align: center;
    }

    .turn-title { font-size: clamp(18px, 3vw, 20px); }

    .turn-subtitle {
      font-size: 13px;
      color: rgba(255, 255, 255, 0.8);
      letter-spacing: 0;
    }

    .turn-toggle.start {
      background: #16a34a;
      border-color: #15803d;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.16), 0 10px 25px rgba(22, 163, 74, 0.35);
    }

    .turn-toggle.stop {
      background: #dc2626;
      border-color: #b91c1c;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.16), 0 10px 25px rgba(220, 38, 38, 0.35);
    }

    .btn.ghost {
      background: #fff;
      color: var(--ink);
    }

    .message {
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #f8fafc;
      color: var(--muted);
      font-weight: 600;
      width: 100%;
    }

    .message.waiting {
      background: #eef2ff;
      border-color: #c7d2fe;
      color: #4338ca;
      animation: pulseNotice 1.6s ease-in-out infinite;
    }

    @keyframes pulseNotice {
      0%, 100% { opacity: 0.82; }
      50% { opacity: 1; }
    }

    @media (max-width: 1100px) {
      .dock-row { grid-template-columns: 1fr; grid-template-areas: 'rack' 'cta' 'ai'; }
      .dock-cta { justify-content: stretch; }
      .dock-cta .turn-toggle { width: 100%; }
      .dock-ai { justify-content: stretch; }
      .dock-ai .ai-cta { width: 100%; justify-content: center; }
      .rack-wrap { grid-template-areas: 'help rack' 'actions actions'; grid-template-columns: auto 1fr; gap: 8px; }
      .rack-actions { justify-content: flex-start; }
    }


    @media (min-width: 900px) {
      body { padding: var(--top-dock-height) 0 var(--bottom-dock-height); }
      .grid { grid-template-columns: 2fr 1fr; }
      .grid .card:first-child { grid-column: span 2; }
    }

    @media (max-width: 720px) {
      :root {
        --top-dock-height: 74px;
        --bottom-dock-height: 104px;
        --cell-size: clamp(34px, 7vw + 6px, 44px);
        --cell-gap: 3px;
      }

      body { padding: calc(var(--top-dock-height) + 10px) 0 calc(var(--bottom-dock-height) + 10px); }
      .hud-inner { padding: 8px 12px 8px; gap: 8px; justify-content: center; display: grid; grid-template-columns: auto 1fr auto; align-items: center; }
      .hud-menu { order: 1; }
      .brand { order: 2; flex: 1; justify-content: center; justify-self: center; }
      .hud-right { order: 3; margin-left: 0; flex: 1; justify-content: flex-end; justify-self: end; }
      .hud-menu { justify-self: start; }
      .hud-meta { gap: 6px; }
      .hud-pill { padding: 6px 9px; font-size: 13px; }
      .app-title { font-size: clamp(18px, 5vw, 22px); }
      .hud-eyebrow { padding: 3px 8px; font-size: 10px; }

      .dock-inner { padding: 8px 10px 9px; gap: 10px; }
      .dock-row { grid-template-columns: 1fr; grid-template-areas: 'rack' 'cta' 'ai'; align-items: stretch; }
      .dock-cta { min-width: 0; grid-column: auto; }
      .turn-toggle { width: 100%; min-width: 0; }
      .dock-ai { width: 100%; }
      .ai-cta { width: 100%; margin-left: 0; justify-content: center; }
      .rack-wrap { grid-column: auto; grid-template-areas: 'help rack' 'actions actions'; grid-template-columns: auto 1fr; }

      .tile .letter,
      .rack-tile .letter { font-size: 18px; }

      .tile .value,
      .rack-tile .value { font-size: 10px; margin: 0 3px 3px 0; }

      .board-chrome { padding: 10px 10px 8px; }
      .board-preview { padding: 6px; }
      .board-viewport { padding: 6px; }
    }

    @media (max-width: 599px) {
      .board-preview { padding: 10px; }
      .actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .list-item { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="draw-overlay hidden" id="drawOverlay">
    <div class="draw-panel">
      <div class="draw-actions">
        <div>
          <p class="hud-eyebrow" style="margin:0;">Turn order draw</p>
          <h2 style="margin:4px 0 0;">Reveal your starting tile</h2>
          <p class="hud-text" id="drawStatus" style="margin:6px 0 0; color:#cbd5e1;">Waiting for lobby...</p>
        </div>
        <button type="button" id="drawTileBtn">Spill tiles</button>
      </div>
      <div class="draw-hero">
        <div class="draw-stage">
          <div class="draw-bag" id="drawBag" aria-label="Tile bag" role="button" tabindex="0">
            <img
              src="assets/Tile_Bag_01.png"
              alt="Tile bag"
              class="bag-img"
              id="drawBagImg"
              data-placeholder="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='240' viewBox='0 0 220 240'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' x2='0' y1='0' y2='1'%3E%3Cstop offset='0%25' stop-color='%23e2e8f0'/%3E%3Cstop offset='100%25' stop-color='%23f8fafc'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='220' height='240' rx='28' fill='url(%23g)' stroke='%23cbd5e1' stroke-width='3' stroke-dasharray='8 10'/%3E%3Ctext x='110' y='115' text-anchor='middle' fill='%2394a3b8' font-family='Inter,sans-serif' font-size='16'%3EBag art%3C/text%3E%3Ctext x='110' y='140' text-anchor='middle' fill='%23748396' font-family='Inter,sans-serif' font-size='13'%3E(assets/Tile_Bag_01.png)%3C/text%3E%3C/svg%3E"
            />
            <div class="bag-burst" id="bagBurst"></div>
          </div>
          <div class="draw-spill" id="drawSpill">
            <div class="spill-tiles" id="spillTiles" role="list"></div>
            <p class="hud-text spill-hint" id="spillHint">Click the bag to spill some tiles.</p>
            <div class="draw-meter">
              <div class="meter-track" aria-hidden="true"><span class="meter-fill" id="drawMeterFill"></span></div>
              <p class="hud-text" id="drawMeterText" style="margin:0; color:#94a3b8;">0 of 0 players revealed.</p>
            </div>
            <div class="draw-reveal" aria-live="polite">
              <div class="draw-chip" id="drawCardTop">?</div>
              <div class="draw-reveal-copy">
                <p class="hud-eyebrow" style="margin:0;">Your pick</p>
                <p class="hud-text" id="drawResultCopy" style="margin:4px 0 0; color:#cbd5e1;">Click the bag to spill some tiles.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="draw-grid" style="margin-top:10px;">
        <div>
          <h3 style="margin:0 0 6px;">Players</h3>
          <table>
            <thead><tr><th>Player</th><th>Status</th><th>Tile</th></tr></thead>
            <tbody id="drawTable"></tbody>
          </table>
        </div>
        <div>
          <h3 style="margin:0 0 6px;">Turn order</h3>
          <table>
            <thead><tr><th>#</th><th>Player</th><th>Tile</th></tr></thead>
            <tbody id="orderTable"></tbody>
          </table>
        </div>
      </div>
      <p class="hud-text" style="margin-top:10px; color:#cbd5e1;" id="drawHint">All players must draw to continue.</p>
    </div>
  </div>

  <div class="draw-modal hidden" id="tileModal" aria-live="polite">
    <div class="modal-card">
      <h3 style="margin:0;">Drawing...</h3>
      <div class="draw-ticker" id="drawTicker">?</div>
      <p id="drawResultText" style="color:#cbd5e1; margin:6px 0 0;"></p>
    </div>
  </div>

  <div class="draw-modal hidden" id="startModal" aria-live="polite">
    <div class="modal-card">
      <h3 id="startModalTitle" style="margin:0;">Game starting</h3>
      <p id="startModalMessage" style="color:#cbd5e1; margin:8px 0 4px;"></p>
      <div class="countdown" id="startCountdown">3</div>
    </div>
  </div>

  <div class="draw-modal hidden" id="turnModal" aria-live="assertive">
    <div class="modal-card">
      <h3 style="margin:0;">It's your turn!</h3>
      <p style="color:#cbd5e1; margin:8px 0 0;">Place a word or choose an action below.</p>
    </div>
  </div>

  <div class="draw-modal hidden" id="winnerModal" aria-live="polite">
    <div class="modal-card">
      <h3 id="winnerTitle" style="margin:0;">Game over</h3>
      <div class="winner-copy">
        <div class="winner-face" id="winnerFace" aria-hidden="true">🎉</div>
        <p id="winnerMessage" style="color:#cbd5e1; margin:8px 0 4px;"></p>
        <p id="winnerSecondary" class="hud-text" style="margin:0;">Ready for another round?</p>
      </div>
      <div class="draw-actions" style="margin-top:12px; justify-content:center;">
        <button class="btn rack-shuffle" type="button" id="rematchBtn">🔁 Rematch</button>
        <button class="btn rack-shuffle" type="button" id="closeWinnerBtn">Close</button>
      </div>
      <div class="celebration" id="winnerConfetti" aria-hidden="true"></div>
    </div>
  </div>

  <header class="hud-dock" aria-label="Game status dock">
    <div class="hud-inner">
      <div class="hud-menu" id="hudMenu">
        <button class="menu-toggle" type="button" id="menuToggle" aria-haspopup="true" aria-expanded="false" aria-controls="menuPanel">
          Menu <span class="chevron" aria-hidden="true">▾</span>
        </button>
        <div class="menu-panel" id="menuPanel" role="menu">
          <button class="menu-item" type="button" id="openRules" role="menuitem">Rules</button>
          <button class="menu-item danger" type="button" id="resetBoardBtn" role="menuitem">Reset board</button>
        </div>
      </div>
      <div class="brand">
        <span class="brand-mark" aria-hidden="true">TM</span>
        <div class="hud-text">
          <p class="hud-eyebrow">Live play</p>
          <h1 class="app-title">TileMasterAI</h1>
        </div>
      </div>
      <div class="hud-right">
        <div class="score-strip" id="playerScores" aria-live="polite"></div>
        <div class="hud-meta">
          <span class="hud-pill"><strong>Bag</strong> <span id="bagCount">100</span> tiles</span>
        </div>
      </div>
    </div>
  </header>

  <main class="app-shell" aria-label="TileMasterAI board">
    <div class="board-viewport" id="boardViewport">
      <div class="board-toolbar" id="boardToolbar" aria-label="Board navigation controls">
        <button class="toolbar-toggle" id="boardControlsToggle" type="button" aria-expanded="true">Hide view tools</button>
        <div class="toolbar-buttons" id="boardToolbarButtons">
          <button class="toolbar-btn" type="button" id="zoomOutBtn" aria-label="Zoom out">−</button>
          <button class="toolbar-btn" type="button" id="zoomInBtn" aria-label="Zoom in">+</button>
          <button class="toolbar-btn" type="button" id="centerBoardBtn" aria-label="Center board">Center</button>
          <button class="toolbar-btn" type="button" id="fitBoardBtn" aria-label="Fit board">Fit</button>
          <button class="toolbar-btn" type="button" id="resetViewBtn" aria-label="Reset pan and zoom">Reset</button>
        </div>
      </div>
      <div class="board-scale" id="boardScale">
        <div class="board-frame" id="boardFrame">
          <div class="board-chrome" id="boardChrome">
            <div class="board-preview" aria-label="Game board">
              <div class="board-grid" role="presentation">
              <?php foreach ($premiumBoard as $rowIndex => $row): ?>
                <?php foreach ($row as $colIndex => $cellType):
                  $rowLabel = $rowLabels[$rowIndex];
                  $colLabel = $columnLabels[$colIndex];
                  $tile = $boardModel->tileAtPosition($rowIndex + 1, $colIndex + 1);
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
                  if ($tile) {
                    if ($tile->isBlank()) {
                      $ariaParts[] = $tile->letter() === '?' ? 'blank tile (0 pt)' : "blank tile as {$tile->letter()} (0 pt)";
                    } else {
                      $ariaParts[] = "tile {$tile->letter()} ({$tile->value()} pt)";
                    }
                  }
                  $ariaLabel = implode(' · ', $ariaParts);
                ?>
                <div
                  class="<?php echo $classes; ?>"
                  aria-label="<?php echo $ariaLabel; ?>"
                  data-row="<?php echo $rowIndex; ?>"
                  data-col="<?php echo $colIndex; ?>"
                  data-premium="<?php echo $cellType; ?>"
                  data-center="<?php echo $isCenter ? 'true' : 'false'; ?>"
                >
                  <?php if ($isCenter): ?>
                    <span class="cell-label">★ DW</span>
                  <?php elseif ($cellType !== ''): ?>
                    <span class="cell-label"><?php echo $cellType; ?></span>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              <?php endforeach; ?>
              </div>
            </div>

            <div class="message" id="turnMessage">Start a turn to draw up to seven tiles from the bag.</div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <footer class="turn-dock" aria-label="Turn controls">
    <div class="dock-inner">
      <div class="dock-row">
        <div class="dock-cta">
          <button class="btn turn-toggle start" type="button" id="turnToggleBtn" aria-pressed="false">
            <span class="turn-label">
              <span class="turn-title">Start turn</span>
              <span class="turn-subtitle">Draw tiles and place your word</span>
            </span>
          </button>
        </div>
        <div class="dock-ai">
          <button class="btn ai-cta" type="button" id="aiMovesBtn" disabled aria-disabled="true">
            <span class="ai-icon" aria-hidden="true">🤖</span>
            <span class="ai-text">AI suggested moves</span>
          </button>
        </div>
        <div class="rack-wrap">
          <button class="dock-help" type="button" id="rackHelp" aria-expanded="false" aria-controls="rackHelpTip" aria-label="Rack tips">?</button>
          <div class="rack-bar" aria-label="Rack" id="rack"></div>
          <div class="rack-actions">
            <button class="btn rack-shuffle" type="button" id="shuffleRackBtn" aria-label="Shuffle rack tiles">🔀 <span class="sr-only">Shuffle rack tiles</span><span aria-hidden="true">Shuffle</span></button>
            <button class="btn rack-shuffle" type="button" id="passBtn" aria-label="Pass turn">⏭️ Pass</button>
            <button class="btn rack-shuffle" type="button" id="exchangeBtn" aria-label="Exchange all tiles">🔄 Exchange all</button>
          </div>
          <div class="dock-tooltip" id="rackHelpTip" role="tooltip">
            <strong>Rack tips</strong>
            <span>Drag tiles from the rack onto the board. Blanks turn blue after you set their letter.</span>
            <span>Drag tiles onto the board. Double-click a placed tile to send it back.</span>
          </div>
        </div>
      </div>
    </div>
  </footer>

<script>
    const premiumLayout = <?php echo json_encode($premiumBoard); ?>;
    const tileDistribution = <?php echo json_encode($tileDistribution); ?>;
    const tileValues = Object.fromEntries(Object.entries(tileDistribution).map(([letter, entry]) => [letter, entry.value]));
    const dictionaryUrl = <?php echo json_encode(str_replace(__DIR__ . '/', '', $dictionaryPath)); ?>;
    const serverSuggestions = <?php echo json_encode(array_map($normalizeMove, $moveSuggestions)); ?>;

    document.addEventListener('DOMContentLoaded', () => {
      const BOARD_SIZE = 15;
      const RACK_SIZE = 7;
      const rackEl = document.getElementById('rack');
      const messageEl = document.getElementById('turnMessage');
      const bagCountEl = document.getElementById('bagCount');
      const scoreEl = document.getElementById('scoreTotal');
      const toggleBtn = document.getElementById('turnToggleBtn');
      const turnTitleEl = toggleBtn ? toggleBtn.querySelector('.turn-title') : null;
      const turnSubtitleEl = toggleBtn ? toggleBtn.querySelector('.turn-subtitle') : null;
      const resetBtn = document.getElementById('resetBoardBtn');
      const cells = Array.from(document.querySelectorAll('.board-grid .cell'));
      const aiBtn = document.getElementById('aiMovesBtn');
      const shuffleBtn = document.getElementById('shuffleRackBtn');
      const passBtn = document.getElementById('passBtn');
      const exchangeBtn = document.getElementById('exchangeBtn');
      const aiModal = document.getElementById('aiModal');
      const aiCloseBtn = document.getElementById('closeAi');
      const aiListEl = document.getElementById('aiList');
      const aiStatusEl = document.getElementById('aiStatus');
      const aiAnimationEl = document.getElementById('aiAnimation');
      const aiStepEl = document.getElementById('aiStep');
      const aiSubtextEl = document.getElementById('aiSubtext');
      const playerScoresEl = document.getElementById('playerScores');
      const rulesBtn = document.getElementById('openRules');
      const menuToggle = document.getElementById('menuToggle');
      const menuPanel = document.getElementById('menuPanel');
      const hudMenu = document.getElementById('hudMenu');
      const boardViewport = document.getElementById('boardViewport');
      const boardScaleEl = document.getElementById('boardScale');
      const boardChromeEl = document.getElementById('boardChrome');
      const boardToolbar = document.getElementById('boardToolbar');
      const boardControlsToggle = document.getElementById('boardControlsToggle');
      const zoomInBtn = document.getElementById('zoomInBtn');
      const zoomOutBtn = document.getElementById('zoomOutBtn');
      const fitBoardBtn = document.getElementById('fitBoardBtn');
      const centerBoardBtn = document.getElementById('centerBoardBtn');
      const resetViewBtn = document.getElementById('resetViewBtn');
      const rackHelpBtn = document.getElementById('rackHelp');
      const rackHelpTip = document.getElementById('rackHelpTip');
      const drawOverlay = document.getElementById('drawOverlay');
      const drawStatusEl = document.getElementById('drawStatus');
      const drawHintEl = document.getElementById('drawHint');
      const drawTileBtn = document.getElementById('drawTileBtn');
      const drawTable = document.getElementById('drawTable');
      const orderTable = document.getElementById('orderTable');
      const tileModal = document.getElementById('tileModal');
      const drawTicker = document.getElementById('drawTicker');
      const drawResultText = document.getElementById('drawResultText');
      const drawCardTop = document.getElementById('drawCardTop');
      const drawResultCopy = document.getElementById('drawResultCopy');
      const drawBag = document.getElementById('drawBag');
      const drawBagImg = document.getElementById('drawBagImg');
      const bagBurst = document.getElementById('bagBurst');
      const spillTiles = document.getElementById('spillTiles');
      const spillHint = document.getElementById('spillHint');
      const drawMeterFill = document.getElementById('drawMeterFill');
      const drawMeterText = document.getElementById('drawMeterText');
      const startModal = document.getElementById('startModal');
      const startModalTitle = document.getElementById('startModalTitle');
      const startModalMessage = document.getElementById('startModalMessage');
      const startCountdown = document.getElementById('startCountdown');
      const turnModal = document.getElementById('turnModal');
      const winnerModal = document.getElementById('winnerModal');
      const winnerTitle = document.getElementById('winnerTitle');
      const winnerMessage = document.getElementById('winnerMessage');
      const winnerSecondary = document.getElementById('winnerSecondary');
      const winnerFace = document.getElementById('winnerFace');
      const winnerConfetti = document.getElementById('winnerConfetti');
      const rematchBtn = document.getElementById('rematchBtn');
      const closeWinnerBtn = document.getElementById('closeWinnerBtn');
      const lobbyId = new URLSearchParams(window.location.search).get('lobbyId');
      const state = { user: null, game: null, racks: {}, turnIndex: 0, turnOrder: [], draws: [], players: [], drawAnimationActive: false, lastDrawRevealAt: 0, scores: {}, winnerUserId: null, lastTurnOwner: null, bagSpilled: false };
      let tileId = 0;
      let bag = [];
      let rack = [];
      let board = Array.from({ length: BOARD_SIZE }, () => Array.from({ length: BOARD_SIZE }, () => null));
      let totalScore = 0;
      let firstTurn = true;
      let turnActive = false;
      let dictionaryReady = false;
      let dictionary = new Set();
      let aiStepInterval;
      let aiRevealTimeout;
      let aiAudioInterval;
      let audioCtx;
      let baseScale = 1;
      let userZoom = 1;
      let pinchDistance = null;
      let panX = 0;
      let panY = 0;
      let isPanning = false;
      let panOrigin = { x: 0, y: 0 };
      let panRenderQueued = false;
      let panMomentumFrame = null;
      let panVelocity = { x: 0, y: 0 };
      let lastPanSample = null;
      let touchDragTileId = null;
      let touchDragLastPosition = null;
      let startModalShown = false;
      let winnerShown = false;
      let lastTurnUserId = null;
      let startTimer = null;
      let startDelayTimer = null;
      let celebrationTimer = null;

      const initAudio = () => {
        if (audioCtx) return audioCtx;
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return null;
        audioCtx = new AudioContextClass();
        return audioCtx;
      };

      const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

      const scheduleTone = (ctx, {
        frequency,
        type = 'sine',
        gainValue = 0.06,
        start = 0,
        duration = 0.18,
        attack = 0.01,
        decayEnd = 0.0001,
      }) => {
        if (!frequency || !ctx) return;
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = type;
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, ctx.currentTime + start);
        gain.gain.exponentialRampToValueAtTime(gainValue, ctx.currentTime + start + attack);
        gain.gain.exponentialRampToValueAtTime(decayEnd, ctx.currentTime + start + duration);
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.start(ctx.currentTime + start);
        oscillator.stop(ctx.currentTime + start + duration + 0.05);
      };

      const createSynthFx = ({ steps = [], jitter = 0.05 }) => ({ rate = 1 } = {}) => {
        const ctx = initAudio();
        if (!ctx) return;
        if (ctx.state === 'suspended') {
          ctx.resume();
        }
        const pitchJitter = 1 + (Math.random() * jitter * 2 - jitter);
        const baseRate = clamp(rate, 0.6, 1.6) * pitchJitter;
        steps.forEach(step => {
          scheduleTone(ctx, {
            ...step,
            frequency: step.frequency * baseRate,
          });
        });
      };

      const fx = {
        place: createSynthFx({
          steps: [
            { frequency: 620, duration: 0.12, type: 'triangle', gainValue: 0.07 },
            { frequency: 760, start: 0.08, duration: 0.09, type: 'sine', gainValue: 0.05 },
          ],
          jitter: 0.04,
        }),
        accept: createSynthFx({
          steps: [
            { frequency: 480, duration: 0.22, type: 'sine', gainValue: 0.06 },
            { frequency: 640, start: 0.05, duration: 0.22, type: 'triangle', gainValue: 0.05 },
            { frequency: 800, start: 0.1, duration: 0.2, type: 'square', gainValue: 0.04 },
            { frequency: 960, start: 0.24, duration: 0.12, type: 'triangle', gainValue: 0.045 },
          ],
          jitter: 0.03,
        }),
        reset: createSynthFx({
          steps: [
            { frequency: 360, duration: 0.22, type: 'sawtooth', gainValue: 0.06 },
            { frequency: 240, start: 0.12, duration: 0.2, type: 'triangle', gainValue: 0.05 },
          ],
          jitter: 0.02,
        }),
        invalid: createSynthFx({
          steps: [
            { frequency: 180, duration: 0.16, type: 'square', gainValue: 0.07 },
            { frequency: 320, start: 0.02, duration: 0.12, type: 'triangle', gainValue: 0.045 },
            { frequency: 160, start: 0.1, duration: 0.18, type: 'sawtooth', gainValue: 0.05 },
          ],
          jitter: 0.06,
        }),
        shuffle: createSynthFx({
          steps: [
            { frequency: 380, duration: 0.14, type: 'triangle', gainValue: 0.05 },
            { frequency: 520, start: 0.08, duration: 0.14, type: 'sine', gainValue: 0.05 },
            { frequency: 660, start: 0.16, duration: 0.14, type: 'triangle', gainValue: 0.045 },
            { frequency: 820, start: 0.24, duration: 0.12, type: 'square', gainValue: 0.04 },
          ],
          jitter: 0.05,
        }),
        spill: createSynthFx({
          steps: [
            { frequency: 240, duration: 0.1, type: 'triangle', gainValue: 0.09 },
            { frequency: 180, start: 0.04, duration: 0.16, type: 'sawtooth', gainValue: 0.07 },
            { frequency: 520, start: 0.12, duration: 0.12, type: 'sine', gainValue: 0.05 },
            { frequency: 320, start: 0.2, duration: 0.16, type: 'triangle', gainValue: 0.05 },
          ],
          jitter: 0.08,
        }),
        turnBell: createSynthFx({
          steps: [
            { frequency: 660, duration: 0.22, type: 'triangle', gainValue: 0.08 },
            { frequency: 880, start: 0.05, duration: 0.2, type: 'sine', gainValue: 0.08 },
          ],
          jitter: 0.02,
        }),
        victory: createSynthFx({
          steps: [
            { frequency: 523, duration: 0.16, type: 'triangle', gainValue: 0.08 },
            { frequency: 659, start: 0.08, duration: 0.16, type: 'triangle', gainValue: 0.08 },
            { frequency: 784, start: 0.16, duration: 0.16, type: 'sine', gainValue: 0.07 },
          ],
          jitter: 0.04,
        }),
        champion: createSynthFx({
          steps: [
            { frequency: 392, duration: 0.18, type: 'triangle', gainValue: 0.08 },
            { frequency: 523, start: 0.08, duration: 0.18, type: 'sine', gainValue: 0.07 },
            { frequency: 659, start: 0.16, duration: 0.2, type: 'triangle', gainValue: 0.07 },
            { frequency: 784, start: 0.28, duration: 0.22, type: 'sine', gainValue: 0.06 },
            { frequency: 988, start: 0.42, duration: 0.24, type: 'triangle', gainValue: 0.05 },
          ],
          jitter: 0.01,
        }),
        runner: createSynthFx({
          steps: [
            { frequency: 330, duration: 0.18, type: 'triangle', gainValue: 0.06 },
            { frequency: 392, start: 0.08, duration: 0.18, type: 'sine', gainValue: 0.05 },
            { frequency: 523, start: 0.18, duration: 0.24, type: 'triangle', gainValue: 0.05 },
          ],
          jitter: 0.02,
        }),
      };

      const BAG_IMAGES = { closed: 'assets/Tile_Bag_01.png', open: 'assets/Tile_Bag_02.png' };

      const ensureBagImageReady = () => {
        if (!drawBagImg) return;
        const placeholder = drawBagImg.dataset.placeholder || '';

        drawBagImg.addEventListener('error', () => {
          if (!placeholder || drawBagImg.src === placeholder) return;
          drawBagImg.dataset.fallbackUsed = 'true';
          drawBagImg.src = placeholder;
        });

        if (drawBagImg.complete && drawBagImg.naturalWidth === 0 && placeholder) {
          drawBagImg.dataset.fallbackUsed = 'true';
          drawBagImg.src = placeholder;
        }
      };

      const setBagFrame = (open = false) => {
        if (!drawBagImg) return;

        if (drawBagImg.dataset.fallbackUsed === 'true') {
          const placeholder = drawBagImg.dataset.placeholder || '';
          if (placeholder) {
            drawBagImg.src = placeholder;
          }
        } else {
          drawBagImg.src = open ? BAG_IMAGES.open : BAG_IMAGES.closed;
        }

        drawBag?.classList.toggle('bag-open', open);
      };

      ensureBagImageReady();

      const randomLetterFromPool = (pool = []) => {
        const cleanPool = pool.filter((letter) => letter !== '?');
        if (!cleanPool.length) return String.fromCharCode(65 + Math.floor(Math.random() * 26));
        return cleanPool[Math.floor(Math.random() * cleanPool.length)];
      };

      const runTileAnimation = (spillLetters = []) => new Promise((resolve) => {
        if (!spillTiles) {
          resolve(null);
          return;
        }

        const sourcePool = Array.isArray(state.game?.draw_pool)
          ? state.game.draw_pool
          : Object.keys(tileDistribution);
        const spillCount = Math.min(8, Math.max(5, (state.players?.length || 4) + 2));
        const letters = spillLetters.length
          ? spillLetters.slice(0, spillCount)
          : Array.from({ length: spillCount }, () => randomLetterFromPool(sourcePool));

        state.drawAnimationActive = true;
        state.bagSpilled = true;
        if (tileModal) tileModal.classList.add('hidden');
        setBagFrame(true);
        spillTiles.innerHTML = '';
        if (spillHint) spillHint.textContent = 'Select a tile!';
        if (drawResultCopy) drawResultCopy.textContent = 'Select a tile!';
        if (drawResultText) drawResultText.textContent = 'Tiles spilled—pick one.';
        if (drawTicker) drawTicker.textContent = '…';
        drawOverlay?.classList.remove('hidden');

        if (drawBag) {
          drawBag.classList.remove('bag-pop');
          void drawBag.offsetWidth;
          drawBag.classList.add('bag-pop');
        }
        if (bagBurst) {
          bagBurst.style.animation = 'none';
          void bagBurst.offsetWidth;
          bagBurst.style.animation = 'burst 520ms ease';
        }

        const chips = [];
        letters.forEach((letter) => {
          const chip = document.createElement('button');
          chip.type = 'button';
          chip.className = 'spill-tile';
          chip.dataset.letter = letter;
          const angle = (Math.random() * 24) - 12;
          const tx = (Math.random() * 90) - 45;
          const ty = 16 + Math.random() * 48;
          chip.style.setProperty('--angle', `${angle}deg`);
          chip.style.setProperty('--tx', `${tx}px`);
          chip.style.setProperty('--ty', `${ty}px`);
          chip.setAttribute('aria-label', 'Facedown tile');
          chip.innerHTML = '<span class="tile-back"></span><span class="tile-front">?</span>';
          chip.addEventListener('click', () => revealTile(chip));
          spillTiles.appendChild(chip);
          requestAnimationFrame(() => chip.classList.add('landed'));
          chips.push(chip);
        });

        let resolved = false;

        function revealTile(chip) {
          if (resolved) return;
          resolved = true;
          const letter = chip.dataset.letter || randomLetterFromPool(sourcePool);
          chips.forEach((item) => {
            item.disabled = true;
            const isMatch = item === chip;
            item.classList.toggle('revealed', isMatch);
            if (isMatch) {
              const front = item.querySelector('.tile-front');
              if (front) front.textContent = letter;
              item.setAttribute('aria-label', `Tile ${letter}`);
            } else {
              item.style.opacity = 0.4;
            }
          });

          if (drawCardTop) {
            drawCardTop.textContent = letter;
            drawCardTop.classList.add('revealed');
            setTimeout(() => drawCardTop.classList.remove('revealed'), 520);
          }
          if (drawResultCopy) drawResultCopy.textContent = `You drew ${letter}.`;
          if (drawResultText) drawResultText.textContent = `You drew ${letter}.`;
          if (drawTicker) drawTicker.textContent = letter;
          if (spillHint) spillHint.textContent = 'Great pick! Waiting for the table to finish drawing.';
          state.lastDrawRevealAt = Date.now();
          playFx('place', { rate: 0.94 });

          setTimeout(() => {
            state.drawAnimationActive = false;
            resolve(letter);
          }, 720);
        }
      });

      const playFx = (name, { rate = 1 } = {}) => {
        const effect = fx[name];
        if (!effect) return;
        effect({ rate });
      };

      const stopAiAudioLoop = () => {
        if (aiAudioInterval) {
          clearInterval(aiAudioInterval);
          aiAudioInterval = null;
        }
      };

      const startAiAudioLoop = () => {
        const ctx = initAudio();
        if (!ctx) return;
        stopAiAudioLoop();
        if (ctx.state === 'suspended') {
          ctx.resume();
        }

        aiAudioInterval = setInterval(() => {
          const shimmer = 420 + Math.random() * 120;
          const bass = 240 + Math.random() * 90;
          scheduleTone(ctx, { frequency: shimmer, duration: 0.3, type: 'sine', gainValue: 0.04 });
          scheduleTone(ctx, { frequency: bass, start: 0.14, duration: 0.32, type: 'triangle', gainValue: 0.035 });
        }, 620);
      };

      const measureBoardRect = () => {
        if (!boardChromeEl || !boardScaleEl) return { width: 0, height: 0 };
        const previousTransform = boardScaleEl.style.transform;
        boardScaleEl.style.transform = 'none';
        const rect = boardChromeEl.getBoundingClientRect();
        boardScaleEl.style.transform = previousTransform;
        return rect;
      };

      const getViewportPadding = () => {
        if (!boardViewport) return 0;
        const rect = boardViewport.getBoundingClientRect();
        return Math.max(18, Math.min(rect.width, rect.height) * 0.05);
      };

      const measureBoardCenter = () => {
        if (!boardScaleEl || !boardChromeEl || !boardViewport) {
          return { x: 0, y: 0, width: 0, height: 0 };
        }

        const previousTransform = boardScaleEl.style.transform;
        boardScaleEl.style.transform = 'none';

        const boardRect = boardChromeEl.getBoundingClientRect();
        const viewportRect = boardViewport.getBoundingClientRect();
        const centerCell = boardChromeEl.querySelector('[data-center="true"]');

        let x = boardRect.width / 2;
        let y = boardRect.height / 2;

        if (centerCell instanceof HTMLElement) {
          const cellRect = centerCell.getBoundingClientRect();
          x = cellRect.left - viewportRect.left + cellRect.width / 2;
          y = cellRect.top - viewportRect.top + cellRect.height / 2;
        }

        boardScaleEl.style.transform = previousTransform;

        return { x, y, width: boardRect.width, height: boardRect.height };
      };

      const centerBoard = () => {
        if (!boardViewport || !boardScaleEl) return;
        const finalScale = getFinalScale();
        const viewportRect = boardViewport.getBoundingClientRect();
        const { x, y } = measureBoardCenter();

        panX = viewportRect.width / 2 - x * finalScale;
        panY = viewportRect.height / 2 - y * finalScale;
        applyBoardTransform();
      };

      const getZoomBounds = () => {
        const MIN_ZOOM = Math.max(0.2, (baseScale || 1) * 0.35);
        const MAX_ZOOM = Math.max(2.5, (baseScale || 1) * 3);
        return { MIN_ZOOM, MAX_ZOOM };
      };

      const getFinalScale = () => {
        const { MIN_ZOOM, MAX_ZOOM } = getZoomBounds();
        return clamp(baseScale * userZoom, MIN_ZOOM, MAX_ZOOM);
      };

      const clampPanToViewport = (finalScale) => {
        if (!boardViewport || !boardChromeEl || !boardScaleEl) return;
        const boardRect = measureBoardRect();
        const viewportRect = boardViewport.getBoundingClientRect();
        const padding = getViewportPadding();
        const reach = Math.max(padding * 1.75, 28);

        const scaledWidth = boardRect.width * finalScale;
        const scaledHeight = boardRect.height * finalScale;

        let minPanX = viewportRect.width - scaledWidth - reach;
        let maxPanX = reach;
        let minPanY = viewportRect.height - scaledHeight - reach;
        let maxPanY = reach;

        if (scaledWidth <= viewportRect.width) {
          const centeredX = (viewportRect.width - scaledWidth) / 2;
          minPanX = centeredX - reach;
          maxPanX = centeredX + reach;
        }

        if (scaledHeight <= viewportRect.height) {
          const centeredY = (viewportRect.height - scaledHeight) / 2;
          minPanY = centeredY - reach;
          maxPanY = centeredY + reach;
        }

        panX = clamp(panX, minPanX, maxPanX);
        panY = clamp(panY, minPanY, maxPanY);
      };

      const stopPanMomentum = () => {
        if (panMomentumFrame) {
          cancelAnimationFrame(panMomentumFrame);
          panMomentumFrame = null;
        }
      };

      const applyBoardTransform = () => {
        if (!boardScaleEl) return;
        const finalScale = getFinalScale();
        clampPanToViewport(finalScale);
        boardScaleEl.style.transform = `translate3d(${panX}px, ${panY}px, 0) scale(${finalScale})`;
      };

      const samplePanVelocity = (x, y) => {
        const now = performance.now();
        if (lastPanSample) {
          const deltaTime = Math.max(1, now - lastPanSample.time);
          panVelocity = {
            x: (x - lastPanSample.x) / deltaTime,
            y: (y - lastPanSample.y) / deltaTime,
          };
        }
        lastPanSample = { x, y, time: now };
      };

      const startPanMomentum = () => {
        stopPanMomentum();
        const decay = 0.92;
        const minVelocity = 0.01;

        const step = () => {
          panVelocity.x *= decay;
          panVelocity.y *= decay;

          if (Math.abs(panVelocity.x) < minVelocity && Math.abs(panVelocity.y) < minVelocity) {
            panMomentumFrame = null;
            return;
          }

          panX += panVelocity.x * 16;
          panY += panVelocity.y * 16;
          applyBoardTransform();
          panMomentumFrame = requestAnimationFrame(step);
        };

        panMomentumFrame = requestAnimationFrame(step);
      };

      const requestBoardRender = () => {
        if (panRenderQueued) return;
        panRenderQueued = true;
        requestAnimationFrame(() => {
          panRenderQueued = false;
          applyBoardTransform();
        });
      };

      const resizeBoardToViewport = ({ resetView = false } = {}) => {
        if (!boardViewport || !boardScaleEl || !boardChromeEl) return;
        const topHeight = document.querySelector('.hud-dock')?.getBoundingClientRect().height || 0;
        const bottomHeight = document.querySelector('.turn-dock')?.getBoundingClientRect().height || 0;
        const availableHeight = Math.max(360, window.innerHeight - topHeight - bottomHeight);

        boardViewport.style.height = `${availableHeight}px`;

        const viewportRect = boardViewport.getBoundingClientRect();
        const boardRect = measureBoardRect();
        const padding = getViewportPadding();
        const heightScale = boardRect.height ? (viewportRect.height - padding * 2) / boardRect.height : 1;
        const widthScale = boardRect.width ? (viewportRect.width - padding * 2) / boardRect.width : 1;

        baseScale = Math.min(heightScale, widthScale);
        if (!Number.isFinite(baseScale) || baseScale <= 0) { baseScale = 1; }

        if (resetView) {
          userZoom = 1;
          centerBoard();
        } else {
          applyBoardTransform();
        }
      };

      const adjustZoom = (factor, pivot = null) => {
        const { MIN_ZOOM, MAX_ZOOM } = getZoomBounds();
        const minFactor = MIN_ZOOM / (baseScale || 1);
        const maxFactor = MAX_ZOOM / (baseScale || 1);
        const nextZoom = clamp(userZoom * factor, minFactor, maxFactor);
        const appliedFactor = nextZoom / userZoom;

        if (pivot && boardViewport) {
          const viewportRect = boardViewport.getBoundingClientRect();
          const originX = pivot.x - viewportRect.left;
          const originY = pivot.y - viewportRect.top;
          panX = originX - (originX - panX) * appliedFactor;
          panY = originY - (originY - panY) * appliedFactor;
        }

        userZoom = nextZoom;
        applyBoardTransform();
      };

      const resetBoardView = () => {
        panX = 0;
        panY = 0;
        userZoom = 1;
        centerBoard();
      };

      const fitBoard = () => {
        resizeBoardToViewport({ resetView: true });
      };

      const toggleBoardControls = () => {
        if (!boardToolbar || !boardControlsToggle) return;
        const collapsed = boardToolbar.classList.toggle('collapsed');
        boardControlsToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        boardControlsToggle.textContent = collapsed ? 'Show view tools' : 'Hide view tools';
      };

      const viewportCenter = () => {
        if (!boardViewport) return null;
        const rect = boardViewport.getBoundingClientRect();
        return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
      };

      const handleWheelZoom = (event) => {
        if (!boardScaleEl || !boardViewport) return;
        if (event.ctrlKey || event.metaKey) return;
        event.preventDefault();
        const delta = -event.deltaY;
        const intensity = event.deltaMode === 1 ? 0.04 : 0.0014;
        const factor = Math.exp(delta * intensity);
        stopPanMomentum();
        adjustZoom(factor, { x: event.clientX, y: event.clientY });
      };

      const touchDistance = (touches) => {
        if (touches.length < 2) return 0;
        const [t1, t2] = touches;
        const dx = t1.clientX - t2.clientX;
        const dy = t1.clientY - t2.clientY;
        return Math.hypot(dx, dy);
      };

      const handleTouchStart = (event) => {
        if (!boardViewport) return;
        if (event.touches.length === 2) {
          isPanning = false;
          boardViewport.classList.remove('dragging');
          pinchDistance = touchDistance(event.touches);
          stopPanMomentum();
          return;
        }

        const [touch] = event.touches;
        const target = event.target;
        if (!touch || (target.closest('.tile') || target.closest('.rack-tile'))) return;

        isPanning = true;
        panOrigin = { x: touch.clientX - panX, y: touch.clientY - panY };
        lastPanSample = null;
        stopPanMomentum();
        boardViewport.classList.add('dragging');
      };

      const handleTouchMove = (event) => {
        if (!boardScaleEl) return;

        if (event.touches.length >= 2) {
          if (pinchDistance === null) return;
          event.preventDefault();
          const newDistance = touchDistance(event.touches);
          if (newDistance > 0) {
            const factor = newDistance / (pinchDistance || newDistance);
            const [t1, t2] = event.touches;
            const pivot = t1 && t2 ? { x: (t1.clientX + t2.clientX) / 2, y: (t1.clientY + t2.clientY) / 2 } : null;
            adjustZoom(factor, pivot);
            pinchDistance = newDistance;
          }
          return;
        }

        if (!isPanning || !event.touches.length) return;
        const [touch] = event.touches;
        if (!touch) return;
        event.preventDefault();
        panX = touch.clientX - panOrigin.x;
        panY = touch.clientY - panOrigin.y;
        samplePanVelocity(touch.clientX, touch.clientY);
        requestBoardRender();
      };

      const handleTouchEnd = () => {
        if (pinchDistance !== null) {
          pinchDistance = null;
        }

        if (isPanning) {
          endBoardPan();
        }
      };

      const startBoardPan = (event) => {
        if (!boardViewport || event.button !== 0) return;
        const target = event.target;
        if (target.closest('.tile') || target.closest('.rack-tile')) return;
        event.preventDefault();
        isPanning = true;
        panOrigin = { x: event.clientX - panX, y: event.clientY - panY };
        lastPanSample = null;
        stopPanMomentum();
        boardViewport.classList.add('dragging');
      };

      const continueBoardPan = (event) => {
        if (!isPanning) return;
        panX = event.clientX - panOrigin.x;
        panY = event.clientY - panOrigin.y;
        samplePanVelocity(event.clientX, event.clientY);
        requestBoardRender();
      };

      const endBoardPan = () => {
        if (!isPanning) return;
        isPanning = false;
        boardViewport.classList.remove('dragging');
        startPanMomentum();
      };

      const buildBag = () => {
        bag = [];
        Object.entries(tileDistribution).forEach(([letter, entry]) => {
          for (let i = 0; i < entry.count; i += 1) {
            bag.push(letter);
          }
        });
      };

      const pickTileFromBag = () => {
        if (!bag.length) return null;
        const [letter] = bag.splice(0, 1);
        return letter;
      };

      const createTile = (letter) => {
        const isBlank = letter === '?';
        const id = `tile-${++tileId}`;
        const tile = {
          id,
          letter,
          assignedLetter: isBlank ? '' : letter,
          isBlank,
          value: isBlank ? 0 : (tileValues[letter] || 0),
          locked: false,
          justPlaced: false,
          invalidReason: ''
        };
        return tile;
      };

      const setMessage = (text, tone = '') => {
        messageEl.textContent = text;
        messageEl.classList.remove('error', 'success');
        if (tone) {
          messageEl.classList.add(tone);
          if (tone === 'error') {
            playFx('invalid', { rate: 0.92 + Math.random() * 0.12 });
          }
        }
      };

      const renderDrawTables = () => {
        if (!drawTable || !orderTable) return;
        const draws = state.game?.draws || state.draws || [];
        const players = state.players || [];
        const myDraw = draws.find((d) => Number(d.user_id) === Number(state.user?.id));

        drawTable.innerHTML = players.map((player) => {
          const entry = draws.find((d) => Number(d.user_id) === Number(player.user_id ?? player.id));
          const isSelf = Number(player.user_id ?? player.id) === Number(state.user?.id);
          const status = entry
            ? (entry.revealed ? `Revealed · ${entry.value} pts` : (isSelf ? 'Spilled · pick one' : 'Waiting to reveal'))
            : 'Waiting to draw';
          const tile = entry && entry.revealed ? entry.tile : '—';
          return `<tr><td>${player.username}${isSelf ? ' (you)' : ''}</td><td>${status}</td><td>${tile}</td></tr>`;
        }).join('');

        orderTable.innerHTML = (state.turnOrder || []).map((entry, idx) => {
          const crown = idx === 0 ? '👑 ' : '';
          return `<tr><td>${idx + 1}</td><td>${crown}${entry.username}</td><td>${entry.tile ?? '—'}</td></tr>`;
        }).join('');

        if (drawCardTop) {
          drawCardTop.textContent = myDraw && myDraw.revealed ? myDraw.tile : '？';
        }
      };

      const triggerStartCountdown = () => {
        if (!startModal || !startCountdown || startModalShown) return;
        const first = state.turnOrder?.[0];
        const firstName = first?.username || 'First player';
        if (state.game?.board_state?.length) {
          return;
        }
        startModalShown = true;
        let counter = 3;
        startModalTitle.textContent = 'Game starting';
        const draws = state.game?.draws || [];
        const sorted = [...draws].sort((a, b) => (b.value ?? 0) - (a.value ?? 0) || String(b.tile).localeCompare(String(a.tile)));
        const winnerDraw = draws.find((entry) => Number(entry.user_id) === Number(first?.user_id));
        const runnerUp = sorted[1];
        const reason = winnerDraw
          ? `${firstName} drew ${winnerDraw.tile} (${winnerDraw.value} pts)`
          : `${firstName} has the highest draw`;
        const versus = runnerUp ? `, edging out ${runnerUp.username}'s ${runnerUp.tile}.` : '.';
        startModalMessage.textContent = `${reason}${versus}`;
        startCountdown.textContent = String(counter);
        startModal.classList.remove('hidden');
        startModal.setAttribute('aria-hidden', 'false');

        const tick = () => {
          counter -= 1;
          if (counter <= 0) {
            startModal.classList.add('hidden');
            startModal.setAttribute('aria-hidden', 'true');
            if (isMyTurn() && !turnActive) {
              const started = startTurn();
              if (started) {
                turnActive = true;
                updateTurnButton();
                updateAiButton();
              }
            }
            setTurnMessaging();
            startTimer = null;
            return;
          }
          startCountdown.textContent = String(counter);
          startTimer = setTimeout(tick, 1000);
        };

        startTimer = setTimeout(tick, 1000);
      };

      const showTurnModal = () => {
        if (!turnModal) return;
        turnModal.classList.remove('hidden');
        turnModal.setAttribute('aria-hidden', 'false');
        playFx('turnBell', { rate: 1 });
        setTimeout(() => {
          turnModal.classList.add('hidden');
          turnModal.setAttribute('aria-hidden', 'true');
        }, 1500);
      };

      const clearCelebration = () => {
        if (winnerConfetti) {
          winnerConfetti.innerHTML = '';
        }
        if (celebrationTimer) {
          clearTimeout(celebrationTimer);
          celebrationTimer = null;
        }
      };

      const launchCelebration = (isWinner) => {
        clearCelebration();
        if (!winnerConfetti) return;
        const pieces = isWinner ? 32 : 18;
        for (let i = 0; i < pieces; i += 1) {
          const piece = document.createElement('span');
          piece.className = 'confetti-piece';
          piece.style.left = `${Math.random() * 100}%`;
          piece.style.animationDelay = `${Math.random() * 0.8}s`;
          piece.style.animationDuration = `${1.1 + Math.random() * 0.7}s`;
          winnerConfetti.appendChild(piece);
        }
        celebrationTimer = setTimeout(() => winnerConfetti.innerHTML = '', 2800);
      };

      const showWinnerModal = () => {
        if (!winnerModal || !state.winnerUserId || winnerShown) return;
        const winnerEntry = state.players.find((p) => Number(p.user_id ?? p.id) === Number(state.winnerUserId));
        const winnerName = winnerEntry?.username || 'Winner';
        const myScore = state.scores?.[state.user?.id] ?? 0;
        const topScore = state.scores?.[state.winnerUserId] ?? 0;
        const iAmWinner = Number(state.user?.id) === Number(state.winnerUserId);

        winnerTitle.textContent = `${winnerName} wins!`;
        winnerMessage.textContent = `${winnerName} finished their tiles and scored ${topScore} points. You finished with ${myScore} points.`;
        if (winnerFace) {
          winnerFace.textContent = iAmWinner ? '🏆' : '🤝';
          winnerFace.classList.toggle('runnerup-face', !iAmWinner);
        }
        if (winnerSecondary) {
          winnerSecondary.textContent = iAmWinner
            ? 'Tap rematch to defend your crown!'
            : 'Great effort! Queue up a rematch?';
        }

        winnerModal.classList.remove('hidden');
        winnerModal.setAttribute('aria-hidden', 'false');
        launchCelebration(iAmWinner);
        playFx(iAmWinner ? 'champion' : 'runner', { rate: 1 });
        winnerShown = true;
      };

      const closeWinnerModal = () => {
        if (!winnerModal) return;
        winnerModal.classList.add('hidden');
        winnerModal.setAttribute('aria-hidden', 'true');
        clearCelebration();
      };

      const requestRematch = async () => {
        if (!lobbyId || !rematchBtn) return;
        rematchBtn.disabled = true;
        try {
          const res = await fetch('/api/game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'rematch', lobbyId }),
          });
          const data = await res.json();
          if (!data.success) {
            setMessage(data.message || 'Unable to start a rematch right now.', 'error');
            return;
          }
          state.winnerUserId = null;
          winnerShown = false;
          startModalShown = false;
          turnActive = false;
          hydrateFromGame(data.game);
          setTurnMessaging();
          setMessage('Rematch ready! Redraw turn order or start your next word.', 'success');
          closeWinnerModal();
        } catch (error) {
          setMessage('Unable to start a rematch right now.', 'error');
        } finally {
          rematchBtn.disabled = false;
        }
      };

      const updateDrawUi = () => {
        if (!drawOverlay || !state.user) return;
        const draws = state.game?.draws || state.draws || [];
        const players = state.players || [];
        const myEntry = draws.find((entry) => Number(entry.user_id) === Number(state.user.id));
        const alreadyDrew = Boolean(myEntry);
        const hasRevealed = Boolean(myEntry?.revealed);
        const hasPending = alreadyDrew && !hasRevealed;
        const everyoneDrew = players.length > 0 && draws.length >= players.length;
        const everyoneRevealed = everyoneDrew && draws.every((entry) => entry.revealed);
        const readyToDraw = !hasRevealed && (!everyoneDrew || hasPending);
        renderDrawTables();

        if (drawStatusEl) {
          const leader = state.turnOrder?.[0];
          drawStatusEl.textContent = leader && everyoneRevealed
            ? `${leader.username} starts the game`
            : 'Spill the bag, pick a tile, and reveal to lock turn order.';
        }
        if (drawHintEl) {
          drawHintEl.textContent = hasPending
            ? 'Tap any face-down tile to claim it.'
            : alreadyDrew
              ? 'You revealed—waiting for the table to finish.'
              : 'Click the bag to spill some tiles, then pick one.';
        }
        if (drawTileBtn) {
          drawTileBtn.disabled = hasRevealed || (everyoneDrew && !hasPending);
        }
        if (drawBag) {
          drawBag.classList.toggle('disabled', !readyToDraw);
        }
        if (!state.bagSpilled && readyToDraw) {
          setBagFrame(false);
          if (spillTiles) spillTiles.innerHTML = '';
          if (spillHint) spillHint.textContent = 'Click the bag to spill some tiles.';
          if (drawResultCopy) drawResultCopy.textContent = 'Click the bag to spill some tiles.';
        }

        const revealedCount = draws.filter((entry) => entry.revealed).length;
        const totalPlayers = players.length || 0;
        if (drawMeterFill) {
          const fill = totalPlayers ? Math.min(100, Math.round((revealedCount / totalPlayers) * 100)) : 0;
          drawMeterFill.style.width = `${fill}%`;
        }
        if (drawMeterText) {
          drawMeterText.textContent = totalPlayers
            ? `${revealedCount} of ${totalPlayers} revealed`
            : 'Waiting for players...';
        }

        const readyToStart = everyoneRevealed && state.turnOrder.length > 0;
        drawOverlay.classList.toggle('hidden', readyToStart);

        if (!readyToStart && startDelayTimer) {
          clearTimeout(startDelayTimer);
          startDelayTimer = null;
        }

        const scheduleCountdown = () => {
          if (state.drawAnimationActive) {
            startDelayTimer = setTimeout(scheduleCountdown, 300);
            return;
          }
          const elapsed = state.lastDrawRevealAt ? (Date.now() - state.lastDrawRevealAt) : 0;
          const wait = elapsed >= 1000 ? 0 : 1000 - elapsed;
          startDelayTimer = setTimeout(() => {
            startDelayTimer = null;
            triggerStartCountdown();
          }, wait);
        };

        if (readyToStart && !startModalShown && !startDelayTimer) {
          scheduleCountdown();
        }
      };

      const isMyTurn = () => {
        const current = state.turnOrder[state.turnIndex];
        return current && state.user && Number(current.user_id) === Number(state.user.id);
      };

      const currentTurnName = () => state.turnOrder[state.turnIndex]?.username || 'Opponent';

      const serializeBoard = () => {
        const placements = [];
        for (let r = 0; r < BOARD_SIZE; r += 1) {
          for (let c = 0; c < BOARD_SIZE; c += 1) {
            const tile = board[r][c];
            if (tile) {
              placements.push({
                row: r,
                col: c,
                letter: tile.letter,
                assignedLetter: tile.assignedLetter || '',
                isBlank: tile.isBlank,
              });
            }
          }
        }
        return placements;
      };

      const applyBoardState = (placements = []) => {
        board = Array.from({ length: BOARD_SIZE }, () => Array.from({ length: BOARD_SIZE }, () => null));
        placements.forEach((placement) => {
          const row = Number(placement.row ?? -1);
          const col = Number(placement.col ?? -1);
          if (row < 0 || col < 0 || row >= BOARD_SIZE || col >= BOARD_SIZE) return;
          const tile = createTile((placement.letter || '?').toUpperCase());
          tile.assignedLetter = placement.assignedLetter || '';
          tile.isBlank = !!placement.isBlank;
          tile.locked = true;
          tile.justPlaced = false;
          board[row][col] = tile;
        });
        firstTurn = placements.length === 0;
        renderBoard();
      };

      const applyRackState = () => {
        if (!state.user) return;
        const letters = state.racks[state.user.id] || [];
        rack = letters.map((letter) => {
          const tile = createTile(String(letter).toUpperCase());
          tile.position = { type: 'rack' };
          return tile;
        });
        renderRack();
      };

      const hydrateFromGame = (game) => {
        state.game = game;
        state.turnOrder = game.turn_order || [];
        state.turnIndex = game.current_turn_index || 0;
        state.racks = game.racks || {};
        state.draws = game.draws || [];
        state.draw_pool = game.draw_pool || [];
        state.scores = game.scores || {};
        state.winnerUserId = game.winner_user_id || null;
        if (!state.drawAnimationActive && state.draws.length === 0) {
          state.bagSpilled = false;
          setBagFrame(false);
          if (spillTiles) spillTiles.innerHTML = '';
        }
        bag = Array.isArray(game.bag) ? [...game.bag] : [];
        if (!state.winnerUserId) {
          winnerShown = false;
          if (winnerModal) {
            winnerModal.classList.add('hidden');
            winnerModal.setAttribute('aria-hidden', 'true');
          }
          clearCelebration();
        }
        applyBoardState(game.board_state || []);
        applyRackState();
        totalScore = state.scores?.[state.user?.id] ?? 0;
        if (scoreEl) { scoreEl.textContent = totalScore; }
        updateBagCount();
        updateTurnButton();
        renderPlayerScores();
      };

      const fetchUser = async () => {
        try {
          const res = await fetch('/api/auth.php');
          if (!res.ok) return;
          const data = await res.json();
          state.user = data.user;
        } catch (e) {
          console.error(e);
        }
      };

      const fetchGameState = async (options = { silent: false }) => {
        if (!lobbyId) return;
        if (turnActive && isMyTurn()) return;
        try {
          const previousTurnUser = state.turnOrder[state.turnIndex]?.user_id;
          const res = await fetch(`/api/game.php?lobbyId=${encodeURIComponent(lobbyId)}`);
          const data = await res.json();
          if (!data.success) {
            if (!options.silent) setMessage(data.message || 'Unable to load game', 'error');
            return;
          }
          state.players = data.players || state.players;
          hydrateFromGame(data.game);
          updateDrawUi();
          setTurnMessaging();
          const newCurrent = state.turnOrder[state.turnIndex]?.user_id;
          if (newCurrent && Number(newCurrent) === Number(state.user?.id) && newCurrent !== previousTurnUser) {
            showTurnModal();
          }
        } catch (error) {
          if (!options.silent) setMessage('Unable to reach the game server right now.', 'error');
        }
      };

      const syncGameState = async ({ advanceTurn = false, actingIndex = null, scoreDelta = null } = {}) => {
        if (!lobbyId || !state.user) return;
        const activeIndex = actingIndex !== null ? actingIndex : state.turnIndex;
        state.racks[state.user.id] = rack.map((tile) => (tile.isBlank && tile.assignedLetter)
          ? tile.assignedLetter.toUpperCase()
          : tile.letter.toUpperCase());
        const nextIndex = advanceTurn && state.turnOrder.length
          ? (activeIndex + 1) % state.turnOrder.length
          : activeIndex;

        const res = await fetch('/api/game.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'sync',
            lobbyId,
            board: serializeBoard(),
            racks: state.racks,
            bag,
            turnIndex: nextIndex,
            actingIndex: activeIndex,
            scoreDelta,
          }),
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data.success && data.game) {
          hydrateFromGame(data.game);
          updateDrawUi();
          setTurnMessaging();
        }
      };

      const handleDrawTile = async () => {
        if (!lobbyId) return;
        if (state.drawAnimationActive) return;
        if (drawTileBtn) drawTileBtn.disabled = true;
        if (drawBag) {
          drawBag.classList.add('disabled');
          drawBag.classList.remove('bag-pop');
          void drawBag.offsetWidth;
          drawBag.classList.add('bag-pop');
        }
        playFx('spill', { rate: 0.96 });
        try {
          const res = await fetch('/api/game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'draw', lobbyId }),
          });
            const data = await res.json();
            if (!data.success) {
              setMessage(data.message || 'Unable to draw a tile.', 'error');
              if (drawTileBtn) drawTileBtn.disabled = false;
              if (drawBag) drawBag.classList.remove('disabled');
            state.bagSpilled = false;
            setBagFrame(false);
              return;
            }
          const spill = Array.isArray(data.result?.spill) ? data.result.spill : [];
          const picked = await runTileAnimation(spill);
          if (!picked) {
            throw new Error('No tile selected.');
          }
          await fetch('/api/game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reveal', lobbyId, tile: picked }),
          });
          await fetchGameState();
        } catch (error) {
          setMessage('Unable to reach the draw server right now.', 'error');
          state.bagSpilled = false;
          setBagFrame(false);
        } finally {
          if (drawTileBtn) drawTileBtn.disabled = false;
          if (drawBag) drawBag.classList.remove('disabled');
        }
      };

      const handlePass = async () => {
        if (!isMyTurn()) {
          setMessage('Only the active player can pass.', 'error');
          return;
        }
        try {
          const res = await fetch('/api/game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'pass', lobbyId }),
          });
          const data = await res.json();
          if (!data.success) {
            setMessage(data.message || 'Unable to pass your turn.', 'error');
            return;
          }
          hydrateFromGame(data.game);
          setTurnMessaging();
          setMessage('Turn passed. Waiting for your opponent...', 'success');
        } catch (error) {
          setMessage('Unable to pass right now.', 'error');
        }
      };

      const handleExchange = async () => {
        if (!isMyTurn()) {
          setMessage('Only the active player can exchange tiles.', 'error');
          return;
        }
        returnLooseTilesToRack();
        try {
          const res = await fetch('/api/game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'exchange', lobbyId }),
          });
          const data = await res.json();
          if (!data.success) {
            setMessage(data.message || 'Unable to exchange tiles.', 'error');
            return;
          }
          hydrateFromGame(data.game);
          setTurnMessaging();
          renderRack();
          setMessage('Tiles exchanged. Next player is up.', 'success');
          playFx('shuffle', { rate: 0.9 });
        } catch (error) {
          setMessage('Unable to exchange right now.', 'error');
        }
      };

      const setTurnMessaging = () => {
        if (!messageEl) return;
        if (state.winnerUserId) {
          const winnerEntry = state.players.find((p) => Number(p.user_id ?? p.id) === Number(state.winnerUserId));
          const winnerName = winnerEntry?.username || 'Winner';
          messageEl.textContent = `${winnerName} has won the game.`;
          messageEl.classList.add('waiting');
          toggleBtn?.setAttribute('disabled', 'true');
          if (boardChromeEl) boardChromeEl.classList.add('locked');
          if (rackEl) rackEl.classList.add('locked');
          showWinnerModal();
          return;
        }
        if (!state.turnOrder.length) {
          messageEl.textContent = 'Draw a tile to see who goes first.';
          messageEl.classList.add('waiting');
          if (boardChromeEl) boardChromeEl.classList.add('locked');
          if (rackEl) rackEl.classList.add('locked');
          toggleBtn?.setAttribute('disabled', 'true');
          updateActionButtons();
          return;
        }
        const waiting = !isMyTurn();
        messageEl.classList.toggle('waiting', waiting);
        if (boardChromeEl) boardChromeEl.classList.toggle('locked', waiting);
        if (rackEl) rackEl.classList.toggle('locked', waiting);
        if (waiting) {
          messageEl.textContent = `${currentTurnName()} is playing. Please wait for your turn.`;
          toggleBtn?.setAttribute('disabled', 'true');
        } else {
          toggleBtn?.removeAttribute('disabled');
          messageEl.textContent = turnActive
            ? 'Place tiles and submit your move.'
            : 'Start your turn to draw tiles from the shared bag.';
        }
        updateActionButtons();
      };

      const closeHudMenu = () => {
        if (!hudMenu || !menuToggle) return;
        hudMenu.classList.remove('open');
        menuToggle.setAttribute('aria-expanded', 'false');
      };

      const toggleHudMenu = () => {
        if (!hudMenu || !menuToggle) return;
        const isOpen = hudMenu.classList.toggle('open');
        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      };

      const closeRackHelp = () => {
        if (!rackHelpTip || !rackHelpBtn) return;
        rackHelpTip.classList.remove('show');
        rackHelpBtn.setAttribute('aria-expanded', 'false');
      };

      const toggleRackHelp = () => {
        if (!rackHelpTip || !rackHelpBtn) return;
        const shouldShow = rackHelpTip.classList.toggle('show');
        rackHelpBtn.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');

        if (shouldShow) {
          rackHelpTip.style.left = '0';
          rackHelpTip.style.right = 'auto';
          const tipRect = rackHelpTip.getBoundingClientRect();
          const viewportWidth = document.documentElement.clientWidth;
          if (tipRect.right > viewportWidth - 12) {
            rackHelpTip.style.left = 'auto';
            rackHelpTip.style.right = '0';
          }
        }
      };

      const updateTurnButton = () => {
        if (!toggleBtn) return;
        toggleBtn.classList.toggle('start', !turnActive);
        toggleBtn.classList.toggle('stop', turnActive);
        const waiting = !isMyTurn();
        toggleBtn.disabled = waiting;
        if (turnTitleEl) {
          turnTitleEl.textContent = waiting ? `${currentTurnName()} is up` : (turnActive ? 'Submit move' : 'Start turn');
        }
        if (turnSubtitleEl) {
          turnSubtitleEl.textContent = waiting
            ? 'Please wait for your turn'
            : (turnActive ? 'Lock tiles & score it' : 'Draw tiles and place your word');
        }
        toggleBtn.setAttribute('aria-pressed', turnActive ? 'true' : 'false');
        updateActionButtons();
      };

      const updateAiButton = () => {
        if (!aiBtn) return;
        const disabled = !turnActive;
        aiBtn.disabled = disabled;
        aiBtn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        aiBtn.classList.toggle('disabled', disabled);
      };

      const updateActionButtons = () => {
        const waiting = !isMyTurn() || !!state.winnerUserId;
        [passBtn, exchangeBtn].forEach((btn) => {
          if (!btn) return;
          const disabled = waiting || turnActive;
          btn.disabled = disabled;
          btn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        });
      };

      const updateBagCount = () => {
        bagCountEl.textContent = bag.length;
      };

      const renderPlayerScores = () => {
        if (!playerScoresEl) return;
        const players = (state.turnOrder && state.turnOrder.length) ? state.turnOrder : (state.players || []);
        const scores = state.scores || {};
        const topScore = Object.values(scores).reduce((max, value) => Math.max(max, Number(value) || 0), 0);

        if (!players.length) {
          playerScoresEl.innerHTML = '<div class="hud-text" style="color:#cbd5e1;">Waiting for players…</div>';
          return;
        }

        playerScoresEl.innerHTML = players.map((player) => {
          const userId = player.user_id ?? player.id;
          const name = player.username || 'Player';
          const score = scores[userId] ?? 0;
          const leader = score === topScore && topScore > 0;
          const initial = name.substring(0, 1).toUpperCase();
          return `
            <div class="score-chip${leader ? ' leader' : ''}" aria-label="${name} has ${score} points">
              <div class="avatar">${initial}</div>
              <div class="meta"><strong>${name}</strong><span>${score} pts</span></div>
            </div>
          `;
        }).join('');
      };

      const renderRack = () => {
        rackEl.innerHTML = '';
        rack.forEach((tile) => {
          const tileEl = document.createElement('div');
          tileEl.className = `rack-tile${tile.isBlank ? ' blank' : ''}`;
          tileEl.draggable = true;
          tileEl.dataset.tileId = tile.id;
          tileEl.dataset.location = 'rack';
          tileEl.addEventListener('dragstart', handleTileDragStart);
          tileEl.addEventListener('touchstart', handleTileTouchStart, { passive: false });
          tileEl.addEventListener('touchmove', handleTileTouchMove, { passive: false });
          tileEl.addEventListener('touchend', handleTileTouchEnd);
          tileEl.addEventListener('touchcancel', handleTileTouchEnd);

          const letterEl = document.createElement('span');
          letterEl.className = `letter${tile.isBlank && tile.assignedLetter === '' ? ' blank-empty' : tile.isBlank ? ' blank-assigned' : ''}`;
          letterEl.textContent = tile.isBlank ? (tile.assignedLetter || '·') : tile.letter;

          const valueEl = document.createElement('span');
          valueEl.className = 'value';
          valueEl.textContent = tile.isBlank ? '' : tile.value;

          tileEl.appendChild(letterEl);
          tileEl.appendChild(valueEl);
          rackEl.appendChild(tileEl);
        });
      };

      const renderBoard = () => {
        cells.forEach((cell) => {
          const row = Number(cell.dataset.row);
          const col = Number(cell.dataset.col);
          const tile = board[row][col];
          const premium = premiumLayout[row][col];
          const labelEl = cell.querySelector('.cell-label');
          cell.classList.remove('invalid', 'drag-target');
          cell.classList.toggle('show-premium', Boolean(tile && tile.justPlaced && premium));
          cell.classList.toggle('premium-used', Boolean(tile && tile.locked && premium));
          cell.removeAttribute('data-tooltip');
          const existingTile = cell.querySelector('.tile');
          if (existingTile) {
            existingTile.remove();
          }

          if (labelEl) {
            const hideLabel = Boolean(tile && tile.locked && premium);
            labelEl.style.display = hideLabel ? 'none' : '';
          }

          if (!tile) return;

          if (tile.invalidReason) {
            cell.classList.add('invalid');
            cell.dataset.tooltip = tile.invalidReason;
          }

          const tileEl = document.createElement('div');
          tileEl.className = `tile${tile.isBlank ? ' blank' : ''}`;
          tileEl.draggable = !tile.locked;
          tileEl.dataset.tileId = tile.id;
          tileEl.dataset.location = 'board';
          tileEl.addEventListener('dragstart', handleTileDragStart);
          tileEl.addEventListener('touchstart', handleTileTouchStart, { passive: false });
          tileEl.addEventListener('touchmove', handleTileTouchMove, { passive: false });
          tileEl.addEventListener('touchend', handleTileTouchEnd);
          tileEl.addEventListener('touchcancel', handleTileTouchEnd);
          tileEl.addEventListener('dblclick', () => moveTileToRack(tile.id));

          const letterEl = document.createElement('span');
          const letterClass = tile.isBlank && !tile.assignedLetter ? 'letter blank-empty' : tile.isBlank ? 'letter blank-assigned' : 'letter';
          letterEl.className = letterClass;
          letterEl.textContent = tile.isBlank ? (tile.assignedLetter || '·') : tile.letter;

          const valueEl = document.createElement('span');
          valueEl.className = 'value';
          valueEl.textContent = tile.isBlank ? '' : tile.value;

          tileEl.appendChild(letterEl);
          tileEl.appendChild(valueEl);
          cell.appendChild(tileEl);
        });
      };

      const resetInvalidMarkers = () => {
        board.forEach((row) => row.forEach((tile) => {
          if (tile) tile.invalidReason = '';
        }));
      };

      const handleTileDragStart = (event) => {
        const tileId = event.target.dataset.tileId;
        event.dataTransfer.setData('text/plain', tileId);
        event.dataTransfer.effectAllowed = 'move';
      };

      const handleTileTouchStart = (event) => {
        const touch = event.touches[0];
        if (!touch) return;
        touchDragTileId = event.currentTarget.dataset.tileId;
        touchDragLastPosition = { x: touch.clientX, y: touch.clientY };
        event.preventDefault();
        event.stopPropagation();
      };

      const handleTileTouchMove = (event) => {
        if (!touchDragTileId) return;
        const touch = event.touches[0];
        if (!touch) return;
        touchDragLastPosition = { x: touch.clientX, y: touch.clientY };
        event.preventDefault();
      };

      const handleTileTouchEnd = (event) => {
        if (!touchDragTileId || !touchDragLastPosition) {
          touchDragTileId = null;
          touchDragLastPosition = null;
          return;
        }

        const touch = event.changedTouches?.[0];
        const point = touch ? { x: touch.clientX, y: touch.clientY } : touchDragLastPosition;
        const dropTarget = document.elementFromPoint(point.x, point.y);

        if (dropTarget) {
          const cell = dropTarget.closest('.cell');
          const rackTarget = dropTarget.closest('.rack');
          if (cell) {
            const row = Number(cell.dataset.row);
            const col = Number(cell.dataset.col);
            moveTileToBoard(touchDragTileId, row, col);
          } else if (rackTarget) {
            moveTileToRack(touchDragTileId);
          }
        }

        touchDragTileId = null;
        touchDragLastPosition = null;
      };

      const moveTileToBoard = (tileId, row, col) => {
        const tile = findTile(tileId);
        if (!tile || tile.locked) return;

        if (board[row][col] && board[row][col].locked) {
          setMessage('That square already holds a locked tile.', 'error');
          return;
        }

        if (tile.isBlank && !tile.assignedLetter) {
          const letter = prompt('Assign a letter to this blank tile (A-Z):', '');
          if (!letter || !letter.match(/^[a-zA-Z]$/)) {
            setMessage('Blank tiles must be assigned A–Z before placing.', 'error');
            return;
          }
          tile.assignedLetter = letter.toUpperCase();
        }

        removeTileFromCurrentPosition(tile);
        tile.justPlaced = true;
        tile.invalidReason = '';
        board[row][col] = tile;
        tile.position = { type: 'board', row, col };
        renderBoard();
        renderRack();
        playFx('place', { rate: 0.94 + Math.random() * 0.12 });
      };

      const moveTileToRack = (tileId) => {
        const tile = findTile(tileId);
        if (!tile || tile.locked) return;
        const projectedRackSize = rack.length - (tile.position?.type === 'rack' ? 1 : 0);
        if (projectedRackSize >= RACK_SIZE) {
          setMessage('Your rack can only hold seven tiles.', 'error');
          return;
        }
        removeTileFromCurrentPosition(tile);
        tile.justPlaced = false;
        if (tile.isBlank) {
          tile.assignedLetter = '';
        }
        tile.position = { type: 'rack' };
        rack.push(tile);
        renderRack();
        renderBoard();
      };

      const removeTileFromCurrentPosition = (tile) => {
        if (tile.position?.type === 'board') {
          const { row, col } = tile.position;
          if (board[row][col]?.id === tile.id) {
            board[row][col] = null;
          }
        }
        if (tile.position?.type === 'rack') {
          rack = rack.filter((t) => t.id !== tile.id);
        }
      };

      const findTile = (tileId) => {
        const fromRack = rack.find((tile) => tile.id === tileId);
        if (fromRack) return fromRack;
        for (let r = 0; r < BOARD_SIZE; r += 1) {
          for (let c = 0; c < BOARD_SIZE; c += 1) {
            const tile = board[r][c];
            if (tile && tile.id === tileId) {
              return tile;
            }
          }
        }
        return null;
      };

      const returnLooseTilesToRack = (announce = true) => {
        const movable = [];
        for (let r = 0; r < BOARD_SIZE; r += 1) {
          for (let c = 0; c < BOARD_SIZE; c += 1) {
            const tile = board[r][c];
            if (tile && !tile.locked) {
              movable.push(tile.id);
            }
          }
        }

        movable.forEach((id) => moveTileToRack(id));
        if (movable.length && announce) {
          setMessage('Tiles returned to your rack for the new AI run.', 'success');
        }
      };

      let latestSuggestions = (serverSuggestions || []).length ? serverSuggestions : [];

      const parseCoordinate = (coord) => {
        const match = /^([A-Oa-o])(\d{1,2})$/.exec((coord || '').trim());
        if (!match) return null;
        const row = match[1].toUpperCase().charCodeAt(0) - 'A'.charCodeAt(0);
        const col = Number(match[2]) - 1;
        if (Number.isNaN(row) || Number.isNaN(col) || row < 0 || col < 0 || row >= BOARD_SIZE || col >= BOARD_SIZE) {
          return null;
        }
        return { row, col };
      };

      const rackInventory = () => {
        const letters = {};
        let blanks = 0;

        rack.forEach((tile) => {
          if (tile.isBlank) {
            blanks += 1;
            return;
          }
          const letter = (tile.assignedLetter || tile.letter || '').toUpperCase();
          if (letter) {
            letters[letter] = (letters[letter] || 0) + 1;
          }
        });

        return { letters, blanks };
      };

      const collectMovableBoardTiles = () => {
        const movable = [];
        for (let r = 0; r < BOARD_SIZE; r += 1) {
          for (let c = 0; c < BOARD_SIZE; c += 1) {
            const tile = board[r][c];
            if (tile && !tile.locked) {
              movable.push({ tile, row: r, col: c });
            }
          }
        }
        return movable;
      };

      const rackLettersForServer = () => rack.map((tile) => {
        if (tile.isBlank && tile.assignedLetter) {
          return tile.assignedLetter;
        }
        if (tile.isBlank) {
          return '?';
        }
        return tile.letter;
      });

      const boardStateForServer = () => {
        const placements = [];
        for (let r = 0; r < BOARD_SIZE; r += 1) {
          for (let c = 0; c < BOARD_SIZE; c += 1) {
            const tile = board[r][c];
            if (!tile) continue;
            placements.push({
              row: r,
              col: c,
              letter: tile.letter,
              assignedLetter: tile.assignedLetter,
              isBlank: tile.isBlank,
              locked: tile.locked,
            });
          }
        }
        return placements;
      };

      const suggestionPlayable = (move) => {
        if (!move || !move.word) return false;

        const start = parseCoordinate(move.start || 'H8');
        if (!start) return false;

        const direction = move.direction === 'vertical' ? 'vertical' : 'horizontal';
        const delta = direction === 'vertical' ? { dr: 1, dc: 0 } : { dr: 0, dc: 1 };
        const word = (move.word || '').toUpperCase();
        const pool = rackInventory();
        collectMovableBoardTiles().forEach(({ tile }) => {
          if (tile.isBlank) {
            pool.blanks += 1;
            return;
          }
          const letter = (tile.assignedLetter || tile.letter || '').toUpperCase();
          if (letter) {
            pool.letters[letter] = (pool.letters[letter] || 0) + 1;
          }
        });

        for (let i = 0; i < word.length; i += 1) {
          const row = start.row + delta.dr * i;
          const col = start.col + delta.dc * i;
          if (row >= BOARD_SIZE || col >= BOARD_SIZE) {
            return false;
          }

          const targetTile = board[row][col];
          if (targetTile && targetTile.locked) {
            const lockedLetter = targetTile.isBlank ? (targetTile.assignedLetter || targetTile.letter) : targetTile.letter;
            if ((lockedLetter || '').toUpperCase() !== word[i]) {
              return false;
            }
            continue;
          }

          if ((pool.letters[word[i]] || 0) > 0) {
            pool.letters[word[i]] -= 1;
            continue;
          }

          if (pool.blanks > 0) {
            pool.blanks -= 1;
            continue;
          }

          return false;
        }

        return true;
      };

      const fetchAiSuggestions = async () => {
        try {
          const response = await fetch('game.php?action=suggestions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              rack: rackLettersForServer(),
              board: boardStateForServer(),
            }),
          });

          if (!response.ok) {
            throw new Error('Suggestion fetch failed');
          }

          const data = await response.json();
          latestSuggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
        } catch (error) {
          latestSuggestions = (serverSuggestions || []).length ? serverSuggestions : [];
        }
      };

      const findCell = (row, col) => cells.find((cell) => Number(cell.dataset.row) === row && Number(cell.dataset.col) === col);

      const animateTileToCell = (tile, cell, row, col, sourceOverride = null) => {
        if (!tile) return;
        const source = sourceOverride || document.querySelector(`[data-tile-id="${tile.id}"][data-location="rack"]`);
        const targetRect = cell?.getBoundingClientRect();
        const sourceRect = source?.getBoundingClientRect();

        if (sourceRect && targetRect) {
          const ghost = (source?.cloneNode(true) || document.createElement('div'));
          ghost.classList.add('tile-ghost');
          ghost.style.width = `${sourceRect.width}px`;
          ghost.style.height = `${sourceRect.height}px`;
          ghost.style.top = `${sourceRect.top}px`;
          ghost.style.left = `${sourceRect.left}px`;
          ghost.style.transform = 'translate(0, 0) scale(1)';
          ghost.style.opacity = '1';
          document.body.appendChild(ghost);

          requestAnimationFrame(() => {
            const targetX = targetRect.left + (targetRect.width - sourceRect.width) / 2;
            const targetY = targetRect.top + (targetRect.height - sourceRect.height) / 2;
            ghost.style.transform = `translate(${targetX - sourceRect.left}px, ${targetY - sourceRect.top}px) scale(1.05)`;
            ghost.style.opacity = '0.1';
          });

          setTimeout(() => {
            moveTileToBoard(tile.id, row, col);
            ghost.remove();
          }, 380);
          return;
        }

        moveTileToBoard(tile.id, row, col);
      };

      const applyRemotePlacements = (placements = [], actorId = null) => {
        if (!Array.isArray(placements)) return;
        placements.forEach((placement, index) => {
          const row = Number(placement.row);
          const col = Number(placement.col);
          if (Number.isNaN(row) || Number.isNaN(col)) return;
          const tile = createTile((placement.letter || '?').toUpperCase());
          tile.isBlank = Boolean(placement.isBlank);
          tile.assignedLetter = placement.assignedLetter || '';
          tile.locked = true;
          tile.justPlaced = true;
          removeTileFromCurrentPosition(tile);
          tile.position = { type: 'board', row, col };
          board[row][col] = tile;
          setTimeout(() => {
            const cell = findCell(row, col);
            if (cell) {
              cell.classList.add('pulse');
              setTimeout(() => cell.classList.remove('pulse'), 620);
            }
          }, index * 80);
        });
        renderBoard();
        setTimeout(() => { renderBoard(); }, 640);
          if (actorId) {
            setMessage(`Player ${actorId} locked in their play.`, 'info');
          }
        };

      const applySuggestedMove = (move) => {
        if (!move || !move.word) {
          setMessage('No suggestion to place right now.', 'error');
          return;
        }

        returnLooseTilesToRack(false);
        renderBoard();
        renderRack();

        const start = parseCoordinate(move.start || 'H8');
        if (!start) {
          setMessage('Unable to read that suggestion position.', 'error');
          return;
        }

        const direction = move.direction === 'vertical' ? 'vertical' : 'horizontal';
        const delta = direction === 'vertical' ? { dr: 1, dc: 0 } : { dr: 0, dc: 1 };
        const word = (move.word || '').toUpperCase();
        const availableTiles = [
          ...rack.map((tile) => ({ tile, source: 'rack' })),
          ...collectMovableBoardTiles().map((entry) => ({ ...entry, source: 'board' })),
        ];
        const placements = [];
        const placementLetters = new Map();
        const blankPositions = new Set();

        const takeTile = (predicate) => {
          const index = availableTiles.findIndex(({ tile }) => predicate(tile));
          if (index === -1) return null;
          const [entry] = availableTiles.splice(index, 1);
          return entry;
        };

        if (Array.isArray(move.placements)) {
          move.placements.forEach((placement) => {
            const coord = parseCoordinate(placement.coord || '');
            if (!coord) return;
            const key = `${coord.row}-${coord.col}`;
            placementLetters.set(key, (placement.letter || '').toUpperCase());
            if (placement.isBlank) {
              blankPositions.add(key);
            }
          });
        }

        for (let i = 0; i < word.length; i += 1) {
          const row = start.row + delta.dr * i;
          const col = start.col + delta.dc * i;
          if (row >= BOARD_SIZE || col >= BOARD_SIZE) {
            setMessage('That suggestion would run off the board.', 'error');
            return;
          }

          const targetTile = board[row][col];
          if (targetTile && targetTile.locked) {
            const lockedLetter = targetTile.isBlank ? (targetTile.assignedLetter || targetTile.letter) : targetTile.letter;
            if (lockedLetter.toUpperCase() !== word[i]) {
              setMessage('A locked tile blocks part of that suggestion.', 'error');
              return;
            }
            continue;
          }

          if (targetTile && !targetTile.locked) {
            moveTileToRack(targetTile.id);
          }

          const key = `${row}-${col}`;
          const recordedLetter = placementLetters.get(key);
          const letterForPosition = recordedLetter && recordedLetter !== '?' ? recordedLetter : word[i];
          const needsBlank = blankPositions.has(key);
          const found = takeTile((candidate) => {
            if (!candidate) return false;
            if (needsBlank) return candidate.isBlank;
            if (!candidate.isBlank && (candidate.assignedLetter || candidate.letter).toUpperCase() === letterForPosition) return true;
            if (candidate.isBlank) return true;
            return false;
          });

          if (!found) {
            setMessage(`Missing the tile “${letterForPosition}” to build ${word}.`, 'error');
            return;
          }

          const sourceElement = found.source === 'board'
            ? document.querySelector(`[data-tile-id="${found.tile.id}"][data-location="board"]`)
            : document.querySelector(`[data-tile-id="${found.tile.id}"][data-location="rack"]`);

          if (found.source === 'board') {
            removeTileFromCurrentPosition(found.tile);
          }

          placements.push({ tileId: found.tile.id, letter: letterForPosition, row, col, needsBlank, sourceElement });
        }

        if (!placements.length) {
          setMessage('No movable tiles needed for that suggestion.', 'error');
          return;
        }

        placements.forEach((placement, index) => {
          const tile = findTile(placement.tileId);
          if (tile && tile.isBlank) {
            tile.assignedLetter = placement.letter;
          }
          const cell = findCell(placement.row, placement.col);
          setTimeout(() => {
            animateTileToCell(tile, cell, placement.row, placement.col, placement.sourceElement || null);
          }, index * 160);
        });

        const finalDelay = (placements.length - 1) * 160 + 420;
        setTimeout(() => {
          setMessage(`Placed “${word}” from AI suggestions.`, 'success');
        }, finalDelay);

        closeAiModal();
      };

      const clearAiTimers = () => {
        if (aiStepInterval) clearInterval(aiStepInterval);
        if (aiRevealTimeout) clearTimeout(aiRevealTimeout);
        aiStepInterval = null;
        aiRevealTimeout = null;
        stopAiAudioLoop();
      };

      const setAiAnimationActive = (active) => {
        const hasResults = aiListEl && aiListEl.children.length > 0;
        if (aiStatusEl) {
          aiStatusEl.classList.toggle('ai-complete', !active);
          aiStatusEl.classList.toggle('hidden', !active && hasResults);
          if (active) {
            aiStatusEl.classList.remove('hidden');
          }
        }
        if (aiAnimationEl) {
          aiAnimationEl.classList.toggle('hidden', !active);
        }
        if (active) {
          startAiAudioLoop();
        } else {
          stopAiAudioLoop();
        }
      };

      const openAiModal = () => {
        if (!aiModal) return;
        aiModal.classList.add('active');
        aiModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        if (aiCloseBtn) {
          aiCloseBtn.focus();
        }
      };

      const closeAiModal = () => {
        if (!aiModal) return;
        aiModal.classList.remove('active');
        aiModal.setAttribute('aria-hidden', 'true');
        const rulesOpen = document.getElementById('rulesModal')?.classList.contains('active');
        if (!rulesOpen) {
          document.body.classList.remove('modal-open');
        }
        clearAiTimers();
        setAiAnimationActive(false);
        if (aiListEl) {
          aiListEl.innerHTML = '';
        }
      };

      const renderAiSuggestions = (list) => {
        if (!aiListEl) return;
        aiListEl.innerHTML = '';

        if (!list.length) {
          aiListEl.innerHTML = '<li class="ai-card">No playable suggestions right now.</li>';
          return;
        }

        list.slice(0, 5).forEach((move, index) => {
          const li = document.createElement('li');
          li.className = 'ai-card';
          li.dataset.word = move.word;

          const crossCount = (move.crossWords || []).length;
          const directionLabel = move.direction === 'vertical' ? 'Vertical' : 'Horizontal';

          li.innerHTML = `
            <h4><span aria-hidden="true">#${index + 1}</span> ${move.word} <span style="color:#0ea5e9; font-size:14px;">${move.score} pts</span></h4>
            <p class="ai-meta">${directionLabel} from ${move.start || 'H8'} • Main word ${move.mainWordScore} pts${crossCount ? ` • +${crossCount} cross` : ''}</p>
          `;

          li.addEventListener('click', () => {
            applySuggestedMove(move);
          });

          aiListEl.appendChild(li);
        });
      };

      const showAiThinking = async () => {
        if (!aiStatusEl || !aiStepEl) return;
        const steps = [
          'Scanning premiums and openings…',
          'Shuffling rack tiles into patterns…',
          'Checking cross words for legality…',
          'Ranking plays by score and coverage…',
        ];

        if (aiListEl) {
          aiListEl.innerHTML = '';
        }

        setAiAnimationActive(true);
        aiStepEl.textContent = steps[0];
        if (aiSubtextEl) {
          aiSubtextEl.textContent = 'Returning tiles and brainstorming the best openings for you…';
        }

        clearAiTimers();
        aiRevealTimeout = setTimeout(async () => {
          await fetchAiSuggestions();
          const playable = (latestSuggestions || []).filter((move) => suggestionPlayable(move));
          const topFive = playable.slice(0, 5);
          renderAiSuggestions(topFive);
          setAiAnimationActive(false);
          if (aiSubtextEl) {
            aiSubtextEl.textContent = topFive.length
              ? 'Suggestions ready! Showing the top five playable moves—scroll and tap to load one.'
              : 'No playable suggestions with your current rack—draw or adjust tiles and try again.';
          }
          clearAiTimers();
        }, 2600);

        let idx = 0;
        aiStepInterval = setInterval(() => {
          idx = (idx + 1) % steps.length;
          aiStepEl.textContent = steps[idx];
        }, 900);
      };

      const startTurn = () => {
        if (!isMyTurn()) {
          setMessage(`${currentTurnName()} is still playing. Please wait your turn.`, 'error');
          return false;
        }
        let drewTiles = false;

        while (rack.length < RACK_SIZE) {
          const letter = pickTileFromBag();
          if (!letter) break;
          const tile = createTile(letter);
          tile.position = { type: 'rack' };
          rack.push(tile);
          drewTiles = true;
        }

        updateBagCount();
        renderRack();

        if (rack.length === RACK_SIZE) {
          setMessage(drewTiles ? 'Tiles drawn. Drag from rack to the board to form your word.' : 'Rack already has seven tiles.', 'success');
          if (drewTiles) {
            playFx('accept', { rate: 1.08 });
          }
        } else {
          setMessage('Bag is empty—continue with the tiles you have.', 'success');
        }

        syncGameState();
        setTurnMessaging();

        return true;
      };

      const tilesPlacedThisTurn = () => {
        const placed = [];
        for (let r = 0; r < BOARD_SIZE; r += 1) {
          for (let c = 0; c < BOARD_SIZE; c += 1) {
            const tile = board[r][c];
            if (tile && tile.justPlaced) {
              placed.push({ row: r, col: c, tile });
            }
          }
        }
        return placed;
      };

      const contiguousLine = (coords) => {
        const rows = coords.map((c) => c.row);
        const cols = coords.map((c) => c.col);
        const sameRow = rows.every((r) => r === rows[0]);
        const sameCol = cols.every((c) => c === cols[0]);
        if (!sameRow && !sameCol) return { ok: false, reason: 'Tiles must share one row or column.' };

        if (sameRow) {
          const min = Math.min(...cols);
          const max = Math.max(...cols);
          for (let c = min; c <= max; c += 1) {
            if (!board[rows[0]][c]) return { ok: false, reason: 'Main word cannot have gaps.' };
          }
          return { ok: true, axis: 'row', fixed: rows[0], start: min, end: max };
        }

        const min = Math.min(...rows);
        const max = Math.max(...rows);
        for (let r = min; r <= max; r += 1) {
          if (!board[r][cols[0]]) return { ok: false, reason: 'Main word cannot have gaps.' };
        }
        return { ok: true, axis: 'col', fixed: cols[0], start: min, end: max };
      };

      const wordCoordinates = (row, col, dRow, dCol) => {
        let r = row;
        let c = col;
        while (r - dRow >= 0 && r - dRow < BOARD_SIZE && c - dCol >= 0 && c - dCol < BOARD_SIZE && board[r - dRow][c - dCol]) {
          r -= dRow;
          c -= dCol;
        }

        const coords = [];
        while (r >= 0 && r < BOARD_SIZE && c >= 0 && c < BOARD_SIZE && board[r][c]) {
          coords.push({ row: r, col: c });
          r += dRow;
          c += dCol;
        }
        return coords;
      };

      const wordFromCoords = (coords) => coords.map(({ row, col }) => {
        const tile = board[row][col];
        return tile.isBlank ? tile.assignedLetter : tile.letter;
      }).join('');

      const wordScore = (coords) => {
        let total = 0;
        let wordMultiplier = 1;

        coords.forEach(({ row, col }) => {
          const tile = board[row][col];
          const premium = premiumLayout[row][col];
          let letterScore = tile.isBlank ? 0 : tile.value;

          if (tile.justPlaced) {
            if (premium === 'DL') letterScore *= 2;
            if (premium === 'TL') letterScore *= 3;
            if (premium === 'DW') wordMultiplier *= 2;
            if (premium === 'TW') wordMultiplier *= 3;
          }

          total += letterScore;
        });

        return total * wordMultiplier;
      };

      const hasAdjacentLocked = (coords) => coords.some(({ row, col }) => {
        const deltas = [[1, 0], [-1, 0], [0, 1], [0, -1]];
        return deltas.some(([dr, dc]) => {
          const nr = row + dr;
          const nc = col + dc;
          if (nr < 0 || nr >= BOARD_SIZE || nc < 0 || nc >= BOARD_SIZE) return false;
          const neighbor = board[nr][nc];
          return neighbor && neighbor.locked;
        });
      });

      const validateTurn = () => {
        resetInvalidMarkers();
        const placements = tilesPlacedThisTurn();
        if (placements.length === 0) {
          setMessage('Place at least one tile before submitting.', 'error');
          renderBoard();
          return false;
        }

        if (placements.some((p) => p.tile.isBlank && !p.tile.assignedLetter)) {
          setMessage('Assign letters to all blanks.', 'error');
          return false;
        }

        const centerCell = placements.find(({ row, col }) => row === 7 && col === 7);
        const contiguity = contiguousLine(placements);
        if (!contiguity.ok) {
          placements.forEach(({ tile }) => { tile.invalidReason = contiguity.reason; });
          renderBoard();
          setMessage(contiguity.reason, 'error');
          return false;
        }

        if (firstTurn && !centerCell) {
          placements.forEach(({ tile }) => { tile.invalidReason = 'Opening move must use the center star.'; });
          renderBoard();
          setMessage('Opening move must touch the center star.', 'error');
          return false;
        }

        if (!firstTurn && !hasAdjacentLocked(placements)) {
          placements.forEach(({ tile }) => { tile.invalidReason = 'New tiles must connect to existing words.'; });
          renderBoard();
          setMessage('New tiles have to connect to an existing word.', 'error');
          return false;
        }

        const mainCoords = contiguity.axis === 'row'
          ? wordCoordinates(contiguity.fixed, contiguity.start, 0, 1)
          : wordCoordinates(contiguity.start, contiguity.fixed, 1, 0);

        const wordsToCheck = [mainCoords];
        placements.forEach(({ row, col }) => {
          const perpendicular = contiguity.axis === 'row'
            ? wordCoordinates(row, col, 1, 0)
            : wordCoordinates(row, col, 0, 1);
          if (perpendicular.length > 1) {
            wordsToCheck.push(perpendicular);
          }
        });

        const invalidWords = wordsToCheck.filter((coords) => {
          if (coords.length <= 1) return false;
          const word = wordFromCoords(coords);
          return !dictionaryReady || !dictionary.has(word);
        });

        if (invalidWords.length) {
          invalidWords.forEach((coords) => {
            coords.forEach(({ row, col }) => {
              const tile = board[row][col];
              if (tile.justPlaced) {
                tile.invalidReason = '“' + wordFromCoords(coords) + '” is not in the dictionary.';
              }
            });
          });
          renderBoard();
          setMessage('Every formed word must appear in the dictionary.', 'error');
          return false;
        }

        if (mainCoords.length === 1) {
          placements.forEach(({ tile }) => { tile.invalidReason = 'A word must use at least two tiles.'; });
          renderBoard();
          setMessage('A valid move needs a word of length two or more.', 'error');
          return false;
        }

        const total = wordsToCheck.reduce((sum, coords) => sum + wordScore(coords), 0) + (placements.length === 7 ? 50 : 0);
        finalizeTurn(total, placements.length === 7, placements);
        return true;
      };

      const finalizeTurn = (turnScore, bingo, placements = []) => {
        const committed = placements.length ? placements : tilesPlacedThisTurn();
        const serializedPlacements = committed.map(({ row, col, tile }) => ({
          row,
          col,
          letter: tile.letter,
          assignedLetter: tile.assignedLetter || '',
          isBlank: tile.isBlank,
        }));

        committed.forEach(({ tile }) => {
          tile.locked = true;
          tile.justPlaced = false;
        });
        totalScore += turnScore;
        if (scoreEl) { scoreEl.textContent = totalScore; }
        firstTurn = false;
        turnActive = false;
        updateTurnButton();
        updateAiButton();
        renderBoard();
        const scoreNote = bingo ? ' + 50-point bingo!' : '';
        setMessage(`Move accepted for ${turnScore} points${scoreNote}. Draw to refill for the next turn.`, 'success');
        playFx('accept', { rate: 1.02 });
        const actingIndex = state.turnIndex;
        if (state.turnOrder.length) {
          state.turnIndex = (state.turnIndex + 1) % state.turnOrder.length;
        }
        syncGameState({ advanceTurn: true, actingIndex, scoreDelta: turnScore });
        setTurnMessaging();
      };

      const resetBoard = () => {
        tileId = 0;
        rack = [];
        board = Array.from({ length: BOARD_SIZE }, () => Array.from({ length: BOARD_SIZE }, () => null));
        totalScore = 0;
        firstTurn = true;
        turnActive = false;
        if (state.game) {
          bag = Array.isArray(state.game.bag) ? [...state.game.bag] : [];
          applyBoardState(state.game.board_state || []);
          applyRackState();
          updateBagCount();
        }
        if (scoreEl) { scoreEl.textContent = '0'; }
        setMessage('Board reset to the last synced state.', 'success');
        updateTurnButton();
        updateAiButton();
        playFx('reset', { rate: 0.96 });
      };

      const setupDragAndDrop = () => {
        cells.forEach((cell) => {
          cell.addEventListener('dragover', (event) => {
            event.preventDefault();
            cell.classList.add('drag-target');
          });
          cell.addEventListener('dragleave', () => cell.classList.remove('drag-target'));
          cell.addEventListener('drop', (event) => {
            event.preventDefault();
            cell.classList.remove('drag-target');
            const tileId = event.dataTransfer.getData('text/plain');
            const row = Number(cell.dataset.row);
            const col = Number(cell.dataset.col);
            if (tileId) {
              moveTileToBoard(tileId, row, col);
            }
          });
        });

        rackEl.addEventListener('dragover', (event) => {
          event.preventDefault();
          rackEl.classList.add('drag-target');
        });
        rackEl.addEventListener('dragleave', () => rackEl.classList.remove('drag-target'));
        rackEl.addEventListener('drop', (event) => {
          event.preventDefault();
          rackEl.classList.remove('drag-target');
          const tileId = event.dataTransfer.getData('text/plain');
          if (tileId) {
            moveTileToRack(tileId);
          }
        });
      };

      const loadDictionary = async () => {
        try {
          const response = await fetch(dictionaryUrl);
          const text = await response.text();
          text.split(/\r?\n/).forEach((word) => {
            if (word.trim()) dictionary.add(word.trim().toUpperCase());
          });
          dictionaryReady = true;
          setMessage('Dictionary loaded. You can now validate moves.', 'success');
        } catch (error) {
          dictionaryReady = false;
          setMessage('Dictionary failed to load; validation will block plays.', 'error');
        }
      };

        const handleToggleClick = () => {
          if (!turnActive) {
            const started = startTurn();
            if (started) {
              turnActive = true;
              updateTurnButton();
              updateAiButton();
            }
            return;
          }

          if (!dictionaryReady) {
            setMessage('Dictionary still loading. Try again in a moment.', 'error');
            return;
          }

          const valid = validateTurn();
          if (valid) {
            turnActive = false;
            updateTurnButton();
            updateAiButton();
          }
        };

        const handleAiClick = async () => {
          if (!turnActive) {
            setMessage('Start your turn to request AI suggestions.', 'error');
            return;
          }
          returnLooseTilesToRack();
          renderBoard();
          renderRack();
          openAiModal();
          await showAiThinking();
        };

        const shuffleRack = () => {
          if (!rack.length) {
            setMessage('No tiles to shuffle yet.', 'error');
            return;
          }
          for (let i = rack.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            [rack[i], rack[j]] = [rack[j], rack[i]];
          }
          renderRack();
          setMessage('Rack shuffled.', 'success');
          playFx('shuffle', { rate: 0.94 + Math.random() * 0.1 });
        };

        const handleAiClose = () => {
          closeAiModal();
        };

      if (toggleBtn) toggleBtn.addEventListener('click', handleToggleClick);
      if (drawTileBtn) drawTileBtn.addEventListener('click', handleDrawTile);
      if (drawBag) {
        drawBag.addEventListener('click', handleDrawTile);
        drawBag.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            handleDrawTile();
          }
        });
      }
      if (resetBtn) resetBtn.addEventListener('click', () => { closeHudMenu(); resetBoard(); });
      if (rematchBtn) rematchBtn.addEventListener('click', requestRematch);
      if (closeWinnerBtn) closeWinnerBtn.addEventListener('click', closeWinnerModal);
      if (aiBtn) aiBtn.addEventListener('click', handleAiClick);
      if (passBtn) passBtn.addEventListener('click', handlePass);
      if (exchangeBtn) exchangeBtn.addEventListener('click', handleExchange);
      if (aiCloseBtn) aiCloseBtn.addEventListener('click', handleAiClose);
      if (shuffleBtn) shuffleBtn.addEventListener('click', shuffleRack);
      if (aiModal) {
        aiModal.addEventListener('click', (event) => {
          if (event.target === aiModal) {
            closeAiModal();
          }
        });
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            if (aiModal.classList.contains('active')) {
              closeAiModal();
            }
            closeHudMenu();
            closeRackHelp();
          }
        });
      }

      if (menuToggle) {
        menuToggle.addEventListener('click', (event) => {
          event.stopPropagation();
          toggleHudMenu();
        });
      }

      if (rackHelpBtn) {
        rackHelpBtn.addEventListener('click', (event) => {
          event.stopPropagation();
          toggleRackHelp();
        });
      }

      document.addEventListener('click', (event) => {
        if (hudMenu && !hudMenu.contains(event.target) && event.target !== menuToggle) {
          closeHudMenu();
        }
        if (rackHelpTip && rackHelpTip.classList.contains('show') && !rackHelpTip.contains(event.target) && event.target !== rackHelpBtn) {
          closeRackHelp();
        }
      });

      if (rulesBtn) {
        rulesBtn.addEventListener('click', () => closeHudMenu());
      }

      if (boardViewport) {
        boardViewport.addEventListener('wheel', handleWheelZoom, { passive: false });
        boardViewport.addEventListener('mousedown', startBoardPan);
        boardViewport.addEventListener('mouseleave', endBoardPan);
        window.addEventListener('mousemove', continueBoardPan);
        window.addEventListener('mouseup', endBoardPan);
        boardViewport.addEventListener('touchstart', handleTouchStart, { passive: false });
        boardViewport.addEventListener('touchmove', handleTouchMove, { passive: false });
        boardViewport.addEventListener('touchend', handleTouchEnd);
        boardViewport.addEventListener('touchcancel', handleTouchEnd);
        boardViewport.addEventListener('dblclick', (event) => {
          event.preventDefault();
          centerBoard();
        });
      }

      if (boardControlsToggle) {
        boardControlsToggle.addEventListener('click', (event) => {
          event.stopPropagation();
          toggleBoardControls();
        });
      }

      if (zoomInBtn) zoomInBtn.addEventListener('click', () => adjustZoom(1.12, viewportCenter()));
      if (zoomOutBtn) zoomOutBtn.addEventListener('click', () => adjustZoom(0.9, viewportCenter()));
      if (centerBoardBtn) centerBoardBtn.addEventListener('click', () => centerBoard());
      if (fitBoardBtn) fitBoardBtn.addEventListener('click', () => fitBoard());
      if (resetViewBtn) resetViewBtn.addEventListener('click', () => resetBoardView());

      window.addEventListener('resize', () => resizeBoardToViewport({ resetView: false }));

      renderBoard();
      updateTurnButton();
      updateAiButton();
      requestAnimationFrame(() => resizeBoardToViewport({ resetView: true }));
      setTimeout(() => resizeBoardToViewport({ resetView: false }), 160);
      setupDragAndDrop();
      loadDictionary();
      fetchUser().then(() => fetchGameState()).then(() => {
        setTurnMessaging();
        if (isMyTurn() && rack.length === 0) {
          startTurn();
        }
      });
      setInterval(() => fetchGameState({ silent: true }), 2500);
    });
  </script>

  <div class="modal-backdrop" id="aiModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="aiTitle">
      <div class="modal-header">
        <h3 id="aiTitle">AI suggested moves</h3>
        <button class="modal-close" type="button" id="closeAi" aria-label="Close AI suggestions">×</button>
      </div>
      <div class="ai-status" id="aiStatus">
        <div class="ai-visual" id="aiAnimation" aria-hidden="true">
          <div class="ai-orbital"></div>
          <div class="ai-orbital"></div>
          <div class="ai-orbital"></div>
          <div class="ai-core"><span>thinking</span></div>
        </div>
        <div class="ai-copy">
          <span class="ai-step" id="aiStep">Warming up the move engine…</span>
          <span class="ai-dots" aria-hidden="true"><span></span><span></span><span></span></span>
          <p class="ai-meta" id="aiSubtext">We’ll surface the top five playable words—scroll to review them once ready.</p>
        </div>
      </div>
      <ul class="ai-list" id="aiList" aria-live="polite"></ul>
    </div>
  </div>

  <div class="modal-backdrop" id="rulesModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="rulesTitle">
      <div class="modal-header">
        <h3 id="rulesTitle">Rules for the word tile game</h3>
        <button class="modal-close" type="button" id="closeRules" aria-label="Close rules">×</button>
      </div>
      <div class="rules-highlight">
        Tile pool: 100 total tiles. Counts: 12 E, 9 A, 9 I, 8 O, 6 each N R T, 4 each D L S U, 3 G, 2 each B C F H M P V W Y, and 1 each J K Q X Z. Two blanks score 0 and start empty until assigned.
      </div>
      <ol class="rules-list">
        <li><strong>Objective.</strong> Score the most points by forming valid words horizontally or vertically on the 15x15 board.</li>
        <li><strong>Setup.</strong> Each player draws seven tiles from the facedown pool. The first play must use the center double word square.</li>
        <li><strong>Tile placement.</strong> Words read left to right or top to bottom and must connect to the existing chain after the opening turn.</li>
        <li><strong>Premium squares.</strong> Double and triple letter or word squares affect only newly placed tiles; multiple word bonuses in a single play multiply together.</li>
        <li><strong>Blank tiles.</strong> Two blanks act as wild letters. Choose a value when played; the tile shows a blue letter and always scores 0 points.</li>
        <li><strong>Scoring a turn.</strong> Add letter values with any letter bonuses, then apply word bonuses. Include scores for every new cross word formed. Playing all seven tiles in one turn adds a 50 point bonus.</li>
        <li><strong>Exchanging tiles.</strong> On your turn you may swap any number of tiles with the pool if at least seven tiles remain, but you forfeit scoring that turn.</li>
        <li><strong>Challenging words.</strong> A play can be challenged before the next turn. Invalid words are removed and score zero; valid plays stand.</li>
        <li><strong>Ending the game.</strong> Play ends when the pool is empty and a player uses all tiles or when every player passes twice. Subtract leftover rack points from each player; add the opponent totals to the score of the player who went out.</li>
      </ol>
      <p class="modal-footer-note">A blank tile appears as an empty face until you assign it, at which point the blue letter reminds everyone it still carries no points.</p>
    </div>
  </div>

  <script>
    (() => {
      const modal = document.getElementById('rulesModal');
      const openBtn = document.getElementById('openRules');
      const closeBtn = document.getElementById('closeRules');

      if (!modal || !openBtn || !closeBtn) {
        return;
      }

      const setModalState = (open) => {
        modal.classList.toggle('active', open);
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) {
          document.body.classList.add('modal-open');
        } else {
          const aiOpen = document.getElementById('aiModal')?.classList.contains('active');
          if (!aiOpen) {
            document.body.classList.remove('modal-open');
          }
        }

        if (open) {
          closeBtn.focus();
        }
      };

      openBtn.addEventListener('click', () => setModalState(true));
      closeBtn.addEventListener('click', () => setModalState(false));
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          setModalState(false);
        }
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
          setModalState(false);
        }
      });
    })();
  </script>
</body>
</html>
