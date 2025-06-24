<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';


$app = AppFactory::create();
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});
$app->setBasePath('/Fortis/api/public');

$app->addRoutingMiddleware();

$app->addErrorMiddleware(true, true, true);

define('DATA_PATH', realpath(__DIR__ . '/../../data'));

function loadGames(PDO $pdo) {
    $stmt = $pdo->query("SELECT * FROM games");
    $games = $stmt->fetchAll();

    foreach ($games as &$game) {
    foreach (['board_resources', 'occupied_spaces', 'player_farmers', 'player_structures', 'players', 'player_resources'] as $field) {
        $game[$field] = json_decode($game[$field] ?? '{}', true);
    }
    // ✅ Map penalty explicit
    $game['penalty'] = [
        $game['player1_id'] => $game['penalty_player1'] ?? 0,
        $game['player2_id'] => $game['penalty_player2'] ?? 0
    ];
}

    return $games;
}



define('VALID_RESOURCES', [
    'wood', 'clay', 'food', 'reed', 'stone',
    'sheep', 'boar', 'cow'
]);

define('VALID_ACTION_SPACES', array_merge(VALID_RESOURCES, ['build-house', 'add-farmer']));

function switchTurn(PDO $pdo, &$game) {
    $p1 = $game['player1_id'];
    $p2 = $game['player2_id'];
    $cur = $game['current_turn'];
    $other = ($cur === $p1) ? $p2 : $p1;

    if (!isset($game['player_farmers'][$cur])) $game['player_farmers'][$cur] = 0;
    $game['player_farmers'][$cur]--;

    if (!isset($game['player_farmers'][$other])) $game['player_farmers'][$other] = 0;

    if ($game['player_farmers'][$other] > 0) {
        $game['current_turn'] = $other;
    } elseif ($game['player_farmers'][$cur] > 0) {
        $game['current_turn'] = $cur;
    } else {
        // End round
        $game['round']++;

        addResourcesToBoard($game);

        $game['occupied_spaces'] = array_fill_keys(VALID_ACTION_SPACES, null);

        $p1_houses = isset($game['player_structures'][$p1]['houses']) ? count($game['player_structures'][$p1]['houses']) : 1;
        $p2_houses = isset($game['player_structures'][$p2]['houses']) ? count($game['player_structures'][$p2]['houses']) : 1;

        $game['player_farmers'][$p1] = $p1_houses;
        $game['player_farmers'][$p2] = $p2_houses;

        $game['current_turn'] = $p1;

        // ✅ Dacă e rundă de mâncare:
        if (in_array($game['round'], [4, 7, 9, 11, 13, 14])) {
            feedPlayerAuto($pdo, $game, $p1);
            feedPlayerAuto($pdo, $game, $p2);
        }
    }

    $stmt = $pdo->prepare("
        UPDATE games
        SET round = :round,
            current_turn = :current_turn,
            occupied_spaces = :occupied_spaces,
            player_farmers = :player_farmers,
            board_resources = :board_resources
        WHERE id = :id
    ");
    $stmt->execute([
        ':round' => $game['round'],
        ':current_turn' => $game['current_turn'],
        ':occupied_spaces' => json_encode($game['occupied_spaces']),
        ':player_farmers' => json_encode($game['player_farmers']),
        ':board_resources' => json_encode($game['board_resources']),
        ':id' => $game['id']
    ]);
}

/**
 * Feed automat la începutul rundelor de hrană.
 * - Consumă food + sacrifică animale dacă e nevoie.
 * - Dacă tot nu e destul: aplică penalizare.
 */
function feedPlayerAuto(PDO $pdo, &$game, $playerId) {
    $validResources = VALID_RESOURCES;
    $animalFoodValue = ['cow' => 3, 'boar' => 2, 'sheep' => 1];

    // Hrană necesară = numărul de fermieri * 2 (exemplu)
    $requiredFood = ($game['player_farmers'][$playerId] ?? 0) * 2;

    if (!isset($game['player_resources'][$playerId])) {
        $game['player_resources'][$playerId] = array_fill_keys($validResources, 0);
    }

    $resources = &$game['player_resources'][$playerId];
    $foodAvailable = $resources['food'] ?? 0;

    $remainingNeed = $requiredFood - $foodAvailable;
    $foodFromAnimals = 0;

    if ($remainingNeed > 0) {
        // Sacrifică animale în ordine
        foreach ($animalFoodValue as $animal => $value) {
            while ($resources[$animal] > 0 && $foodFromAnimals < $remainingNeed) {
                $resources[$animal]--;
                $foodFromAnimals += $value;
            }
        }
    }

    $totalFood = $foodAvailable + $foodFromAnimals;

    if ($totalFood >= $requiredFood) {
        $resources['food'] = $totalFood - $requiredFood;
    } else {
        $resources['food'] = 0;
        $missing = $requiredFood - $totalFood;
        if ($playerId == $game['player1_id']) {
            $game['penalty_player1'] = ($game['penalty_player1'] ?? 0) + $missing;
        } else {
            $game['penalty_player2'] = ($game['penalty_player2'] ?? 0) + $missing;
        }
    }

    // Update penalizare și resurse în DB
    $stmt = $pdo->prepare("
        UPDATE games 
        SET penalty_player1 = :pen1, penalty_player2 = :pen2, player_resources = :player_resources
        WHERE id = :id
    ");
    $stmt->execute([
        ':pen1' => $game['penalty_player1'] ?? 0,
        ':pen2' => $game['penalty_player2'] ?? 0,
        ':player_resources' => json_encode($game['player_resources']),
        ':id' => $game['id']
    ]);
}

function jsonResponse(Response $response, $data, $status = 200) {
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
}



function loadPlayers(PDO $pdo) {
    $stmt = $pdo->query("SELECT * FROM players");
    return $stmt->fetchAll();
}

function addResourcesToBoard(&$game) {
    foreach (VALID_RESOURCES as $res) {
        $game['board_resources'][$res] += 1; // sau regulă specială (ex: lemn +1, argilă +1, mâncare nu)
    }
}

$app->get('/', function ($request, $response, $args) {
    $response->getBody()->write("✅ API OK - vezi /players, /games etc.");
    return $response;
});



$app->get('/players', function (Request $request, Response $response) use ($pdo) {
    $players = loadPlayers($pdo);
    $response->getBody()->write(json_encode($players));
    return $response->withHeader('Content-Type', 'application/json');
});



$app->post('/players', function (Request $request, Response $response) use ($pdo) {
    $data = json_decode($request->getBody()->getContents(), true);

    if (!isset($data['name']) || !isset($data['active'])) {
        return $response->withStatus(400)->withJson(['error' => 'Date invalide']);
    }

    $stmt = $pdo->prepare("INSERT INTO players (name, active) VALUES (:name, :active)");
    $stmt->execute([
        ':name' => $data['name'],
        ':active' => $data['active']
    ]);

    $newId = $pdo->lastInsertId();

    $newPlayer = [
        'id' => $newId,
        'name' => $data['name'],
        'active' => (bool)$data['active']
    ];

    $response->getBody()->write(json_encode($newPlayer));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});





$app->get('/players/{playerId}', function (Request $request, Response $response, array $args) use ($pdo){
    $players = loadPlayers($pdo);
    $playerId = (int)$args['playerId'];  

    foreach ($players as $player) {
        if ($player['id'] === $playerId) {
            $response->getBody()->write(json_encode($player));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    $response->getBody()->write(json_encode(['error' => 'Jucător inexistent']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});

$app->put('/players/{playerId}', function (Request $request, Response $response, array $args) use ($pdo) {
    $playerId = (int)$args['playerId'];
    $data = json_decode($request->getBody()->getContents(), true);

    if (!isset($data['name']) && !isset($data['active'])) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Trebuie să furnizezi name și/sau active']));
    }

    $fields = [];
    $params = [':id' => $playerId];

    if (isset($data['name'])) {
        $fields[] = "name = :name";
        $params[':name'] = $data['name'];
    }
    if (isset($data['active'])) {
        $fields[] = "active = :active";
        $params[':active'] = $data['active'];
    }

    $sql = "UPDATE players SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Load updated player
    $stmt = $pdo->prepare("SELECT * FROM players WHERE id = :id");
    $stmt->execute([':id' => $playerId]);
    $player = $stmt->fetch();

    if (!$player) {
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Jucător inexistent']));
    }

    $response->getBody()->write(json_encode($player));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->put('/players/{playerId}/status', function (Request $request, Response $response, array $args) use ($pdo) {
    $playerId = (int)$args['playerId'];
    $data = json_decode($request->getBody()->getContents(), true);

    if (!isset($data['active'])) {
        $response->getBody()->write(json_encode(['error' => 'Câmpul "active" este necesar']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $players = loadPlayers($pdo);

    foreach ($players as &$player) {
        if ($player['id'] === $playerId) {
            $player['active'] = (bool)$data['active'];
            file_put_contents(DATA_PATH . '/players.json', json_encode($players, JSON_PRETTY_PRINT));
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    $response->getBody()->write(json_encode(['error' => 'Jucător inexistent']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});



$app->get('/games', function (Request $request, Response $response, array $args) use ($pdo){
    $games = loadGames($pdo);
    $validResources = VALID_RESOURCES;         // ✅ OK


    foreach ($games as &$game) {
        foreach (['player1_id', 'player2_id'] as $pidKey) {
            $pid = $game[$pidKey] ?? null;
            if ($pid !== null) {
                if (!isset($game['player_resources'][$pid])) {
                    $game['player_resources'][$pid] = array_fill_keys($validResources, 0);
                } else {
                    // Asigură-te că toate resursele sunt setate
                    foreach ($validResources as $res) {
                        if (!isset($game['player_resources'][$pid][$res])) {
                            $game['player_resources'][$pid][$res] = 0;
                        }
                    }
                }
            }
        }
    }

    $response->getBody()->write(json_encode($games));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/games', function (Request $request, Response $response) use ($pdo) {
    $data = json_decode($request->getBody()->getContents(), true);

    // 1️⃣ Verifică player1_id prezent
    if (!isset($data['player1_id'])) {
        $response->getBody()->write(json_encode(['error' => 'Lipsește player1_id']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // 2️⃣ Verifică player1 în DB și să fie activ
    $stmt = $pdo->prepare("SELECT * FROM players WHERE id = :id");
    $stmt->execute([':id' => $data['player1_id']]);
    $player = $stmt->fetch();

    if (!$player || !$player['active']) {
        $response->getBody()->write(json_encode(['error' => 'Jucătorul 1 nu există sau nu e activ']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // 3️⃣ Construiește starea inițială a jocului
    $player1Id = $player['id'];

    $player1Data = [
        'id' => $player['id'],
        'name' => $player['name']
    ];

    $player1Houses = [
        ['type' => 'wood', 'round_built' => 1],
        ['type' => 'wood', 'round_built' => 1]
    ];

    $boardResources = [
        'wood' => 0,
        'clay' => 0,
        'food' => 5,
        'reed' => 0,
        'stone' => 0,
        'sheep' => 0,
        'boar' => 0,
        'cow' => 0
    ];

    $occupiedSpaces = array_fill_keys(VALID_ACTION_SPACES, null);

    $playerFarmers = [
        $player1Id => 2
    ];

    $playerStructures = [
        $player1Id => [
            'houses' => $player1Houses
        ]
    ];

    $playersList = [$player1Data];

    // 4️⃣ INSERĂ în DB (tabela games)
    $stmt = $pdo->prepare("
        INSERT INTO games 
            (player1_id, player2_id, round, current_turn, active, score_player1, score_player2, 
            board_resources, occupied_spaces, player_farmers, player_structures, players)
        VALUES 
            (:player1_id, NULL, 1, :current_turn, 0, 0, 0, 
            :board_resources, :occupied_spaces, :player_farmers, :player_structures, :players)
    ");

    $stmt->execute([
        ':player1_id' => $player1Id,
        ':current_turn' => $player1Id,
        ':board_resources' => json_encode($boardResources),
        ':occupied_spaces' => json_encode($occupiedSpaces),
        ':player_farmers' => json_encode($playerFarmers),
        ':player_structures' => json_encode($playerStructures),
        ':players' => json_encode($playersList)
    ]);

    $newId = $pdo->lastInsertId();

    // 5️⃣ Construiește JSON-ul final pentru răspuns
    $newGame = [
        'id' => (int)$newId,
        'player1_id' => $player1Id,
        'player2_id' => null,
        'round' => 1,
        'current_turn' => $player1Id,
        'active' => false,
        'score_player1' => 0,
        'score_player2' => 0,
        'board_resources' => $boardResources,
        'occupied_spaces' => $occupiedSpaces,
        'player_farmers' => $playerFarmers,
        'player_structures' => $playerStructures,
        'players' => $playersList
    ];

    // 6️⃣ Răspunde OK
    $response->getBody()->write(json_encode($newGame));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});






$app->post('/games/{gameId}/start', function (Request $request, Response $response, array $args) use ($pdo) {
    $gameId = (int)$args['gameId'];

    // 1️⃣ Verifică jocul în DB
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = :id");
    $stmt->execute([':id' => $gameId]);
    $game = $stmt->fetch();

    if (!$game) {
        $response->getBody()->write(json_encode(['error' => 'Joc inexistent']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // 2️⃣ Verifică jucători
    if (!$game['player1_id'] || !$game['player2_id']) {
        $response->getBody()->write(json_encode(['error' => 'Jocul nu are 2 jucători.']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // 3️⃣ Verifică dacă e deja activ
    if ($game['active']) {
        $response->getBody()->write(json_encode(['error' => 'Jocul este deja activ.']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // 4️⃣ Resetează spațiile și resursele
    $occupiedSpaces = array_fill_keys(VALID_ACTION_SPACES, null);
    $boardResources = [
        'wood' => 0,
        'clay' => 0,
        'food' => 5,
        'reed' => 0,
        'stone' => 0,
        'sheep' => 0,
        'boar' => 0,
        'cow' => 0
    ];

    // 5️⃣ Update DB
    $stmt = $pdo->prepare("
        UPDATE games 
        SET 
            active = 1,
            round = 1,
            current_turn = :current_turn,
            occupied_spaces = :occupied_spaces,
            board_resources = :board_resources
        WHERE id = :id
    ");
    $stmt->execute([
        ':current_turn' => $game['player1_id'],
        ':occupied_spaces' => json_encode($occupiedSpaces),
        ':board_resources' => json_encode($boardResources),
        ':id' => $gameId
    ]);

    // 6️⃣ Reîncarcă jocul actualizat
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = :id");
    $stmt->execute([':id' => $gameId]);
    $updatedGame = $stmt->fetch();

    // Decodifică câmpurile JSON
    foreach (['board_resources', 'occupied_spaces', 'player_farmers', 'player_structures', 'players'] as $field) {
        if (isset($updatedGame[$field])) {
            $updatedGame[$field] = json_decode($updatedGame[$field], true);
        }
    }

    // 7️⃣ Răspunde
    $response->getBody()->write(json_encode([
        'message' => 'Jocul a început',
        'game' => $updatedGame
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});




$app->get('/games/active', function (Request $request, Response $response) use ($pdo) {
    $games = loadGames($pdo);
    $activeGames = [];

    foreach ($games as $game) {
        if ((int)$game['active'] === 1) {  // <<=== AICI e fix-ul
            $activeGames[] = $game;
        }
    }

    $response->getBody()->write(json_encode($activeGames));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/games/{id}', function (Request $request, Response $response, $args) {
    $gameId = $args['id'];

    // EXEMPLE: Simulează player2_id dacă e activ
    $game = [
        'id' => $gameId,
        'player1_id' => 1,
        'player2_id' => 2, // sau null dacă încă aștepți
        'active' => 1,
        'player1_name' => 'Player1',
        'player2_name' => 'Player2'
    ];

    $response->getBody()->write(json_encode($game));
    return $response->withHeader('Content-Type', 'application/json');
});



$app->delete('/games/{gameId}', function (Request $request, Response $response, array $args) use ($pdo) {
    $gameId = (int)$args['gameId'];

    // Verifică jocul în DB
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = :id");
    $stmt->execute([':id' => $gameId]);
    $game = $stmt->fetch();

    if (!$game) {
        $response->getBody()->write(json_encode(['error' => 'Joc inexistent']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // Șterge jocul din DB
    $stmt = $pdo->prepare("DELETE FROM games WHERE id = :id");
    $stmt->execute([':id' => $gameId]);

    // Șterge și acțiunile asociate
    $stmt = $pdo->prepare("DELETE FROM actions WHERE game_id = :id");
    $stmt->execute([':id' => $gameId]);

    // Reîncarcă și rescrie JSON-ul corect
    $games = loadGames($pdo);
    file_put_contents(DATA_PATH . '/games.json', json_encode($games, JSON_PRETTY_PRINT));

    return $response->withStatus(204);
});




$app->put('/games/{gameId}', function (Request $request, Response $response, array $args) use ($pdo) {
    $gameId = (int)$args['gameId'];
    $data = json_decode($request->getBody()->getContents(), true);

    // 1️⃣ Verifică dacă jocul există
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = :id");
    $stmt->execute([':id' => $gameId]);
    $game = $stmt->fetch();

    if (!$game) {
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Joc inexistent']));
    }

    // 2️⃣ Construiește update din câmpurile permise
    $fields = [];
    $params = [':id' => $gameId];
    foreach (['round', 'current_turn', 'score_player1', 'score_player2', 'active'] as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }

    if (empty($fields)) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Nicio modificare']));
    }

    // 3️⃣ Execută update în DB
    $sql = "UPDATE games SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // 4️⃣ Reîncarcă jocul din DB
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = :id");
    $stmt->execute([':id' => $gameId]);
    $updatedGame = $stmt->fetch();

    // 5️⃣ Actualizează și games.json dacă îl folosești
    $games = loadGames($pdo); // ia toate din DB din nou
    file_put_contents(DATA_PATH . '/games.json', json_encode($games, JSON_PRETTY_PRINT));

    // 6️⃣ Returnează jocul actualizat
    $response->getBody()->write(json_encode($updatedGame));
    return $response->withHeader('Content-Type', 'application/json');
});






$app->get('/games/{gameId}/summary', function (Request $request, Response $response, array $args) use ($pdo) {
    $games = loadGames($pdo);
    $gameId = (int)$args['gameId'];

    foreach ($games as $game) {
        if ($game['id'] === $gameId) {
            // 👇 verificare dacă jocul este încă activ
            if (!isset($game['active']) || $game['active']) {
                $response->getBody()->write(json_encode(['error' => 'Jocul este încă activ.']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $summary = [
                'winner_id' => null,
                'loser_id' => null,
                'rounds_played' => $game['round'],
                'score' => [
                    'player1' => $game['score_player1'] ?? null,
                    'player2' => $game['score_player2'] ?? null
                ],
                'draw' => false
            ];

            if (isset($game['score_player1']) && isset($game['score_player2'])) {
                if ($game['score_player1'] > $game['score_player2']) {
                    $summary['winner_id'] = $game['player1_id'];
                    $summary['loser_id'] = $game['player2_id'];
                } elseif ($game['score_player2'] > $game['score_player1']) {
                    $summary['winner_id'] = $game['player2_id'];
                    $summary['loser_id'] = $game['player1_id'];
                } else {
                    $summary['draw'] = true;
                }
            }

            $response->getBody()->write(json_encode($summary));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    $response->getBody()->write(json_encode(['error' => 'Joc inexistent']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});

$app->post('/games/{gameId}/join', function (Request $request, Response $response, array $args) {
    $gameId = (int)$args['gameId'];
    $data = json_decode($request->getBody()->getContents(), true);

    if (!isset($data['player_id'])) {
        $response->getBody()->write(json_encode(['error' => 'Lipsește player_id']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $playerId = (int)$data['player_id'];
    $players = loadPlayers($pdo);
    $games = loadGames($pdo);

    // Căutăm jucătorul
    $playerData = null;
    foreach ($players as $p) {
        if ($p['id'] === $playerId && $p['active']) {
            $playerData = $p;
            break;
        }
    }

    if (!$playerData) {
        $response->getBody()->write(json_encode(['error' => 'Jucătorul nu există sau nu este activ']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    foreach ($games as &$game) {
        if ($game['id'] === $gameId) {
            // Verificăm dacă jucătorul este deja în joc
            if (
                $playerId === $game['player1_id'] ||
                $playerId === $game['player2_id']
            ) {
                $response->getBody()->write(json_encode(['error' => 'Jucătorul este deja în joc']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Verificăm dacă jocul are deja ambii jucători
            if (isset($game['player1_id']) && isset($game['player2_id'])) {
                $response->getBody()->write(json_encode(['error' => 'Jocul are deja 2 jucători']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Alocăm player1 sau player2
            if (!isset($game['player1_id'])) {
                $game['player1_id'] = $playerId;
                $game['current_turn'] = $playerId;
            } elseif (!isset($game['player2_id'])) {
                $game['player2_id'] = $playerId;
            }

            // Inițializăm lista players dacă nu există
            if (!isset($game['players']) || !is_array($game['players'])) {
                $game['players'] = [];
            }

            // Adăugăm jucătorul detaliat în lista players doar dacă nu e deja acolo
            $alreadyInList = false;
            foreach ($game['players'] as $p) {
                if ($p['id'] === $playerData['id']) {
                    $alreadyInList = true;
                    break;
                }
            }

            if (!$alreadyInList) {
                $game['players'][] = [
                    'id' => $playerData['id'],
                    'name' => $playerData['name']
                ];
            }

            // Activăm jocul dacă sunt doi jucători
            if (isset($game['player1_id']) && isset($game['player2_id'])) {
                $game['active'] = true;
            }

            file_put_contents(DATA_PATH . '/games.json', json_encode($games, JSON_PRETTY_PRINT));
            $response->getBody()->write(json_encode($game));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }
    }

    $response->getBody()->write(json_encode(['error' => 'Joc inexistent']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});








$app->get('/games/{gameId}/rules', function (Request $request, Response $response, array $args) use ($pdo){
    $games = loadGames($pdo);
    $gameId = (int)$args['gameId'];

    foreach ($games as $game) {
        if ($game['id'] === $gameId) {
            // Regulile jocului
            $rules = [
                'total_rounds' => 14,
                'max_players' => 2,
                'starting_resources' => [
                    'wood' => 0,
                    'clay' => 0,
                    'food' => 5,
                    'reed' => 0,
                    'stone' => 0,
                    'sheep' => 0,
                    'boar' => 0,
                    'cow' => 0
                ]
            ];

            $response->getBody()->write(json_encode($rules));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    // Dacă jocul nu există
    $response->getBody()->write(json_encode(['error' => 'Joc inexistent']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});

$app->get('/games/{gameId}/farmers', function (Request $request, Response $response, array $args)  use ($pdo){
    $games = loadGames($pdo);
    $gameId = (int)$args['gameId'];

    foreach ($games as $game) {
        if ($game['id'] === $gameId) {
            $farmers = $game['player_farmers'] ?? [];
            $response->getBody()->write(json_encode($farmers));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    $response->getBody()->write(json_encode(['error' => 'Joc inexistent']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});


$app->get('/games/{gameId}/board/resources', function (Request $request, Response $response, array $args) use ($pdo) {
    $games = loadGames($pdo);
    $gameId = (int)$args['gameId'];

    foreach ($games as $game) {
        if ($game['id'] === $gameId) {
            // Verificăm dacă există resurse în joc
            if (isset($game['board_resources'])) {
                $resources = $game['board_resources'];
            } else {
                // Dacă nu există, returnăm resurse goale sau default
                $resources = [
                    'wood' => 0,
                    'clay' => 0,
                    'food' => 0,
                    'reed' => 0,
                    'stone' => 0,
                    'sheep' => 0,
                    'boar' => 0,
                    'cow' => 0
                ];
            }

            $response->getBody()->write(json_encode($resources));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    // Dacă jocul nu există
    $response->getBody()->write(json_encode(['error' => 'Joc inexistent']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});

$app->put('/games/{gameId}/board/resources', function (Request $request, Response $response, array $args) use ($pdo) {
    $gameId = (int)$args['gameId'];
    $data = json_decode($request->getBody()->getContents(), true);

    $validResources = VALID_RESOURCES;

    // Ia jocul direct din DB
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = :id");
    $stmt->execute([':id' => $gameId]);
    $game = $stmt->fetch();

    if (!$game) {
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Joc inexistent']));
    }

    $boardResources = json_decode($game['board_resources'], true) ?? array_fill_keys($validResources, 0);

    foreach ($validResources as $res) {
        if (isset($data[$res]) && is_numeric($data[$res])) {
            $boardResources[$res] += (int)$data[$res];
        }
    }

    // UPDATE în DB
    $stmt = $pdo->prepare("UPDATE games SET board_resources = :board_resources WHERE id = :id");
    $stmt->execute([
        ':board_resources' => json_encode($boardResources),
        ':id' => $gameId
    ]);

    // ✔️ Răspuns
    $response->getBody()->write(json_encode([
        'message' => 'Resursele au fost actualizate',
        'board_resources' => $boardResources
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});




$app->post('/games/{gameId}/players/{playerId}/actions/gather', function (Request $request, Response $response, array $args) use ($pdo) {
    $gameId = (int)$args['gameId'];
    $playerId = (int)$args['playerId'];
    $data = json_decode($request->getBody()->getContents(), true);

    $validResources = VALID_RESOURCES;

    // 1️⃣ Validare input
    if (!isset($data['resource_type']) || !in_array($data['resource_type'], $validResources)) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Tip de resursă invalid sau lipsă']));
    }

    $resource = $data['resource_type'];
    $games = loadGames($pdo);

    foreach ($games as &$game) {
        if ($game['id'] === $gameId) {
            // 2️⃣ Verifică jucătorul
            if ($playerId !== $game['player1_id'] && $playerId !== $game['player2_id']) {
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Jucătorul nu face parte din joc']));
            }

            // 3️⃣ Verifică spațiul
            if (isset($game['occupied_spaces'][$resource]) && $game['occupied_spaces'][$resource] !== null) {
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Spațiul este deja ocupat de alt jucător.']));
            }

            // 4️⃣ Verifică resursa disponibilă
            $availableAmount = $game['board_resources'][$resource] ?? 0;
            if ($availableAmount < 1) {
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Nu există resurse disponibile pe tablă']));
            }

            // 5️⃣ Marchează spațiul ca ocupat
            $game['occupied_spaces'][$resource] = $playerId;

            // 6️⃣ Inițializează resursele jucătorului dacă lipsesc
            if (!isset($game['player_resources'][$playerId])) {
                $game['player_resources'][$playerId] = array_fill_keys($validResources, 0);
            }

            // 7️⃣ Transferă resursa și golește spațiul de pe tablă
            $game['player_resources'][$playerId][$resource] += $availableAmount;
            $game['board_resources'][$resource] = 0;

            // 8️⃣ LOGHEAZĂ în tabela actions
            $stmt = $pdo->prepare("
                INSERT INTO actions (game_id, player_id, type, data)
                VALUES (:game_id, :player_id, :type, :data)
            ");
            $stmt->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
                ':type' => 'gather',
                ':data' => json_encode([
                    'resource_type' => $resource,
                    'amount' => $availableAmount
                ])
            ]);

            // 9️⃣ Schimbă rândul și salvează
            switchTurn($pdo,$game);
            file_put_contents(DATA_PATH . '/games.json', json_encode($games, JSON_PRETTY_PRINT));

            return $response->withHeader('Content-Type', 'application/json')
                ->write(json_encode([
                    'message' => "Jucătorul a adunat $availableAmount x $resource",
                    'board_resources' => $game['board_resources'],
                    'player_resources' => $game['player_resources'][$playerId]
                ]));
        }
    }

    // Dacă nu s-a găsit jocul
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
        ->write(json_encode(['error' => 'Joc inexistent']));
});


$app->post('/games/{gameId}/players/{playerId}/actions/feed', function (Request $request, Response $response, array $args) use ($pdo) {
    $gameId = (int)$args['gameId'];
    $playerId = (int)$args['playerId'];
    $data = json_decode($request->getBody()->getContents(), true);

    $requiredFood = isset($data['required_food']) ? (int)$data['required_food'] : 2;
    $allowSacrifice = isset($data['allow_sacrifice']) ? (bool)$data['allow_sacrifice'] : false;

    $validResources = VALID_RESOURCES;
    $animalFoodValue = ['cow' => 3, 'boar' => 2, 'sheep' => 1];

    $games = loadGames($pdo);

    foreach ($games as &$game) {
        if ($game['id'] === $gameId) {
            // ✅ Verifică jucătorul
            if (!in_array($playerId, [$game['player1_id'], $game['player2_id']])) {
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Jucătorul nu face parte din joc']));
            }

            // ✅ Inițializează resursele jucătorului dacă lipsesc
            if (!isset($game['player_resources'][$playerId])) {
                $game['player_resources'][$playerId] = array_fill_keys($validResources, 0);
            }

            $resources = &$game['player_resources'][$playerId];
            $foodAvailable = $resources['food'] ?? 0;
            $initialFood = $foodAvailable;
            $sacrificed = [];
            $message = '';

            // ✅ Consumă food și sacrifică animale dacă trebuie
            if ($foodAvailable >= $requiredFood) {
                $resources['food'] -= $requiredFood;
                $message = "Jucătorul a fost hrănit cu food.";
            } else {
                $remainingNeed = $requiredFood - $foodAvailable;
                $resources['food'] = 0;
                $foodFromAnimals = 0;

                if ($allowSacrifice) {
                    foreach ($animalFoodValue as $animal => $value) {
                        while ($resources[$animal] > 0 && $foodFromAnimals < $remainingNeed) {
                            $resources[$animal]--;
                            $foodFromAnimals += $value;
                            $sacrificed[$animal] = ($sacrificed[$animal] ?? 0) + 1;
                        }
                    }
                }

                $totalFood = $foodAvailable + $foodFromAnimals;

                if ($totalFood >= $requiredFood) {
                    $excess = $totalFood - $requiredFood;
                    $resources['food'] += $excess; // bonus dacă s-a depășit
                    $message = $allowSacrifice
                        ? "Jucătorul a fost hrănit cu food și animale."
                        : "Jucătorul a fost hrănit doar cu food.";
                } else {
                    $missing = $requiredFood - $totalFood;
                    // ✅ Folosește coloanele SQL
                    if ($playerId == $game['player1_id']) {
                        $game['penalty_player1'] = ($game['penalty_player1'] ?? 0) + $missing;
                    } else {
                        $game['penalty_player2'] = ($game['penalty_player2'] ?? 0) + $missing;
                    }
                    $message = "Resurse insuficiente! Penalizare: $missing.";
                }
            }

            // ✅ UPDATE penalizare direct în DB
            $stmt = $pdo->prepare("
                UPDATE games 
                SET penalty_player1 = :pen1, penalty_player2 = :pen2 
                WHERE id = :id
            ");
            $stmt->execute([
                ':pen1' => $game['penalty_player1'] ?? 0,
                ':pen2' => $game['penalty_player2'] ?? 0,
                ':id' => $gameId
            ]);

            // ✅ Loghează acțiunea
            $stmt = $pdo->prepare("
                INSERT INTO actions (game_id, player_id, type, data)
                VALUES (:game_id, :player_id, :type, :data)
            ");
            $stmt->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
                ':type' => 'feed',
                ':data' => json_encode([
                    'required_food' => $requiredFood,
                    'initial_food' => $initialFood,
                    'allow_sacrifice' => $allowSacrifice,
                    'sacrificed_animals' => $sacrificed,
                    'penalty' => ($playerId == $game['player1_id']) 
                        ? ($game['penalty_player1'] ?? 0) 
                        : ($game['penalty_player2'] ?? 0)
                ])
            ]);

            // ✅ Salvează JSON local dacă vrei să păstrezi fișierul
            file_put_contents(DATA_PATH . '/games.json', json_encode($games, JSON_PRETTY_PRINT));

            return $response->withHeader('Content-Type', 'application/json')
                ->write(json_encode([
                    'message' => $message,
                    'food_initial' => $initialFood,
                    'food_needed' => $requiredFood,
                    'allow_sacrifice' => $allowSacrifice,
                    'resources' => $resources,
                    'sacrificed_animals' => $sacrificed,
                    'penalty' => ($playerId == $game['player1_id']) 
                        ? ($game['penalty_player1'] ?? 0) 
                        : ($game['penalty_player2'] ?? 0)
                ]));
        }
    }

    // Joc inexistent
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
        ->write(json_encode(['error' => 'Joc inexistent']));
});




$app->post('/games/{gameId}/players/{playerId}/actions/build-house', function (Request $request, Response $response, array $args) use ($pdo) {
    $gameId = (int)$args['gameId'];
    $playerId = (int)$args['playerId'];
    $data = json_decode($request->getBody()->getContents(), true);

    $houseType = $data['type'] ?? 'wood'; // default
    $validTypes = ['wood', 'clay', 'stone'];
    $costs = [
        'wood' => ['wood' => 5, 'reed' => 2],
        'clay' => ['clay' => 5, 'reed' => 2],
        'stone' => ['stone' => 5, 'reed' => 2],
    ];

    if (!in_array($houseType, $validTypes)) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Tip de casă invalid']));
    }

    $games = loadGames($pdo);
    $validResources = VALID_RESOURCES;

    foreach ($games as &$game) {
        if ($game['id'] === $gameId) {
            if (!in_array($playerId, [$game['player1_id'], $game['player2_id']])) {
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Jucătorul nu face parte din joc']));
            }

            if ($game['current_turn'] !== $playerId) {
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Nu este rândul acestui jucător']));
            }

            // Verifică dacă spațiul build-house e ocupat
            if (isset($game['occupied_spaces']['build-house']) && $game['occupied_spaces']['build-house'] !== null) {
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Spațiul pentru construit case este deja ocupat']));
            }

            // Marchează spațiul ca ocupat
            $game['occupied_spaces']['build-house'] = $playerId;

            // Inițializează resurse dacă lipsesc
            if (!isset($game['player_resources'][$playerId])) {
                $game['player_resources'][$playerId] = array_fill_keys($validResources, 0);
            }

            $playerRes = &$game['player_resources'][$playerId];
            $cost = $costs[$houseType];

            // Verifică resurse suficiente
            foreach ($cost as $res => $amount) {
                if (($playerRes[$res] ?? 0) < $amount) {
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                        ->write(json_encode(['error' => "Resurse insuficiente pentru $res"]));
                }
            }

            // Scade resursele
            foreach ($cost as $res => $amount) {
                $playerRes[$res] -= $amount;
            }

            // Inițializează structurile și fermierii dacă lipsesc
            if (!isset($game['player_structures'][$playerId])) {
                $game['player_structures'][$playerId] = ['houses' => []];
            }
            if (!isset($game['player_farmers'][$playerId])) {
                $game['player_farmers'][$playerId] = 1;
            }

            // Adaugă casa
            $game['player_structures'][$playerId]['houses'][] = [
                'type' => $houseType,
                'round_built' => $game['round']
            ];

            // Log in DB: actions
            $stmt = $pdo->prepare("
                INSERT INTO actions (game_id, player_id, type, data)
                VALUES (:game_id, :player_id, :type, :data)
            ");
            $stmt->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
                ':type' => 'build-house',
                ':data' => json_encode([
                    'type' => $houseType,
                    'cost' => $cost,
                    'round' => $game['round']
                ])
            ]);

            // Schimbă turul
            switchTurn($pdo,$game);

            // Salvează
            file_put_contents(DATA_PATH . '/games.json', json_encode($games, JSON_PRETTY_PRINT));

            return $response->withHeader('Content-Type', 'application/json')
                ->write(json_encode([
                    'message' => "Casă construită din $houseType",
                    'remaining_resources' => $playerRes,
                    'houses_count' => count($game['player_structures'][$playerId]['houses']),
                    'structures' => $game['player_structures'][$playerId]
                ]));
        }
    }

    return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
        ->write(json_encode(['error' => 'Joc inexistent']));
});



$app->post('/games/{gameId}/players/{playerId}/actions/add-farmer', function (Request $request, Response $response, array $args) use ($pdo) {
    $gameId = (int)$args['gameId'];
    $playerId = (int)$args['playerId'];

    $games = loadGames($pdo);

    foreach ($games as &$game) {
        if ($game['id'] === $gameId) {

            // Verifică dacă jucătorul face parte din joc
            if (!in_array($playerId, [$game['player1_id'], $game['player2_id']])) {
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Jucătorul nu face parte din joc']));
            }

            // Verifică dacă e rândul lui
            if ($game['current_turn'] !== $playerId) {
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Nu este rândul acestui jucător']));
            }

            // Verifică dacă spațiul add-farmer e ocupat
            if (isset($game['occupied_spaces']['add-farmer']) && $game['occupied_spaces']['add-farmer'] !== null) {
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => 'Spațiul pentru adăugat fermier este deja ocupat.']));
            }

            // Marchează spațiul ca ocupat
            $game['occupied_spaces']['add-farmer'] = $playerId;

            // Inițializează structurile și fermierii dacă lipsesc
            if (!isset($game['player_structures'][$playerId])) {
                $game['player_structures'][$playerId] = ['houses' => []];
            }
            if (!isset($game['player_farmers'][$playerId])) {
                $game['player_farmers'][$playerId] = 1; // default
            }

            // Număr case
            $housesCount = count($game['player_structures'][$playerId]['houses']);
            if ($housesCount === 0) {
                $housesCount = 1; // fallback: minim 1 casă implicită
            }

            // Număr fermieri
            $currentFarmers = $game['player_farmers'][$playerId];

            if ($currentFarmers >= $housesCount) {
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(['error' => "Nu ai loc. Ai $housesCount case, deci maxim $housesCount fermieri."]));
            }

            // Crește numărul de fermieri
            $game['player_farmers'][$playerId] += 1;

            // Log in DB: actions
            $stmt = $pdo->prepare("
                INSERT INTO actions (game_id, player_id, type, data)
                VALUES (:game_id, :player_id, :type, :data)
            ");
            $stmt->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
                ':type' => 'add-farmer',
                ':data' => json_encode([
                    'new_total_farmers' => $game['player_farmers'][$playerId],
                    'houses' => $housesCount,
                    'round' => $game['round']
                ])
            ]);

            // Schimbă turul
            switchTurn($pdo,$game);

            // Salvează
            file_put_contents(DATA_PATH . '/games.json', json_encode($games, JSON_PRETTY_PRINT));

            return $response->withHeader('Content-Type', 'application/json')
                ->write(json_encode([
                    'message' => 'Fermier adăugat cu succes.',
                    'total_farmers' => $game['player_farmers'][$playerId],
                    'houses' => $housesCount
                ]));
        }
    }

    return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
        ->write(json_encode(['error' => 'Joc inexistent']));
});


$app->get('/actions', function (Request $request, Response $response) use ($pdo) {
    $stmt = $pdo->query("SELECT * FROM actions ORDER BY id ASC");
    $actions = $stmt->fetchAll();
    $response->getBody()->write(json_encode($actions));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/game/export/scores', function (Request $request, Response $response) use ($pdo) {
    // ✅ Ia direct scorurile din tabela games
    $stmt = $pdo->query("
        SELECT 
            id AS game_id,
            player1_id,
            player2_id,
            score_player1,
            score_player2
        FROM games
    ");
    $scores = $stmt->fetchAll();

    if (empty($scores)) {
        $response->getBody()->write(json_encode(['error' => 'Nu există scoruri de exportat.']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode($scores));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/game/export/history', function (Request $request, Response $response) use ($pdo) {
    // ✅ Ia direct doar câmpurile relevante din DB
    $stmt = $pdo->query("
        SELECT 
            id AS game_id,
            player1_id,
            player2_id,
            round AS rounds_played,
            active
        FROM games
    ");
    $history = $stmt->fetchAll();

    if (empty($history)) {
        $response->getBody()->write(json_encode(['error' => 'Nu există istoric de jocuri.']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode($history));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/stats/leaderboard', function (Request $request, Response $response) use ($pdo) {
    // Folosim SQL: COALESCE pentru a evita NULL
    $stmt = $pdo->query("
        SELECT player1_id AS player_id, SUM(score_player1) AS total_score
        FROM games
        WHERE player1_id IS NOT NULL
        GROUP BY player1_id

        UNION ALL

        SELECT player2_id AS player_id, SUM(score_player2) AS total_score
        FROM games
        WHERE player2_id IS NOT NULL
        GROUP BY player2_id
    ");

    $rows = $stmt->fetchAll();

    // Consolidăm scorurile pe player_id
    $leaderboard = [];
    foreach ($rows as $row) {
        $pid = (int)$row['player_id'];
        $leaderboard[$pid] = ($leaderboard[$pid] ?? 0) + (int)$row['total_score'];
    }

    // Transformăm în array indexat + sort descrescător
    $result = [];
    foreach ($leaderboard as $pid => $total_score) {
        $result[] = [
            'player_id' => $pid,
            'total_score' => $total_score
        ];
    }
    usort($result, fn($a, $b) => $b['total_score'] <=> $a['total_score']);

    if (empty($result)) {
        $response->getBody()->write(json_encode(['error' => 'Nu există scoruri pentru leaderboard.']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/stats/player/{playerId}', function (Request $request, Response $response, array $args) use ($pdo) {
    $playerId = (int)$args['playerId'];

    // Joacă jocuri ca player1
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS games_played, COALESCE(SUM(score_player1),0) AS total_score 
        FROM games WHERE player1_id = :player_id
    ");
    $stmt->execute([':player_id' => $playerId]);
    $p1 = $stmt->fetch();

    // Joacă jocuri ca player2
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS games_played, COALESCE(SUM(score_player2),0) AS total_score 
        FROM games WHERE player2_id = :player_id
    ");
    $stmt->execute([':player_id' => $playerId]);
    $p2 = $stmt->fetch();

    $games_played = $p1['games_played'] + $p2['games_played'];
    $total_score = $p1['total_score'] + $p2['total_score'];

    if ($games_played === 0) {
        $response->getBody()->write(json_encode(['error' => 'Jucător inexistent sau fără jocuri.']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // Pentru win/loss, calculăm din scoruri
    $stmt = $pdo->prepare("
        SELECT player1_id, player2_id, score_player1, score_player2
        FROM games
        WHERE (player1_id = :player_id OR player2_id = :player_id)
          AND score_player1 IS NOT NULL AND score_player2 IS NOT NULL
    ");
    $stmt->execute([':player_id' => $playerId]);
    $rows = $stmt->fetchAll();

    $wins = 0;
    $losses = 0;

    foreach ($rows as $row) {
        if ($row['score_player1'] > $row['score_player2']) {
            if ($row['player1_id'] == $playerId) $wins++;
            elseif ($row['player2_id'] == $playerId) $losses++;
        } elseif ($row['score_player2'] > $row['score_player1']) {
            if ($row['player2_id'] == $playerId) $wins++;
            elseif ($row['player1_id'] == $playerId) $losses++;
        }
    }

    $stats = [
        'player_id' => $playerId,
        'games_played' => $games_played,
        'total_score' => $total_score,
        'games_won' => $wins,
        'games_lost' => $losses
    ];

    $response->getBody()->write(json_encode($stats));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/state/{gameId}', function (Request $request, Response $response, array $args) use ($pdo) {
    $games = loadGames($pdo);
    $gameId = (int)$args['gameId'];

    foreach ($games as $game) {
        if ($game['id'] === $gameId) {
            $state = [
                'game_id' => $game['id'],
                'round' => $game['round'],
                'current_turn' => $game['current_turn'],
                'board_resources' => $game['board_resources'] ?? [
                    'wood' => 0,
                    'clay' => 0,
                    'food' => 0,
                    'reed' => 0,
                    'stone' => 0,
                    'sheep' => 0,
                    'boar' => 0,
                    'cow' => 0
                ],
                'occupied_spaces' => $game['occupied_spaces'] ?? [],
                'player_resources' => $game['player_resources'] ?? [],
                'player_farmers' => $game['player_farmers'] ?? [],
                'player_structures' => $game['player_structures'] ?? [],
                'penalty' => $game['penalty'] ?? [],
                'players' => $game['players'] ?? []
            ];

            $response->getBody()->write(json_encode($state));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    $response->getBody()->write(json_encode(['error' => 'Joc inexistent']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});


$app->post('/games/{gameId}/end', function (Request $request, Response $response, array $args) use ($pdo) {
    $gameId = (int)$args['gameId'];

    // Ia jocul direct din DB
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = :id");
    $stmt->execute([':id' => $gameId]);
    $game = $stmt->fetch();

    if (!$game) {
        $response->getBody()->write(json_encode(['error' => 'Joc inexistent']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // Verifică reguli:
    $shouldEnd = false;
    $reason = '';

    // 1️⃣ S-au jucat 14 runde?
    if (isset($game['round']) && $game['round'] >= 14) {
        $shouldEnd = true;
        $reason = 'Jocul s-a terminat: s-au jucat 14 runde.';
    }

    // 2️⃣ Inactivitate peste 3 minute
    $now = time();
    $lastMove = isset($game['last_move']) ? (int)$game['last_move'] : $now;
    if (!$shouldEnd && ($now - $lastMove) > (3 * 60)) {
        $shouldEnd = true;
        $reason = 'Jocul s-a terminat: jucător inactiv peste 3 minute.';
    }

    if ($shouldEnd) {
        // ✅ UPDATE în DB
        $stmt = $pdo->prepare("UPDATE games SET active = 0 WHERE id = :id");
        $stmt->execute([':id' => $gameId]);

        // ✅ (opțional) Actualizează JSON local dacă folosești și fișier
        $games = loadGames($pdo);
        file_put_contents(DATA_PATH . '/games.json', json_encode($games, JSON_PRETTY_PRINT));

        $response->getBody()->write(json_encode(['message' => $reason]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Altfel: jocul încă nu poate fi terminat
    $response->getBody()->write(json_encode(['message' => 'Jocul încă nu poate fi terminat.']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});




$app->run();


