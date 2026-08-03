<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$imported = (int)$pdo->query('SELECT COUNT(DISTINCT game_id) FROM audit_log WHERE action="nagemata_eesti_import"')->fetchColumn();
$configured = (int)$pdo->query('SELECT COUNT(DISTINCT g.id) FROM games g JOIN audit_log a ON a.game_id=g.id AND a.action="nagemata_eesti_import" WHERE g.duration_minutes=360 AND g.allow_gpx_export=1 AND g.map_path IS NOT NULL')->fetchColumn();
$totals = $pdo->query('SELECT g.id,g.name,g.status,g.duration_minutes,g.allow_gpx_export,g.map_path,COUNT(c.id) checkpoints FROM games g LEFT JOIN checkpoints c ON c.game_id=g.id WHERE g.name IN ("Nägemata Eesti Total Kruus","Nägemata Eesti Total Asfalt") GROUP BY g.id ORDER BY g.name')->fetchAll();
$migration = $pdo->query('SELECT COUNT(*) FROM migrations WHERE name="008_maps_timing_speed.sql"')->fetchColumn();

$result = ['migration_008' => (int)$migration === 1, 'imported_games' => $imported, 'configured_imported_games' => $configured, 'totals' => $totals];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
if ((int)$migration !== 1 || $imported !== $configured || count($totals) !== 2) exit(1);
