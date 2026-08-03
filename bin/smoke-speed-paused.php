<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
putenv('SPEED_TRACKING_ENABLED=false');
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/game_services.php';

function assert_speed_paused(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->exec('INSERT INTO games (name, status) VALUES ("Speed pause smoke", "running")');
    $gameId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO teams (game_id, name, email, status, play_started_at) VALUES (?, "Speed pause team", "speed-pause@example.invalid", "approved", NOW())')->execute([$gameId]);
    $teamId = (int)$pdo->lastInsertId();
    $team = ['id' => $teamId, 'game_id' => $gameId, 'paused_at' => null];

    $result = record_location_and_speed($team, 58.385, 24.5, 5);
    $location = $pdo->query('SELECT filtered_speed_kmh, speed_limit_kmh, ignored_reason FROM location_logs WHERE team_id=' . $teamId)->fetch();
    $eventCount = (int)$pdo->query('SELECT COUNT(*) FROM speeding_events WHERE team_id=' . $teamId)->fetchColumn();

    assert_speed_paused(config()['speed_tracking_enabled'] === false, 'Speed tracking must be disabled by default.');
    assert_speed_paused($result['speed_tracking'] === false && $result['speed'] === null && $result['limit'] === null, 'Disabled response contains speed data.');
    assert_speed_paused($location && $location['filtered_speed_kmh'] === null && $location['speed_limit_kmh'] === null, 'Location log contains calculated speed data.');
    assert_speed_paused($eventCount === 0, 'Disabled tracking created a speeding event.');

    echo "speed pause smoke tests ok\n";
} finally {
    $pdo->rollBack();
}
