<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/game_services.php';

$ids = [];
foreach (array_slice($argv, 1) as $argument) {
    if (ctype_digit($argument)) $ids[] = (int)$argument;
}
if (!$ids) {
    $ids = array_map('intval', db()->query('SELECT DISTINCT g.id FROM games g JOIN audit_log a ON a.game_id = g.id WHERE a.action IN ("nagemata_eesti_import", "nagemata_eesti_total_created") ORDER BY g.id')->fetchAll(PDO::FETCH_COLUMN));
}
if (!$ids) {
    fwrite(STDERR, "No games found. Pass game IDs or import Nägemata Eesti first.\n");
    exit(1);
}

$settings = db()->prepare('UPDATE games SET allow_gpx_export = 1, duration_minutes = COALESCE(duration_minutes, 360), speeding_penalty = COALESCE(speeding_penalty, 7) WHERE id = ?');
foreach ($ids as $id) {
    try {
        $settings->execute([$id]);
        $path = generate_player_map($id);
        printf("%d\t%s\n", $id, $path);
        usleep(250000);
    } catch (Throwable $e) {
        fwrite(STDERR, $id . "\tERROR\t" . $e->getMessage() . "\n");
    }
}
