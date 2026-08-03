<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/game_services.php';

$admin = ensure_config_admin();
$pdo = db();

function total_text_limit(string $value, int $length): string
{
    return preg_match('/^.{0,' . $length . '}/us', $value, $match) ? $match[0] : $value;
}

foreach (['Kruus' => '%kruus%', 'Asfalt' => '%asfalt%'] as $kind => $pattern) {
    $sources = $pdo->prepare('SELECT DISTINCT g.id, g.name FROM games g JOIN audit_log a ON a.game_id = g.id AND a.action = "nagemata_eesti_import" WHERE LOWER(g.name) LIKE ? AND LOWER(g.name) NOT LIKE "%total%" ORDER BY g.id');
    $sources->execute([$pattern]);
    $sourceGames = $sources->fetchAll();
    if (!$sourceGames) {
        fwrite(STDERR, "No {$kind} source games found.\n");
        continue;
    }
    $name = 'Nägemata Eesti Total ' . $kind;
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT id FROM games WHERE name = ? FOR UPDATE');
        $find->execute([$name]);
        $gameId = (int)$find->fetchColumn();
        if ($gameId) {
            $played = $pdo->prepare('SELECT COUNT(*) FROM submissions s JOIN teams t ON t.id=s.team_id WHERE t.game_id=?');
            $played->execute([$gameId]);
            if ((int)$played->fetchColumn() > 0) throw new RuntimeException($name . ' already has submissions.');
            $pdo->prepare('DELETE FROM checkpoints WHERE game_id = ?')->execute([$gameId]);
            $pdo->prepare('UPDATE games SET status="running", auto_approve_teams=1, allow_gpx_export=1, duration_minutes=360, speeding_penalty=7, started_at=COALESCE(started_at,NOW()) WHERE id=?')->execute([$gameId]);
        } else {
            $insert = $pdo->prepare('INSERT INTO games (name,status,default_visit_points,default_wrong_penalty,auto_approve_teams,created_by_admin_id,public_results_enabled,allow_gpx_export,duration_minutes,speeding_penalty,started_at) VALUES (?,"running",3,2,1,?,1,1,360,7,NOW())');
            $insert->execute([$name, (int)$admin['id']]);
            $gameId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT IGNORE INTO game_admins (game_id,admin_id) VALUES (?,?)')->execute([$gameId, (int)$admin['id']]);

        $checkpointInsert = $pdo->prepare('INSERT INTO checkpoints (game_id,number,title,lat,lng,radius_m,visit_points,wrong_penalty,difficulty) VALUES (?,?,?,?,?,?,?,?,?)');
        $questionInsert = $pdo->prepare('INSERT INTO questions (checkpoint_id,type,text) VALUES (?,?,?)');
        $optionInsert = $pdo->prepare('INSERT INTO answer_options (question_id,label,is_correct) VALUES (?,?,?)');
        $optionSelect = $pdo->prepare('SELECT label,is_correct FROM answer_options WHERE question_id=? ORDER BY id');
        $number = 0;
        foreach ($sourceGames as $source) {
            $points = $pdo->prepare('SELECT c.*,q.type question_type,q.text question_text,q.id source_question_id FROM checkpoints c JOIN questions q ON q.checkpoint_id=c.id WHERE c.game_id=? ORDER BY c.id');
            $points->execute([(int)$source['id']]);
            foreach ($points->fetchAll() as $point) {
                $number++;
                $title = total_text_limit($source['name'] . ': ' . $point['title'], 190);
                $checkpointInsert->execute([$gameId, (string)$number, $title, $point['lat'], $point['lng'], $point['radius_m'], $point['visit_points'], $point['wrong_penalty'], $point['difficulty']]);
                $checkpointId = (int)$pdo->lastInsertId();
                $questionInsert->execute([$checkpointId, $point['question_type'], $point['question_text']]);
                $questionId = (int)$pdo->lastInsertId();
                $optionSelect->execute([(int)$point['source_question_id']]);
                foreach ($optionSelect->fetchAll() as $option) $optionInsert->execute([$questionId, $option['label'], $option['is_correct']]);
            }
        }
        $pdo->prepare('INSERT INTO audit_log (game_id,actor,action,data_json) VALUES (?,"system","nagemata_eesti_total_created",?)')
            ->execute([$gameId, json_encode(['kind' => $kind, 'source_games' => count($sourceGames), 'checkpoints' => $number], JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();
        printf("%s: game %d, %d sources, %d checkpoints\n", $name, $gameId, count($sourceGames), $number);
        try {
            generate_player_map($gameId);
        } catch (Throwable $mapError) {
            fwrite(STDERR, $name . ' map: ' . $mapError->getMessage() . "\n");
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, $e->getMessage() . "\n");
    }
}
