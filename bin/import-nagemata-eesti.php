<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This importer can only be run from the command line.\n");
    exit(1);
}

$arguments = $argv;
array_shift($arguments);
$apply = in_array('--apply', $arguments, true);
$force = in_array('--force', $arguments, true);
$arguments = array_values(array_filter(
    $arguments,
    static fn(string $argument): bool => !str_starts_with($argument, '--')
));

if (count($arguments) !== 2 || !is_dir($arguments[0]) || !is_file($arguments[1])) {
    fwrite(STDERR, "Usage: php bin/import-nagemata-eesti.php <gpx-directory> <answers.md> [--apply] [--force]\n");
    exit(1);
}

$gpxDirectory = realpath($arguments[0]);
$answersPath = realpath($arguments[1]);
$root = getenv('PIMEPUNKT_ROOT') ?: realpath(__DIR__ . '/..');
if (!$root || !is_file($root . '/src/bootstrap.php')) {
    fwrite(STDERR, "Pimepunkt root was not found. Set PIMEPUNKT_ROOT.\n");
    exit(1);
}

/** @return array<string, array<string, string>> */
function parse_answers(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Could not read answers file.');
    }

    $answers = [];
    $eventId = null;
    foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
        if (preg_match('/^- \*\*eventId:\*\* `([^`]+)`$/u', trim($line), $match)) {
            $eventId = $match[1];
            continue;
        }
        if ($eventId === null || !str_starts_with(trim($line), '|') || str_contains($line, '|---')) {
            continue;
        }

        $cells = array_map(
            static fn(string $cell): string => trim(str_replace('\\|', '|', $cell)),
            preg_split('/(?<!\\\\)\|/u', trim($line, " \t|")) ?: []
        );
        if (count($cells) < 7 || $cells[0] === 'KP') {
            continue;
        }
        $sourcePointId = trim($cells[count($cells) - 2], " `\t");
        $correctAnswer = trim($cells[4]);
        if ($sourcePointId === '' || $correctAnswer === '' || str_contains($correctAnswer, 'lahendamata')) {
            continue;
        }
        $answers[$eventId][$sourcePointId] = $correctAnswer;
    }

    return $answers;
}

function xpath_text(SimpleXMLElement $xml, string $expression): string
{
    $nodes = $xml->xpath($expression);
    return $nodes && isset($nodes[0]) ? trim((string)$nodes[0]) : '';
}

function unicode_limit(string $value, int $length): string
{
    if (preg_match('/^.{0,' . $length . '}/us', $value, $match)) {
        return $match[0];
    }
    return $value;
}

/** @return array{options:list<array{label:string,correct:bool}>,added:bool} */
function waypoint_options(SimpleXMLElement $waypoint, ?string $correctAnswer): array
{
    $nodes = $waypoint->xpath('./*[local-name()="extensions"]/*[local-name()="option"]') ?: [];
    $options = [];
    $correctMatched = false;
    foreach ($nodes as $node) {
        $label = trim((string)$node);
        if ($label === '') {
            continue;
        }
        $isCorrect = !$correctMatched && $correctAnswer !== null && trim($label) === trim($correctAnswer);
        $correctMatched = $correctMatched || $isCorrect;
        $options[] = ['label' => $label, 'correct' => $isCorrect];
    }

    $added = $correctAnswer !== null && !$correctMatched && trim($correctAnswer) !== '';
    if ($added) {
        $options[] = ['label' => trim($correctAnswer), 'correct' => true];
    }
    return ['options' => $options, 'added' => $added];
}

