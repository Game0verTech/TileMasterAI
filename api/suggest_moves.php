<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
require dirname(__DIR__) . '/src/bootstrap.php';

use TileMasterAI\Game\Board;
use TileMasterAI\Game\Dictionary;
use TileMasterAI\Game\MoveGenerator;
use TileMasterAI\Game\Rack;
use TileMasterAI\Game\Tile;

header('Content-Type: application/json');

$payload = json_decode(file_get_contents('php://input') ?: 'null', true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

$boardData = $payload['board'] ?? [];
$rackData = $payload['rack'] ?? [];
$limit = isset($payload['limit']) ? (int) $payload['limit'] : 5;

try {
    $board = buildBoardFromPayload($boardData);
    $rack = buildRackFromPayload($rackData);
} catch (Throwable $exception) {
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
    exit;
}

$dictionaryPath = getenv('DICTIONARY_PATH') ?: dirname(__DIR__) . '/data/dictionary-mini.txt';
$dictionary = new Dictionary($dictionaryPath);

$generator = new MoveGenerator($board, $dictionary);
$moves = $generator->generateMoves($rack, $limit);

$response = [
    'success' => true,
    'moves' => array_map(static fn ($move) => serializeMove($move), $moves),
    'message' => $moves === [] ? 'No moves available' : 'OK',
];

echo json_encode($response);

/**
 * @param array<string, mixed> $boardData
 */
function buildBoardFromPayload(array $boardData): Board
{
    $premiumLayout = $boardData['premiumLayout'] ?? Board::standardLayout();
    $board = new Board($premiumLayout);

    $tiles = $boardData['tiles'] ?? [];
    foreach ($tiles as $tileData) {
        $row = (int) ($tileData['row'] ?? 0);
        $column = (int) ($tileData['column'] ?? 0);
        $letter = isset($tileData['letter']) ? strtoupper((string) $tileData['letter']) : '';
        $isBlank = (bool) ($tileData['isBlank'] ?? false);

        if ($row < 1 || $row > Board::ROWS || $column < 1 || $column > Board::COLUMNS) {
            throw new InvalidArgumentException('Board tile outside of grid.');
        }

        if ($letter === '') {
            continue;
        }

        $tile = new Tile($letter, $isBlank);
        $board->placeTile($row, $column, $tile);
    }

    return $board;
}

/**
 * @param array<int, array<string, mixed>> $rackData
 */
function buildRackFromPayload(array $rackData): Rack
{
    $tiles = [];

    foreach ($rackData as $tileData) {
        $letter = isset($tileData['letter']) ? strtoupper((string) $tileData['letter']) : '';
        $isBlank = (bool) ($tileData['isBlank'] ?? false);

        if ($letter === '') {
            continue;
        }

        $tiles[] = new Tile($letter, $isBlank);
    }

    return new Rack($tiles);
}

/**
 * @param array<string, mixed> $move
 * @return array<string, mixed>
 */
function serializeMove(array $move): array
{
    $placements = array_map(static function ($placement) {
        [$row, $column] = Board::parseCoordinate($placement['coord']);
        $tile = $placement['tile'];

        return [
            'coord' => $placement['coord'],
            'row' => $row,
            'column' => $column,
            'letter' => $tile->letter(),
            'isBlank' => $tile->isBlank(),
            'value' => $tile->value(),
        ];
    }, $move['placements']);

    return [
        'word' => $move['word'],
        'direction' => $move['direction'],
        'start' => $move['start'],
        'score' => $move['score'],
        'bingo' => $move['bingo'],
        'mainWordScore' => $move['mainWordScore'],
        'crossWords' => $move['crossWords'],
        'placements' => $placements,
    ];
}
