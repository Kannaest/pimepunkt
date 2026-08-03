<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/game_services.php';
require __DIR__ . '/../src/nutilogi_sync.php';

function assert_smoke(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$pdo->beginTransaction();
try {
    [$x, $y] = lest97_xy(59.437, 24.7536);
    assert_smoke(abs($x - 542763) < 5 && abs($y - 6589036) < 5, 'L-EST97 projection failed.');
    assert_smoke(parse_maxspeed('50') === 50 && parse_maxspeed('30 mph') === 48 && parse_maxspeed('signals') === null, 'maxspeed parsing failed.');
    assert_smoke(format_elapsed_seconds(3661) === '01:01:01', 'Elapsed time formatting failed.');
    assert_smoke(team_game_status_label(['status' => 'pending']) === 'Ootab korraldaja kinnitust', 'Pending game status label failed.');
    assert_smoke(team_game_status_label(['status' => 'approved', 'game_status' => 'running', 'duration_minutes' => 360, 'play_started_at' => null]) === 'Valmis alustamiseks', 'Ready game status label failed.');
    $centerLat = 58.5;
    $landscapeMap = generated_map_spec([
        'min_lat' => $centerLat,
        'max_lat' => $centerLat + 25000 / 111320,
        'min_lng' => 24.0,
        'max_lng' => 24.0 + 35000 / (111320 * cos(deg2rad($centerLat))),
    ]);
    $portraitMap = generated_map_spec([
        'min_lat' => $centerLat,
        'max_lat' => $centerLat + 35000 / 111320,
        'min_lng' => 24.0,
        'max_lng' => 24.0 + 25000 / (111320 * cos(deg2rad($centerLat))),
    ]);
    assert_smoke($landscapeMap['orientation'] === 'landscape' && $landscapeMap['width'] > 5600 && $landscapeMap['height'] > 4000, 'Landscape map sizing failed.');
    assert_smoke($portraitMap['orientation'] === 'portrait' && $portraitMap['height'] > 5600 && $portraitMap['width'] > 4000, 'Portrait map sizing failed.');
    $nutilogiEvent = nutilogi_normalize_event(
        ['id' => 'NE-test', 'name' => 'NE test', 'starttime' => 1000, 'endtime' => 2000],
        [
            'kp' => ['a' => ['nr' => '1', 'ra' => 6]],
            'kpdata' => ['a' => ['loc' => ['lat' => 58.5, 'lng' => 24.5], 'desc' => '<b>Küsimus</b>', 'responses' => ['x' => 'Vale', 'y' => 'Õige']]],
            'kpanswer' => ['a' => 'y'],
        ]
    );
    assert_smoke(count($nutilogiEvent['points']) === 1, 'Nutilogi point parsing failed.');
    assert_smoke($nutilogiEvent['points'][0]['difficulty'] === 6 && $nutilogiEvent['points'][0]['options'][1]['correct'] === true, 'Nutilogi difficulty or answer parsing failed.');
    assert_smoke(strlen($nutilogiEvent['source_hash']) === 64, 'Nutilogi source hash failed.');

    $pdo->exec('INSERT INTO games (name,status,default_visit_points,default_wrong_penalty,duration_minutes,speeding_penalty) VALUES ("Smoke game","running",3,2,360,7)');
    $gameId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO teams (game_id,name,email,status,play_started_at) VALUES (?,"Smoke team","smoke@example.test","approved",NOW())')->execute([$gameId]);
    $teamId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO checkpoints (game_id,number,title,lat,lng,radius_m,difficulty) VALUES (?,"1","Smoke point",58.385,24.5,50,1)')->execute([$gameId]);
    $checkpointId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO questions (checkpoint_id,type,text) VALUES (?,"ok","Smoke?")')->execute([$checkpointId]);
    $pdo->prepare('INSERT INTO speed_zones (game_id,source,source_id,name,speed_limit_kmh,geometry_type,center_lat,center_lng,radius_m) VALUES (?,"admin","smoke","Smoke zone",20,"circle",58.385,24.5,5000)')->execute([$gameId]);

    $team = $pdo->query('SELECT t.*,g.status game_status FROM teams t JOIN games g ON g.id=t.game_id WHERE t.id=' . $teamId)->fetch();
    $game = $pdo->query('SELECT * FROM games WHERE id=' . $gameId)->fetch();
    $deadline = team_deadline($team, $game);
    assert_smoke($deadline && abs($deadline->getTimestamp() - time() - 21600) < 10, 'Team deadline timezone calculation failed.');
    $pdo->prepare('INSERT INTO location_logs(team_id,lat,lng,accuracy_m,created_at) VALUES (?,58.385,24.500,5,DATE_SUB(NOW(),INTERVAL 12 SECOND))')->execute([$teamId]);
    $firstResult = record_location_and_speed($team, 58.385, 24.502, 5);
    $pdo->prepare('UPDATE speeding_events SET started_at=DATE_SUB(NOW(),INTERVAL 12 SECOND) WHERE team_id=?')->execute([$teamId]);
    $pdo->prepare('UPDATE location_logs SET created_at=DATE_SUB(NOW(),INTERVAL 5 SECOND) WHERE team_id=? ORDER BY id DESC LIMIT 1')->execute([$teamId]);
    $secondResult = record_location_and_speed($team, 58.385, 24.503, 5);
    $event = $pdo->query('SELECT status,penalty_points FROM speeding_events WHERE team_id=' . $teamId)->fetch();
    assert_smoke($event && $event['status'] === 'confirmed' && (int)$event['penalty_points'] === 7, 'Speeding duration or penalty failed: ' . json_encode([$firstResult, $secondResult, $event]));
    assert_smoke(str_contains(game_gpx($gameId), '<wpt lat="58.3850000" lon="24.5000000">'), 'GPX export failed.');

    $pdo->rollBack();
    echo "new feature smoke tests ok\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