/** @return array{event_id:string,event_name:string,points:list<array<string,mixed>>,solved:int,added:int,renumbered:int,used_source_ids:list<string>} */
function parse_gpx(string $path, array $answers): array
{
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$xml) {
        $message = $errors ? trim($errors[0]->message) : 'unknown XML error';
        throw new RuntimeException(basename($path) . ': ' . $message);
    }

    $eventId = xpath_text($xml, '/*[local-name()="gpx"]/*[local-name()="metadata"]/*[local-name()="extensions"]/*[local-name()="eventId"]');
    $eventName = xpath_text($xml, '/*[local-name()="gpx"]/*[local-name()="metadata"]/*[local-name()="extensions"]/*[local-name()="eventName"]');
    if ($eventId === '') {
        $eventId = pathinfo($path, PATHINFO_FILENAME);
    }
    if ($eventName === '') {
        $eventName = xpath_text($xml, '/*[local-name()="gpx"]/*[local-name()="metadata"]/*[local-name()="name"]') ?: $eventId;
    }

    $points = [];
    $solved = 0;
    $added = 0;
    $usedSourceIds = [];
    $numbers = [];
    $renumbered = 0;
    foreach ($xml->xpath('/*[local-name()="gpx"]/*[local-name()="wpt"]') ?: [] as $waypoint) {
        $attributes = $waypoint->attributes();
        $sourcePointId = xpath_text($waypoint, './*[local-name()="extensions"]/*[local-name()="sourcePointId"]');
        $number = xpath_text($waypoint, './*[local-name()="extensions"]/*[local-name()="number"]');
        $question = xpath_text($waypoint, './*[local-name()="extensions"]/*[local-name()="question"]');
        $description = xpath_text($waypoint, './*[local-name()="extensions"]/*[local-name()="longDescription"]');
        $difficulty = checkpoint_difficulty(xpath_text($waypoint, './*[local-name()="extensions"]/*[local-name()="difficulty"]'));
        $name = xpath_text($waypoint, './*[local-name()="name"]');
        if ($number === '' && preg_match('/^KP\s+([^:]+):/u', $name, $match)) {
            $number = trim($match[1]);
        }
        if ($number === '') {
            $number = (string)(count($points) + 1);
        }
        if ($question === '') {
            $question = xpath_text($waypoint, './*[local-name()="cmt"]') ?: $name;
        }
        $correctAnswer = $answers[$eventId][$sourcePointId] ?? null;
        $optionResult = waypoint_options($waypoint, $correctAnswer);
        $options = $optionResult['options'];
        if ($correctAnswer !== null) {
            $solved++;
            $added += $optionResult['added'] ? 1 : 0;
            $usedSourceIds[] = $sourcePointId;
        }
        $originalNumber = $number;
        $suffix = 2;
        while (isset($numbers[$number])) {
            $number = unicode_limit($originalNumber, 36) . '-' . $suffix;
            $suffix++;
        }
        if ($number !== $originalNumber) {
            $renumbered++;
        }
        $numbers[$number] = true;

        $points[] = [
            'number' => unicode_limit($number, 40),
            'title' => unicode_limit($question ?: ('Punkt ' . $number), 190),
            'question' => $description ?: $question ?: ('Punkt ' . $number),
            'lat' => (float)$attributes['lat'],
            'lng' => (float)$attributes['lon'],
            'difficulty' => $difficulty,
            'options' => $options,
        ];
    }

    return [
        'event_id' => $eventId,
        'event_name' => $eventName,
        'points' => $points,
        'solved' => $solved,
        'added' => $added,
        'renumbered' => $renumbered,
        'used_source_ids' => $usedSourceIds,
    ];
}

require $root . '/src/bootstrap.php';

$answers = parse_answers($answersPath);
$files = glob($gpxDirectory . DIRECTORY_SEPARATOR . '*.gpx') ?: [];
sort($files, SORT_NATURAL | SORT_FLAG_CASE);
if (!$files) {
    fwrite(STDERR, "No GPX files found.\n");
    exit(1);
}

$events = [];
$totals = ['points' => 0, 'solved' => 0, 'added' => 0, 'renumbered' => 0];
$usedAnswers = [];
foreach ($files as $file) {
    $event = parse_gpx($file, $answers);
    $events[] = $event;
    $totals['points'] += count($event['points']);
    $totals['solved'] += $event['solved'];
    $totals['added'] += $event['added'];
    $totals['renumbered'] += $event['renumbered'];
    foreach ($event['used_source_ids'] as $sourcePointId) {
        $usedAnswers[$event['event_id']][$sourcePointId] = true;
    }
}

$knownAnswers = array_sum(array_map('count', $answers));
$orphanAnswers = $knownAnswers - array_sum(array_map('count', $usedAnswers));

