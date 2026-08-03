<?php

declare(strict_types=1);

const NUTILOGI_FIREBASE_DB = 'https://nutilogi.firebaseio.com';
const NUTILOGI_STORAGE_API = 'https://firebasestorage.googleapis.com/v0/b/nutilogi.appspot.com/o';
const NUTILOGI_ARCHIVE = 'https://archive.nutilogi.ee';

function nutilogi_json_request(string $url, string $method = 'GET', ?array $body = null, array $headers = []): array
{
    $headerLines = ['Accept: application/json', 'User-Agent: Pimepunkt/1.0 (https://kand.ee/pimepunkt)'];
    $content = '';
    if ($body !== null) {
        $content = json_encode($body, JSON_THROW_ON_ERROR);
        $headerLines[] = 'Content-Type: application/json';
    }
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headerLines) . "\r\n",
        'content' => $content,
        'timeout' => 45,
        'ignore_errors' => true,
    ]]);
    $json = @file_get_contents($url, false, $context);
    $status = $http_response_header[0] ?? '';
    if ($json === false || !preg_match('/\s2\d\d\s/', $status)) {
        throw new RuntimeException('Nutilogi andmepäring ebaõnnestus: ' . ($status ?: 'ühendus puudub'));
    }
    if (strlen($json) > 50 * 1024 * 1024) {
        throw new RuntimeException('Nutilogi vastus ületas 50 MB piirangu.');
    }
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

function nutilogi_auth_token(): string
{
    $key = (string)(config()['nutilogi']['firebase_api_key'] ?? '');
    if ($key === '') {
        throw new RuntimeException('NUTILOGI_FIREBASE_API_KEY puudub.');
    }
    $auth = nutilogi_json_request(
        'https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=' . rawurlencode($key),
        'POST',
        ['returnSecureToken' => true]
    );
    if (empty($auth['idToken']) || !is_string($auth['idToken'])) {
        throw new RuntimeException('Nutilogi anonüümne autentimine ebaõnnestus.');
    }
    return $auth['idToken'];
}

function nutilogi_latest_events(string $token, int $limit): array
{
    $index = nutilogi_json_request(NUTILOGI_FIREBASE_DB . '/events.json?auth=' . rawurlencode($token));
    $events = [];
    $nowMs = time() * 1000;
    foreach ($index as $eventId => $event) {
        if (!is_array($event)) {
            continue;
        }
        $name = trim((string)($event['name'] ?? ''));
        $endTime = (int)($event['endtime'] ?? 0);
        if (!preg_match('/^(20\d{2} )?NE( | -)/u', $name)
            || $endTime <= 0
            || $endTime >= $nowMs
            || ($event['hidden'] ?? false) === true) {
            continue;
        }
        $event['id'] = (string)$eventId;
        $events[] = $event;
    }
    usort($events, static fn(array $a, array $b): int => (int)($b['starttime'] ?? 0) <=> (int)($a['starttime'] ?? 0));
    return array_slice($events, 0, max(1, min(50, $limit)));
}

function nutilogi_event_data(array $event, string $token): array
{
    $eventId = rawurlencode((string)$event['id']);
    if (($event['arch'] ?? null) === 'a2') {
        $archive = nutilogi_json_request(NUTILOGI_ARCHIVE . '/' . $eventId . '/evdata.json');
        return is_array($archive['eventsdata'] ?? null) ? $archive['eventsdata'] : [];
    }
    if (($event['arch'] ?? null) === 'a1') {
        $object = rawurlencode('archive/' . (string)$event['id'] . '/evdata.json');
        $archive = nutilogi_json_request(
            NUTILOGI_STORAGE_API . '/' . $object . '?alt=media',
            'GET',
            null,
            ['Authorization' => 'Bearer ' . $token]
        );
        return is_array($archive['eventsdata'] ?? null) ? $archive['eventsdata'] : [];
    }
    $auth = '?auth=' . rawurlencode($token);
    return [
        'kp' => nutilogi_json_request(NUTILOGI_FIREBASE_DB . '/eventsdata/' . $eventId . '/kp.json' . $auth),
        'kpdata' => nutilogi_json_request(NUTILOGI_FIREBASE_DB . '/eventsdata/' . $eventId . '/kpdata.json' . $auth),
        'kpanswer' => nutilogi_json_request(NUTILOGI_FIREBASE_DB . '/eventsdata/' . $eventId . '/kpanswer.json' . $auth),
    ];
}

