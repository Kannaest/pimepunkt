<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$admin = ensure_config_admin();

$playName = 'Pimepunkt TEST - mängimiseks';
$resultsName = 'Pimepunkt TEST - tulemused';

$mapDir = __DIR__ . '/../storage/uploads/maps';
if (!is_dir($mapDir)) {
    mkdir($mapDir, 0775, true);
}
$mapFile = $mapDir . '/pimepunkt-test-map.svg';
$mapPath = '/uploads/maps/pimepunkt-test-map.svg';
file_put_contents($mapFile, test_map_svg());

$pdo->beginTransaction();
try {
    $delete = $pdo->prepare('DELETE FROM games WHERE name LIKE ?');
    $delete->execute(['Pimepunkt TEST - %']);

    $playGameId = create_game($pdo, $playName, 'running', $mapPath, (int)$admin['id']);
    create_checkpoint_with_question($pdo, $playGameId, '1', 'Testpunkt 1', 58.3859000, 24.4971000, 20000000, 'choice', 'Mis on selle mängu nimi?', ['Pimepunkt', 'Pimeala', 'Punktijaht'], 0, 1);
    create_checkpoint_with_question($pdo, $playGameId, '2', 'Testpunkt 2', 58.3868000, 24.5000000, 20000000, 'ok', 'Vajuta OK, kui näed seda küsimust.', [], 0, 3);
    create_checkpoint_with_question($pdo, $playGameId, '3', 'Testpunkt 3', 58.3893000, 24.5034000, 20000000, 'choice', 'Millise saare värvidest disain alguse sai?', ['Hiiumaa', 'Muhu', 'Vormsi'], 1, 6);

    $resultsGameId = create_game($pdo, $resultsName, 'results_public', $mapPath, (int)$admin['id']);
    $questions = [];
    $questions[] = create_checkpoint_with_question($pdo, $resultsGameId, '1', 'Tulemuste punkt 1', 58.3859000, 24.4971000, 75, 'choice', 'Testküsimus 1', ['Õige', 'Vale', 'Peaaegu'], 0);
    $questions[] = create_checkpoint_with_question($pdo, $resultsGameId, '2', 'Tulemuste punkt 2', 58.3868000, 24.5000000, 75, 'ok', 'Kohal käimise testpunkt', [], 0);
    $questions[] = create_checkpoint_with_question($pdo, $resultsGameId, '3', 'Tulemuste punkt 3', 58.3893000, 24.5034000, 75, 'choice', 'Testküsimus 3', ['Vale', 'Õige', 'Vale'], 1);

    $blueTeam = create_team($pdo, $resultsGameId, 'Sinine tiim', 'sinine-test@example.test');
    $pinkTeam = create_team($pdo, $resultsGameId, 'Roosa tiim', 'roosa-test@example.test');
    $yellowTeam = create_team($pdo, $resultsGameId, 'Kollane tiim', 'kollane-test@example.test');

    submit_answer($pdo, $blueTeam, $questions[0], true, 58.38591, 24.49711);
    submit_answer($pdo, $blueTeam, $questions[1], true, 58.38681, 24.50001);
    submit_answer($pdo, $blueTeam, $questions[2], true, 58.38931, 24.50341);

    submit_answer($pdo, $pinkTeam, $questions[0], true, 58.38585, 24.49702);
    submit_answer($pdo, $pinkTeam, $questions[1], true, 58.38674, 24.49995);
    submit_answer($pdo, $pinkTeam, $questions[2], false, 58.38922, 24.50329);

    submit_answer($pdo, $yellowTeam, $questions[0], false, 58.38570, 24.49690);
    submit_answer($pdo, $yellowTeam, $questions[1], true, 58.38668, 24.49980);

    add_locations($pdo, $blueTeam, [[58.3852, 24.4962], [58.3862, 24.4987], [58.3893, 24.5034]]);
    add_locations($pdo, $pinkTeam, [[58.3847, 24.4958], [58.3868, 24.5000], [58.3890, 24.5028]]);
    add_locations($pdo, $yellowTeam, [[58.3844, 24.4950], [58.3866, 24.4998], [58.3881, 24.5016]]);

    $pdo->commit();
    echo "created play_game_id={$playGameId}\n";
    echo "created results_game_id={$resultsGameId}\n";
    echo "play_url=" . config()['url'] . "/register?q=TEST\n";
    echo "results_url=" . config()['url'] . "/results/{$resultsGameId}\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

function create_game(PDO $pdo, string $name, string $status, string $mapPath, int $adminId): int
{
    $stmt = $pdo->prepare('
        INSERT INTO games (name, status, default_visit_points, default_wrong_penalty, map_path, started_at, finished_at, created_by_admin_id, public_results_enabled)
        VALUES (?, ?, 3, 2, ?, NOW(), IF(? = "results_public", NOW(), NULL), ?, 1)
    ');
    $stmt->execute([$name, $status, $mapPath, $status, $adminId]);
    $gameId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO game_admins (game_id, admin_id) VALUES (?, ?)')->execute([$gameId, $adminId]);
    return $gameId;
}

function create_checkpoint_with_question(PDO $pdo, int $gameId, string $number, string $title, float $lat, float $lng, int $radius, string $type, string $text, array $options, int $correctIndex, int $difficulty = 1): array
{
    $stmt = $pdo->prepare('INSERT INTO checkpoints (game_id, number, title, lat, lng, radius_m, difficulty) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$gameId, $number, $title, $lat, $lng, $radius, checkpoint_difficulty($difficulty)]);
    $checkpointId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO questions (checkpoint_id, type, text) VALUES (?, ?, ?)');
    $stmt->execute([$checkpointId, $type, $text]);
    $questionId = (int)$pdo->lastInsertId();

    $optionIds = [];
    foreach ($options as $index => $label) {
        $stmt = $pdo->prepare('INSERT INTO answer_options (question_id, label, is_correct) VALUES (?, ?, ?)');
        $stmt->execute([$questionId, $label, $index === $correctIndex ? 1 : 0]);
        $optionIds[$index] = (int)$pdo->lastInsertId();
    }

    return [
        'checkpoint_id' => $checkpointId,
        'question_id' => $questionId,
        'type' => $type,
        'correct_option_id' => $optionIds[$correctIndex] ?? null,
        'wrong_option_id' => $optionIds[0] ?? null,
        'lat' => $lat,
        'lng' => $lng,
    ];
}

function create_team(PDO $pdo, int $gameId, string $name, string $email): int
{
    $stmt = $pdo->prepare('INSERT INTO teams (game_id, name, email, status, email_verified_at) VALUES (?, ?, ?, "approved", NOW())');
    $stmt->execute([$gameId, $name, $email]);
    return (int)$pdo->lastInsertId();
}

function submit_answer(PDO $pdo, int $teamId, array $question, bool $correct, float $lat, float $lng): void
{
    $optionId = null;
    $okAnswer = 0;
    if ($question['type'] === 'ok') {
        $okAnswer = 1;
        $correct = true;
    } else {
        $optionId = $correct ? $question['correct_option_id'] : $question['wrong_option_id'];
    }
    $distance = distance_m($lat, $lng, (float)$question['lat'], (float)$question['lng']);
    $stmt = $pdo->prepare('
        INSERT INTO submissions (team_id, checkpoint_id, question_id, answer_option_id, ok_answer, lat, lng, accuracy_m, distance_m, is_correct)
        VALUES (?, ?, ?, ?, ?, ?, ?, 8, ?, ?)
    ');
    $stmt->execute([$teamId, $question['checkpoint_id'], $question['question_id'], $optionId, $okAnswer, $lat, $lng, $distance, $correct ? 1 : 0]);
}

function add_locations(PDO $pdo, int $teamId, array $points): void
{
    foreach ($points as $index => $point) {
        $stmt = $pdo->prepare('INSERT INTO location_logs (team_id, lat, lng, accuracy_m, created_at) VALUES (?, ?, ?, 10, DATE_SUB(NOW(), INTERVAL ? MINUTE))');
        $stmt->execute([$teamId, $point[0], $point[1], count($points) - $index]);
    }
}

function test_map_svg(): string
{
    return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="1400" height="900" viewBox="0 0 1400 900">
  <rect width="1400" height="900" fill="#f8f5ef"/>
  <path d="M120 680 C300 500 460 620 610 450 S920 310 1250 180" fill="none" stroke="#9db7c7" stroke-width="34" stroke-linecap="round"/>
  <path d="M130 700 C330 540 470 650 640 480 S940 340 1260 210" fill="none" stroke="#ffffff" stroke-width="12" stroke-linecap="round" opacity=".9"/>
  <circle cx="270" cy="585" r="42" fill="#efa1bd"/>
  <circle cx="650" cy="455" r="42" fill="#8fb89a"/>
  <circle cx="1030" cy="285" r="42" fill="#f4b26a"/>
  <text x="270" y="598" text-anchor="middle" font-family="Arial, sans-serif" font-size="38" font-weight="700" fill="#252525">1</text>
  <text x="650" y="468" text-anchor="middle" font-family="Arial, sans-serif" font-size="38" font-weight="700" fill="#252525">2</text>
  <text x="1030" y="298" text-anchor="middle" font-family="Arial, sans-serif" font-size="38" font-weight="700" fill="#252525">3</text>
  <text x="70" y="110" font-family="Arial, sans-serif" font-size="58" font-weight="700" fill="#252525">Pimepunkt TEST</text>
  <text x="72" y="165" font-family="Arial, sans-serif" font-size="28" fill="#68645d">Lihtne testkaart zoomi ja küsimuste proovimiseks</text>
</svg>
SVG;
}
