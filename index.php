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
      --cell-size: 56px;
      --cell-gap: 6px;
      --tile-size: calc(var(--cell-size) - 8px);
      --top-dock-height: 82px;
      --bottom-dock-height: 116px;
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
      padding: var(--top-dock-height) 18px var(--bottom-dock-height);
      display: flex;
      flex-direction: column;
      gap: 0;
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
      min-width: min(100%, 880px);
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
      width: var(--tile-size);
      height: var(--tile-size);
      background: var(--tile-wood);
      border-radius: 5px;
      border: 1px solid var(--tile-wood-border);
      box-shadow: 0 4px 8px rgba(15, 23, 42, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.7);
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 6px 8px;
      color: #0f172a;
    }

    .tile.blank,
    .rack-tile.blank {
      background: var(--tile-wood);
      border-color: var(--tile-wood-border);
    }

    .tile .letter {
      font-size: 22px;
      font-weight: 800;
      letter-spacing: 0.3px;
      line-height: 1;
    }

    .tile .value {
      position: absolute;
      bottom: 6px;
      right: 6px;
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
      grid-template-columns: auto 1fr auto;
      align-items: center;
      gap: 10px;
      position: relative;
    }

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
      flex-wrap: wrap;
      padding: 5px 8px;
      background: radial-gradient(circle at 10% 10%, rgba(236, 254, 255, 0.18), transparent 40%),
        linear-gradient(135deg, rgba(14, 165, 233, 0.18), rgba(99, 102, 241, 0.2));
      border: 1px solid rgba(148, 163, 184, 0.5);
      border-radius: 12px;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.14), 0 10px 22px rgba(79, 70, 229, 0.2);
      justify-content: center;
      min-height: 60px;
    }

    .rack-actions {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 10px;
      margin-top: 6px;
    }

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
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--tile-wood);
      border-radius: 5px;
      border: 1px solid var(--tile-wood-border);
      box-shadow: 0 6px 16px rgba(15, 23, 42, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
      color: #0f172a;
      font-weight: 800;
    }

    .rack-tile .letter {
      font-size: 22px;
      letter-spacing: 0.3px;
      line-height: 1;
    }

    .rack-tile .value {
      position: absolute;
      bottom: 6px;
      right: 6px;
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

    .btn[disabled] {
      opacity: 0.5;
      cursor: not-allowed;
      box-shadow: none;
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

    .rules-btn {
      justify-self: start;
      width: fit-content;
      cursor: pointer;
    }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.36);
      backdrop-filter: blur(2px);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      z-index: 10;
    }

    .modal-backdrop.active { display: flex; }

    .modal {
      background: #fff;
      max-width: 720px;
      width: min(720px, 100%);
      border-radius: 16px;
      box-shadow: 0 28px 60px rgba(15, 23, 42, 0.24);
      border: 1px solid #e2e8f0;
      padding: 18px 18px 20px;
      display: grid;
      gap: 10px;
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

    .ai-status {
      display: grid;
      gap: 6px;
      padding: 10px 12px;
      border-radius: 12px;
      background: linear-gradient(135deg, rgba(14, 165, 233, 0.08), rgba(99, 102, 241, 0.08));
      border: 1px solid #cbd5e1;
      font-weight: 600;
      color: #0f172a;
    }

    .ai-dots {
      display: inline-flex;
      gap: 4px;
      align-items: center;
    }

    .ai-dots span {
      width: 8px;
      height: 8px;
      background: #0ea5e9;
      border-radius: 50%;
      animation: pulse 1.2s infinite ease-in-out;
    }

    .ai-dots span:nth-child(2) { animation-delay: 0.2s; }
    .ai-dots span:nth-child(3) { animation-delay: 0.4s; }

    @keyframes pulse {
      0%, 100% { opacity: 0.2; transform: translateY(0); }
      50% { opacity: 1; transform: translateY(-2px); }
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
      padding: 0;
      list-style: none;
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
      width: min(1200px, 100%);
      margin: 0 auto;
      display: grid;
      gap: 12px;
      min-height: calc(100vh - var(--top-dock-height) - var(--bottom-dock-height));
      justify-items: center;
    }

    .board-viewport {
      width: min(1200px, 100%);
      margin: 0 auto;
      display: grid;
      align-items: start;
      justify-items: center;
      overflow: hidden;
      touch-action: none;
      cursor: grab;
    }

    .board-viewport.dragging { cursor: grabbing; }

    .board-scale {
      transform-origin: center;
      transition: transform 180ms ease;
      will-change: transform;
      display: grid;
      align-items: start;
      justify-items: center;
    }

    .board-chrome {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--border);
      box-shadow: var(--glow);
      padding: 16px;
      display: grid;
      gap: 12px;
      justify-items: center;
    }

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
      padding: 10px 16px 10px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .hud-right {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      justify-content: flex-end;
      margin-left: auto;
    }

    .hud-menu { flex-shrink: 0; order: 3; }
    .brand { order: 1; }
    .hud-right { order: 2; }

    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
    }

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
      padding: 7px 11px;
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
      padding: 8px 12px 10px;
      display: grid;
      gap: 6px;
    }

    .dock-row {
      display: grid;
      grid-template-columns: 1fr auto;
      align-items: center;
      gap: 8px;
    }

    .dock-cta {
      display: flex;
      justify-content: center;
      align-items: center;
      grid-column: 1 / span 2;
      min-width: 240px;
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
      justify-self: end;
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
    }

    @media (min-width: 900px) {
      body { padding: var(--top-dock-height) 32px var(--bottom-dock-height); }
      .grid { grid-template-columns: 2fr 1fr; }
      .grid .card:first-child { grid-column: span 2; }
    }

    @media (max-width: 720px) {
      :root {
        --top-dock-height: 74px;
        --bottom-dock-height: 110px;
        --cell-size: 48px;
        --cell-gap: 4px;
      }

      body { padding: calc(var(--top-dock-height) + 10px) 12px calc(var(--bottom-dock-height) + 10px); }
      .hud-inner { padding: 8px 12px 8px; gap: 8px; justify-content: center; display: grid; grid-template-columns: auto 1fr auto; align-items: center; }
      .hud-menu { order: 1; }
      .brand { order: 2; flex: 1; justify-content: center; justify-self: center; }
      .hud-right { order: 3; margin-left: 0; flex: 1; justify-content: flex-end; justify-self: end; }
      .hud-menu { justify-self: start; }
      .hud-meta { gap: 6px; }
      .hud-pill { padding: 6px 9px; font-size: 13px; }
      .app-title { font-size: clamp(18px, 5vw, 22px); }
      .hud-eyebrow { padding: 3px 8px; font-size: 10px; }

      .dock-inner { padding: 8px 10px 9px; gap: 8px; }
      .dock-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: stretch; }
      .dock-cta { min-width: 0; }
      .turn-toggle { width: 100%; min-width: 0; }
      .ai-cta { width: 100%; margin-left: 0; justify-content: center; }
    }

    @media (max-width: 599px) {
      .board-preview { padding: 10px; }
      .board-grid { min-width: 320px; }
      .actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .list-item { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
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
        <div class="hud-meta">
          <span class="hud-pill"><strong>Bag</strong> <span id="bagCount">100</span> tiles</span>
          <span class="hud-pill"><strong>Score</strong> <span id="scoreTotal">0</span> pts</span>
        </div>
      </div>
    </div>
  </header>

  <main class="app-shell" aria-label="TileMasterAI board">
    <div class="board-viewport" id="boardViewport">
      <div class="board-scale" id="boardScale">
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
                  <?php if ($rowIndex === 0): ?><span class="coordinate col"><?php echo $colLabel; ?></span><?php endif; ?>
                  <?php if ($colIndex === 0): ?><span class="coordinate row"><?php echo $rowLabel; ?></span><?php endif; ?>

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
        </div>
        <div class="dock-tooltip" id="rackHelpTip" role="tooltip">
          <strong>Rack tips</strong>
          <span>Drag tiles from the rack onto the board. Blanks turn blue after you set their letter.</span>
          <span>Drag tiles onto the board. Double-click a placed tile to send it back.</span>
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
      const aiModal = document.getElementById('aiModal');
      const aiCloseBtn = document.getElementById('closeAi');
      const aiListEl = document.getElementById('aiList');
      const aiStatusEl = document.getElementById('aiStatus');
      const aiStepEl = document.getElementById('aiStep');
      const aiSubtextEl = document.getElementById('aiSubtext');
      const rulesBtn = document.getElementById('openRules');
      const menuToggle = document.getElementById('menuToggle');
      const menuPanel = document.getElementById('menuPanel');
      const hudMenu = document.getElementById('hudMenu');
      const boardViewport = document.getElementById('boardViewport');
      const boardScaleEl = document.getElementById('boardScale');
      const boardChromeEl = document.getElementById('boardChrome');
      const rackHelpBtn = document.getElementById('rackHelp');
      const rackHelpTip = document.getElementById('rackHelpTip');

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
      let audioCtx;
      let baseScale = 1;
      let userZoom = 1;
      let pinchDistance = null;
      let panX = 0;
      let panY = 0;
      let isPanning = false;
      let panOrigin = { x: 0, y: 0 };

      const initAudio = () => {
        if (audioCtx) return audioCtx;
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return null;
        audioCtx = new AudioContextClass();
        return audioCtx;
      };

      const playTone = (frequency, duration = 0.2, type = 'sine', gainValue = 0.06) => {
        const ctx = initAudio();
        if (!ctx) return;
        if (ctx.state === 'suspended') {
          ctx.resume();
        }
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = type;
        oscillator.frequency.value = frequency;
        gain.gain.value = gainValue;
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.start();
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + duration);
        oscillator.stop(ctx.currentTime + duration + 0.05);
      };

      const playChord = (frequencies = []) => {
        frequencies.forEach((freq, index) => {
          setTimeout(() => playTone(freq, 0.22, 'triangle', 0.045), index * 50);
        });
      };

      const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

      const applyBoardTransform = () => {
        if (!boardScaleEl) return;
        const MIN_ZOOM = Math.min(baseScale || 1, 0.6);
        const MAX_ZOOM = Math.max(1.4, (baseScale || 1) * 2);
        const finalScale = clamp(baseScale * userZoom, MIN_ZOOM, MAX_ZOOM);
        boardScaleEl.style.transform = `translate(${panX}px, ${panY}px) scale(${finalScale})`;
      };

      const resizeBoardToViewport = () => {
        if (!boardViewport || !boardScaleEl || !boardChromeEl) return;
        const topHeight = document.querySelector('.hud-dock')?.getBoundingClientRect().height || 0;
        const bottomHeight = document.querySelector('.turn-dock')?.getBoundingClientRect().height || 0;
        const availableHeight = Math.max(360, window.innerHeight - topHeight - bottomHeight);

        boardViewport.style.height = `${availableHeight}px`;

        const viewportWidth = boardViewport.getBoundingClientRect().width || document.documentElement.clientWidth;
        const previousTransform = boardScaleEl.style.transform;
        boardScaleEl.style.transform = 'none';
        const boardRect = boardChromeEl.getBoundingClientRect();
        boardScaleEl.style.transform = previousTransform;

        const heightScale = boardRect.height ? Math.min(1, availableHeight / boardRect.height) : 1;
        const widthScale = boardRect.width ? Math.min(1, viewportWidth / boardRect.width) : 1;
        baseScale = Math.min(heightScale, widthScale, 1);
        panX = 0;
        panY = 0;
        applyBoardTransform();
      };

      const adjustZoom = (factor) => {
        const MIN_ZOOM = Math.min(baseScale || 1, 0.6);
        const MAX_ZOOM = Math.max(1.4, (baseScale || 1) * 2);
        const minFactor = MIN_ZOOM / (baseScale || 1);
        const maxFactor = MAX_ZOOM / (baseScale || 1);
        userZoom = clamp(userZoom * factor, minFactor, maxFactor);
        applyBoardTransform();
      };

      const handleWheelZoom = (event) => {
        if (!boardScaleEl || !boardViewport) return;
        if (event.ctrlKey || event.metaKey) return;
        event.preventDefault();
        const direction = Math.sign(event.deltaY);
        adjustZoom(direction > 0 ? 0.92 : 1.08);
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
          return;
        }

        const [touch] = event.touches;
        const target = event.target;
        if (!touch || (target.closest('.tile') || target.closest('.rack-tile'))) return;

        isPanning = true;
        panOrigin = { x: touch.clientX - panX, y: touch.clientY - panY };
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
            adjustZoom(factor);
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
        applyBoardTransform();
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
        boardViewport.classList.add('dragging');
      };

      const continueBoardPan = (event) => {
        if (!isPanning) return;
        panX = event.clientX - panOrigin.x;
        panY = event.clientY - panOrigin.y;
        applyBoardTransform();
      };

      const endBoardPan = () => {
        if (!isPanning) return;
        isPanning = false;
        boardViewport.classList.remove('dragging');
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
        const index = Math.floor(Math.random() * bag.length);
        const [letter] = bag.splice(index, 1);
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
        }
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
        if (turnTitleEl) {
          turnTitleEl.textContent = turnActive ? 'Submit move' : 'Start turn';
        }
        if (turnSubtitleEl) {
          turnSubtitleEl.textContent = turnActive ? 'Lock tiles & score it' : 'Draw tiles and place your word';
        }
        toggleBtn.setAttribute('aria-pressed', turnActive ? 'true' : 'false');
      };

      const updateAiButton = () => {
        if (!aiBtn) return;
        const disabled = !turnActive;
        aiBtn.disabled = disabled;
        aiBtn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        aiBtn.classList.toggle('disabled', disabled);
      };

      const updateBagCount = () => {
        bagCountEl.textContent = bag.length;
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
        playTone(360, 0.14, 'triangle', 0.05);
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
          const response = await fetch('index.php?action=suggestions', {
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

      const animateTileToCell = (tile, cell, row, col) => {
        if (!tile) return;
        const source = document.querySelector(`[data-tile-id="${tile.id}"][data-location="rack"]`);
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
        const availableIds = rack.map((tile) => tile.id);
        const placements = [];
        const placementLetters = new Map();
        const blankPositions = new Set();

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
          const foundId = availableIds.find((id) => {
            const candidate = findTile(id);
            if (!candidate) return false;
            if (needsBlank) return candidate.isBlank;
            if (!candidate.isBlank && candidate.letter.toUpperCase() === letterForPosition) return true;
            if (candidate.isBlank) return true;
            return false;
          });

          if (!foundId) {
            setMessage(`Missing the tile “${letterForPosition}” to build ${word}.`, 'error');
            return;
          }

          availableIds.splice(availableIds.indexOf(foundId), 1);
          placements.push({ tileId: foundId, letter: letterForPosition, row, col, needsBlank });
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
            animateTileToCell(tile, cell, placement.row, placement.col);
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
        document.body.classList.remove('modal-open');
        clearAiTimers();
        if (aiListEl) {
          aiListEl.innerHTML = '';
        }
      };

      const renderAiSuggestions = (list) => {
        if (!aiListEl) return;
        aiListEl.innerHTML = '';

        list.forEach((move, index) => {
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

        aiStepEl.textContent = steps[0];
        if (aiSubtextEl) {
          aiSubtextEl.textContent = 'Returning tiles and brainstorming the best openings for you…';
        }

        clearAiTimers();
        aiRevealTimeout = setTimeout(async () => {
          await fetchAiSuggestions();
          const playable = (latestSuggestions || []).filter((move) => suggestionPlayable(move));
          renderAiSuggestions(playable);
          if (aiSubtextEl) {
            aiSubtextEl.textContent = playable.length
              ? 'Suggestions ready! Tap a move to load it and keep playing.'
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
            playChord([480, 640]);
          }
        } else {
          setMessage('Bag is empty—continue with the tiles you have.', 'success');
        }

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
        finalizeTurn(total, placements.length === 7);
        return true;
      };

      const finalizeTurn = (turnScore, bingo) => {
        tilesPlacedThisTurn().forEach(({ tile }) => {
          tile.locked = true;
          tile.justPlaced = false;
        });
        totalScore += turnScore;
        scoreEl.textContent = totalScore;
        firstTurn = false;
        turnActive = false;
        updateTurnButton();
        updateAiButton();
        renderBoard();
        const scoreNote = bingo ? ' + 50-point bingo!' : '';
        setMessage(`Move accepted for ${turnScore} points${scoreNote}. Draw to refill for the next turn.`, 'success');
        playChord([392, 523, 659]);
      };

      const resetBoard = () => {
        tileId = 0;
        rack = [];
        board = Array.from({ length: BOARD_SIZE }, () => Array.from({ length: BOARD_SIZE }, () => null));
        totalScore = 0;
        firstTurn = true;
        turnActive = false;
        buildBag();
        updateBagCount();
        renderRack();
        renderBoard();
        scoreEl.textContent = '0';
        setMessage('Board reset. Start a turn to draw tiles.', 'success');
        updateTurnButton();
        updateAiButton();
        playTone(196, 0.28, 'sawtooth', 0.07);
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
        };

        const handleAiClose = () => {
          closeAiModal();
        };

      if (toggleBtn) toggleBtn.addEventListener('click', handleToggleClick);
      if (resetBtn) resetBtn.addEventListener('click', () => { closeHudMenu(); resetBoard(); });
      if (aiBtn) aiBtn.addEventListener('click', handleAiClick);
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
      }

      window.addEventListener('resize', resizeBoardToViewport);

      buildBag();
      updateBagCount();
      renderRack();
      renderBoard();
      updateTurnButton();
      updateAiButton();
      resizeBoardToViewport();
      setTimeout(resizeBoardToViewport, 120);
      setupDragAndDrop();
      loadDictionary();
    });
  </script>

  <div class="modal-backdrop" id="aiModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="aiTitle">
      <div class="modal-header">
        <h3 id="aiTitle">AI suggested moves</h3>
        <button class="modal-close" type="button" id="closeAi" aria-label="Close AI suggestions">×</button>
      </div>
      <div class="ai-status" id="aiStatus">
        <span id="aiStep">Warming up the move engine…</span>
        <span class="ai-dots" aria-hidden="true"><span></span><span></span><span></span></span>
        <p class="ai-meta" id="aiSubtext">Returning tiles to your rack and dreaming up moves.</p>
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
        document.body.classList.toggle('modal-open', open);

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