function nutilogi_clean_text(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = preg_replace('/<br\s*\/?>/iu', ' ', (string)$value) ?? '';
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function nutilogi_limit(string $value, int $length): string
{
    return preg_match('/^.{0,' . $length . '}/us', $value, $match) ? $match[0] : $value;
}

function nutilogi_normalize_event(array $event, array $data): array
{
    $kp = is_array($data['kp'] ?? null) ? $data['kp'] : [];
    $kpdata = is_array($data['kpdata'] ?? null) ? $data['kpdata'] : [];
    $kpanswer = is_array($data['kpanswer'] ?? null) ? $data['kpanswer'] : [];
    uasort($kp, static fn(mixed $a, mixed $b): int => strnatcasecmp(
        (string)(is_array($a) ? ($a['nr'] ?? '') : ''),
        (string)(is_array($b) ? ($b['nr'] ?? '') : '')
    ));

    $points = [];
    $numbers = [];
    foreach ($kp as $sourcePointId => $point) {
        if (!is_array($point) || !is_array($kpdata[$sourcePointId] ?? null)) {
            continue;
        }
        $details = $kpdata[$sourcePointId];
        $lat = filter_var($details['loc']['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($details['loc']['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false || $lat < 56 || $lat > 61 || $lng < 20 || $lng > 29) {
            continue;
        }
        $number = trim((string)($point['nr'] ?? '')) ?: (string)(count($points) + 1);
        $baseNumber = nutilogi_limit($number, 36);
        $suffix = 2;
        while (isset($numbers[$number])) {
            $number = $baseNumber . '-' . $suffix++;
        }
        $numbers[$number] = true;
        $question = nutilogi_clean_text($details['desc'] ?? '');
        $longDescription = nutilogi_clean_text($details['longdesc'] ?? '');
        $answerId = isset($kpanswer[$sourcePointId]) ? (string)$kpanswer[$sourcePointId] : null;
        $options = [];
        foreach (is_array($details['responses'] ?? null) ? $details['responses'] : [] as $optionId => $label) {
            $label = nutilogi_clean_text($label);
            if ($label !== '') {
                $options[] = ['label' => nutilogi_limit($label, 255), 'correct' => $answerId !== null && (string)$optionId === $answerId];
            }
        }
        $points[] = [
            'source_id' => (string)$sourcePointId,
            'number' => nutilogi_limit($number, 40),
            'title' => nutilogi_limit($question ?: ('Punkt ' . $number), 190),
            'question' => $longDescription ?: $question ?: ('Punkt ' . $number),
            'lat' => (float)$lat,
            'lng' => (float)$lng,
            'difficulty' => checkpoint_difficulty($point['ra'] ?? 1),
            'options' => $options,
        ];
    }
    $eventId = (string)$event['id'];
    $eventName = nutilogi_limit(trim((string)($event['name'] ?? $eventId)) ?: $eventId, 190);
    $normalized = [
        'event_id' => $eventId,
        'event_name' => $eventName,
        'start_ms' => (int)($event['starttime'] ?? 0),
        'end_ms' => (int)($event['endtime'] ?? 0),
        'source_url' => 'https://nutilogi.ee/' . rawurlencode($eventId) . '/result',
        'points' => $points,
    ];
    $normalized['source_hash'] = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return $normalized;
}

function nutilogi_sync_status(): array
{
    $row = db()->query('SELECT COUNT(*) linked_games, MAX(synced_at) last_synced_at FROM nutilogi_events')->fetch();
    return $row ?: ['linked_games' => 0, 'last_synced_at' => null];
}

function nutilogi_legacy_game(string $eventId): ?int
{
    $stmt = db()->prepare("SELECT a.game_id FROM audit_log a JOIN games g ON g.id=a.game_id WHERE a.action='nagemata_eesti_import' AND JSON_UNQUOTE(JSON_EXTRACT(a.data_json, '$.event_id')) = ? ORDER BY a.id DESC LIMIT 1");
    $stmt->execute([$eventId]);
    $gameId = $stmt->fetchColumn();
    return $gameId === false ? null : (int)$gameId;
}

function nutilogi_game_is_locked(int $gameId): bool
{
    $stmt = db()->prepare("SELECT g.status, (SELECT COUNT(*) FROM teams t WHERE t.game_id=g.id) teams FROM games g WHERE g.id=?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    return !$game || !in_array($game['status'], ['draft', 'registration_open', 'waiting_start'], true) || (int)$game['teams'] > 0;
}

function nutilogi_write_game(array $event, int $adminId, ?int $gameId): int
{
    $pdo = db();
    if ($gameId === null) {
        $stmt = $pdo->prepare('INSERT INTO games (name,status,default_visit_points,default_wrong_penalty,auto_approve_teams,created_by_admin_id,public_results_enabled,allow_gpx_export,duration_minutes,speeding_penalty) VALUES (?,"waiting_start",3,2,1,?,1,1,360,7)');
        $stmt->execute([$event['event_name'], $adminId]);
        $gameId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT IGNORE INTO game_admins (game_id,admin_id) VALUES (?,?)')->execute([$gameId, $adminId]);
    } else {
        $pdo->prepare('UPDATE games SET name=?,status=IF(status IN ("draft","registration_open","waiting_start"),"waiting_start",status),auto_approve_teams=1,allow_gpx_export=1,duration_minutes=360,speeding_penalty=7 WHERE id=?')->execute([$event['event_name'], $gameId]);
        $pdo->prepare('DELETE FROM checkpoints WHERE game_id=?')->execute([$gameId]);
    }

    $checkpoint = $pdo->prepare('INSERT INTO checkpoints (game_id,number,title,lat,lng,radius_m,difficulty) VALUES (?,?,?,?,?,50,?)');
    $question = $pdo->prepare('INSERT INTO questions (checkpoint_id,type,text) VALUES (?,?,?)');
    $option = $pdo->prepare('INSERT INTO answer_options (question_id,label,is_correct) VALUES (?,?,?)');
    foreach ($event['points'] as $point) {
        $checkpoint->execute([$gameId, $point['number'], $point['title'], $point['lat'], $point['lng'], $point['difficulty']]);
        $checkpointId = (int)$pdo->lastInsertId();
        $question->execute([$checkpointId, count($point['options']) >= 2 ? 'choice' : 'ok', $point['question']]);
        $questionId = (int)$pdo->lastInsertId();
        foreach ($point['options'] as $answer) {
            $option->execute([$questionId, $answer['label'], $answer['correct'] ? 1 : 0]);
        }
    }
    return $gameId;
}

function nutilogi_save_mapping(array $event, int $gameId): void
{
    $stmt = db()->prepare('INSERT INTO nutilogi_events (event_id,game_id,event_name,source_hash,event_start_ms,event_end_ms,source_url,synced_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),event_name=VALUES(event_name),source_hash=VALUES(source_hash),event_start_ms=VALUES(event_start_ms),event_end_ms=VALUES(event_end_ms),source_url=VALUES(source_url),synced_at=NOW()');
    $stmt->execute([$event['event_id'], $gameId, $event['event_name'], $event['source_hash'], $event['start_ms'] ?: null, $event['end_ms'] ?: null, $event['source_url']]);
}

function nutilogi_sync_latest(int $adminId, bool $apply, int $limit = 20): array
{
    set_time_limit(300);
    $summary = ['published' => 0, 'created' => 0, 'updated' => 0, 'adopted' => 0, 'unchanged' => 0, 'blocked' => 0, 'skipped' => 0, 'errors' => 0, 'map_errors' => 0];
    $locked = false;
    if ($apply) {
        $locked = (int)db()->query("SELECT GET_LOCK('pimepunkt_nutilogi_sync', 0)")->fetchColumn() === 1;
        if (!$locked) {
            throw new RuntimeException('Teine Nutilogi sünkroonimine juba töötab.');
        }
    }
    try {
        $token = nutilogi_auth_token();
        $events = nutilogi_latest_events($token, $limit);
        $summary['published'] = count($events);
        $mapping = db()->prepare('SELECT * FROM nutilogi_events WHERE event_id=?');
        foreach ($events as $sourceEvent) {
            try {
                $event = nutilogi_normalize_event($sourceEvent, nutilogi_event_data($sourceEvent, $token));
                if (!$event['points']) {
                    $summary['skipped']++;
                    continue;
                }
                $mapping->execute([$event['event_id']]);
                $linked = $mapping->fetch();
                if (!$linked) {
                    $legacyGameId = nutilogi_legacy_game($event['event_id']);
                    if ($legacyGameId !== null) {
                        $summary['adopted']++;
                        if ($apply) {
                            nutilogi_save_mapping($event, $legacyGameId);
                            audit('nutilogi_linked', $legacyGameId, null, ['event_id' => $event['event_id']]);
                        }
                        continue;
                    }
                    $summary['created']++;
                    if (!$apply) {
                        continue;
                    }
                    db()->beginTransaction();
                    try {
                        $gameId = nutilogi_write_game($event, $adminId, null);
                        nutilogi_save_mapping($event, $gameId);
                        audit('nutilogi_synced', $gameId, null, ['event_id' => $event['event_id'], 'operation' => 'created', 'checkpoints' => count($event['points'])]);
                        db()->commit();
                    } catch (Throwable $exception) {
                        db()->rollBack();
                        throw $exception;
                    }
                } elseif (hash_equals((string)$linked['source_hash'], $event['source_hash'])) {
                    $summary['unchanged']++;
                    continue;
                } else {
                    $gameId = (int)$linked['game_id'];
                    if (nutilogi_game_is_locked($gameId)) {
                        $summary['blocked']++;
                        continue;
                    }
                    $summary['updated']++;
                    if (!$apply) {
                        continue;
                    }
                    db()->beginTransaction();
                    try {
                        nutilogi_write_game($event, $adminId, $gameId);
                        nutilogi_save_mapping($event, $gameId);
                        audit('nutilogi_synced', $gameId, null, ['event_id' => $event['event_id'], 'operation' => 'updated', 'checkpoints' => count($event['points'])]);
                        db()->commit();
                    } catch (Throwable $exception) {
                        db()->rollBack();
                        throw $exception;
                    }
                }
                if ($apply && isset($gameId)) {
                    try {
                        generate_player_map($gameId);
                    } catch (Throwable $exception) {
                        $summary['map_errors']++;
                        error_log($exception);
                    }
                }
            } catch (Throwable $exception) {
                $summary['errors']++;
                error_log('Nutilogi event sync failed: ' . (string)($sourceEvent['id'] ?? '?') . ': ' . $exception->getMessage());
            }
        }
    } finally {
        if ($locked) {
            db()->query("SELECT RELEASE_LOCK('pimepunkt_nutilogi_sync')");
        }
    }
    return $summary;
}

function nutilogi_sync_message(array $result, bool $apply): string
{
    return ($apply ? 'Nutilogi sünkroon valmis: ' : 'Nutilogi kontroll valmis: ')
        . (int)$result['published'] . ' avaldatud, '
        . (int)$result['created'] . ' uut, '
        . (int)$result['updated'] . ' uuendust, '
        . (int)$result['adopted'] . ' seostamist, '
        . (int)$result['unchanged'] . ' muutuseta, '
        . (int)$result['blocked'] . ' aktiivset vahele jäetud, '
        . (int)$result['skipped'] . ' punktideta vahele jäetud, '
        . ((int)$result['errors'] + (int)$result['map_errors']) . ' viga.';
}