printf(
    "Parsed %d games and %d checkpoints. Attached %d of %d known correct answers; %d were added as missing options, %d have no exported GPX point, and %d duplicate checkpoint numbers were suffixed.\n",
    count($events),
    $totals['points'],
    $totals['solved'],
    $knownAnswers,
    $totals['added'],
    $orphanAnswers,
    $totals['renumbered']
);
if (!$apply) {
    fwrite(STDOUT, "Dry run complete. Add --apply to write to the database.\n");
    exit(0);
}

$pdo = db();
$admin = ensure_config_admin();
$created = 0;
$replaced = 0;
$skipped = 0;
$insertedPoints = 0;
$insertedCorrect = 0;

foreach ($events as $event) {
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT * FROM games WHERE name = ? ORDER BY id LIMIT 1 FOR UPDATE');
        $find->execute([$event['event_name']]);
        $game = $find->fetch();
        if ($game) {
            $submissionCount = $pdo->prepare('SELECT COUNT(*) FROM submissions s JOIN teams t ON t.id = s.team_id WHERE t.game_id = ?');
            $submissionCount->execute([(int)$game['id']]);
            if ((int)$submissionCount->fetchColumn() > 0 && !$force) {
                $pdo->rollBack();
                $skipped++;
                fwrite(STDOUT, "Skipped played game: {$event['event_name']}\n");
                continue;
            }
            $gameId = (int)$game['id'];
            $pdo->prepare("UPDATE games SET status = IF(status IN ('draft','registration_open','waiting_start'), 'running', status), auto_approve_teams = 1, allow_gpx_export = 1, duration_minutes = 360, speeding_penalty = 7, started_at = COALESCE(started_at, NOW()) WHERE id = ?")
                ->execute([$gameId]);
            $pdo->prepare('DELETE FROM checkpoints WHERE game_id = ?')->execute([$gameId]);
            $replaced++;
        } else {
            $insert = $pdo->prepare('INSERT INTO games (name, status, default_visit_points, default_wrong_penalty, auto_approve_teams, created_by_admin_id, public_results_enabled, allow_gpx_export, duration_minutes, speeding_penalty, started_at) VALUES (?, ?, 3, 2, 1, ?, 1, 1, 360, 7, NOW())');
            $insert->execute([$event['event_name'], 'running', (int)$admin['id']]);
            $gameId = (int)$pdo->lastInsertId();
            $created++;
        }
        $pdo->prepare('INSERT IGNORE INTO game_admins (game_id, admin_id) VALUES (?, ?)')->execute([$gameId, (int)$admin['id']]);

        $checkpointInsert = $pdo->prepare('INSERT INTO checkpoints (game_id, number, title, lat, lng, radius_m, difficulty) VALUES (?, ?, ?, ?, ?, 50, ?)');
        $questionInsert = $pdo->prepare('INSERT INTO questions (checkpoint_id, type, text) VALUES (?, ?, ?)');
        $optionInsert = $pdo->prepare('INSERT INTO answer_options (question_id, label, is_correct) VALUES (?, ?, ?)');
        foreach ($event['points'] as $point) {
            $checkpointInsert->execute([$gameId, $point['number'], $point['title'], $point['lat'], $point['lng'], $point['difficulty']]);
            $checkpointId = (int)$pdo->lastInsertId();
            $type = count($point['options']) >= 2 ? 'choice' : 'ok';
            $questionInsert->execute([$checkpointId, $type, $point['question']]);
            $questionId = (int)$pdo->lastInsertId();
            foreach ($point['options'] as $option) {
                $optionInsert->execute([$questionId, unicode_limit($option['label'], 255), $option['correct'] ? 1 : 0]);
                $insertedCorrect += $option['correct'] ? 1 : 0;
            }
            $insertedPoints++;
        }

        $audit = $pdo->prepare('INSERT INTO audit_log (game_id, actor, action, data_json) VALUES (?, ?, ?, ?)');
        $audit->execute([
            $gameId,
            'system',
            'nagemata_eesti_import',
            json_encode([
                'event_id' => $event['event_id'],
                'checkpoints' => count($event['points']),
                'correct_answers' => $event['solved'],
                'status' => 'running',
                'auto_approve_teams' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException($event['event_name'] . ': ' . $exception->getMessage(), 0, $exception);
    }
}

printf(
    "Import complete: %d created, %d replaced, %d skipped; %d checkpoints and %d correct options written.\n",
    $created,
    $replaced,
    $skipped,
    $insertedPoints,
    $insertedCorrect
);
