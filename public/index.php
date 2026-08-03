<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/game_services.php';
require __DIR__ . '/../src/nutilogi_sync.php';

start_session();
send_security_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') {
    $method = 'GET';
}
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = config()['base_path'];
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base)) ?: '/';
}
$route = '/' . trim($uri, '/');
if ($route !== '/') {
    $route = rtrim($route, '/');
}

try {
    dispatch($method, $route);
} catch (Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
    render('error', ['message' => 'Rakenduses tekkis viga. Proovi hetke pärast uuesti.']);
}

function dispatch(string $method, string $route): void
{
    if ($method === 'GET' && $route === '/') {
        home();
        return;
    }
    if ($method === 'GET' && $route === '/health') {
        health();
        return;
    }
    if ($method === 'GET' && $route === '/about') {
        render('about');
        return;
    }
    if ($route === '/admin/login') {
        if ($method === 'GET' && current_admin()) {
            redirect_to('/admin');
        }
        $method === 'POST' ? admin_login_post() : render('admin_login');
        return;
    }
    if ($route === '/admin/register') {
        if ($method === 'GET' && current_admin()) {
            redirect_to('/admin');
        }
        $method === 'POST' ? admin_register_post() : render('admin_register');
        return;
    }
    if ($method === 'GET' && $route === '/auth/magic') {
        magic_link_consume();
        return;
    }
    if ($route === '/admin/logout' && $method === 'POST') {
        require_csrf();
        start_session();
        $token = $_COOKIE['pimepunkt_admin'] ?? '';
        if (is_string($token) && $token !== '') {
            db()->prepare('DELETE FROM admin_sessions WHERE token IN (?, ?)')->execute([session_token_hash($token), $token]);
        }
        unset($_SESSION['admin'], $_SESSION['admin_id']);
        setcookie('pimepunkt_admin', '', ['expires' => time() - 3600, 'path' => (config()['base_path'] ?: '/') . '/']);
        redirect_to('/admin/login');
    }
    if (str_starts_with($route, '/admin')) {
        admin_dispatch($method, $route);
        return;
    }
    if ($route === '/register') {
        $method === 'POST' ? register_post() : register_form();
        return;
    }
    if ($route === '/verify') {
        flash('Kinnituskoodi asemel kasuta e-mailile saadetud turvalist linki.');
        redirect_to('/register');
        return;
    }
    if ($route === '/game') {
        game_view();
        return;
    }
    if ($route === '/game/start' && $method === 'POST') {
        game_start_post();
        return;
    }
    if ($route === '/game/pause' && $method === 'POST') {
        game_pause_post(false);
        return;
    }
    if ($route === '/game/resume' && $method === 'POST') {
        game_pause_post(true);
        return;
    }
    if ($route === '/answer' && $method === 'POST') {
        answer_post();
        return;
    }
    if ($route === '/location' && $method === 'POST') {
        location_post();
        return;
    }
    if (preg_match('#^/games/(\d+)/checkpoints\.gpx$#', $route, $m) && $method === 'GET') {
        game_gpx_download((int)$m[1]);
        return;
    }
    if (preg_match('#^/games/(\d+)/traffic$#', $route, $m) && $method === 'GET') {
        game_traffic_json((int)$m[1]);
        return;
    }
    if (preg_match('#^/results/(\d+)$#', $route, $m)) {
        public_results((int)$m[1]);
        return;
    }

    http_response_code(404);
    render('error', ['message' => 'Lehte ei leitud.']);
}

function home(): void
{
    $game = db()->query("SELECT * FROM games WHERE status IN ('registration_open','waiting_start','running','results_public') ORDER BY id DESC LIMIT 1")->fetch();
    $team = current_team();
    render('home', ['game' => $game, 'team' => $team]);
}

function health(): void
{
    $ok = false;
    try {
        db()->query('SELECT 1')->fetchColumn();
        $ok = true;
    } catch (Throwable) {
        $ok = false;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'app' => config()['name'],
        'version' => config()['version'],
        'database' => $ok ? 'ok' : 'failed',
    ]);
}

function admin_login_post(): void
{
    require_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    ensure_config_admin();
    $admin = find_admin_by_email($email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && $admin && can_send_auth_token('admin', $email)) {
        $token = create_auth_token('admin', $email);
        send_magic_link($email, config()['url'] . '/auth/magic?token=' . urlencode($token), 'admin');
    }
    flash('Kui e-mail sobib adminiga, saadeti sisselogimislink.');
    redirect_to('/admin/login');
}

function admin_register_post(): void
{
    require_csrf();
    ensure_config_admin();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Sisesta korrektne e-mail.');
        redirect_to('/admin/register');
    }
    if (!find_admin_by_email($email)) {
        db()->prepare('INSERT INTO admins (email, is_super) VALUES (?, 0)')->execute([$email]);
        audit('admin_self_registered', null, null, ['email' => $email]);
    }
    if (can_send_auth_token('admin', $email)) {
        $token = create_auth_token('admin', $email);
        send_magic_link($email, config()['url'] . '/auth/magic?token=' . urlencode($token), 'admin');
    }
    flash('Admini sisselogimislink saadeti e-mailile. Pärast avamist saad luua oma mänge.');
    redirect_to('/admin/login');
}

function magic_link_consume(): void
{
    $token = (string)($_GET['token'] ?? '');
    $row = $token === '' ? null : find_auth_token($token);
    if (!$row) {
        invalid_magic_link_response($token);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $hash = hash('sha256', $token . config()['secret']);
        $stmt = $pdo->prepare('SELECT * FROM auth_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->rollBack();
            invalid_magic_link_response($token);
        }
        $stmt = $pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $stmt->execute([(int)$row['id']]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            invalid_magic_link_response($token);
        }
        if ($row['purpose'] === 'admin') {
            ensure_config_admin();
            $admin = find_admin_by_email($row['email']);
            if (!$admin) {
                throw new RuntimeException('Admini link ei sobi.');
            }
            create_admin_session((int)$admin['id']);
            $pdo->commit();
            redirect_to('/admin');
        }

        $gameStmt = $pdo->prepare('SELECT auto_approve_teams FROM games WHERE id = ?');
        $gameStmt->execute([(int)$row['game_id']]);
        $autoApproveTeams = (int)($gameStmt->fetchColumn() ?: 0) === 1;

        $stmt = $pdo->prepare('SELECT id, status FROM teams WHERE game_id = ? AND email = ?');
        $stmt->execute([(int)$row['game_id'], $row['email']]);
        $team = $stmt->fetch();
        $status = $autoApproveTeams ? 'approved' : 'pending';
        if ($team) {
            $teamId = (int)$team['id'];
            if ($autoApproveTeams && $team['status'] === 'pending') {
                $pdo->prepare('UPDATE teams SET name = ?, status = "approved", email_verified_at = NOW() WHERE id = ?')->execute([$row['team_name'], $teamId]);
            } else {
                $pdo->prepare('UPDATE teams SET name = ?, email_verified_at = NOW() WHERE id = ?')->execute([$row['team_name'], $teamId]);
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO teams (game_id, name, email, status, email_verified_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([(int)$row['game_id'], $row['team_name'], $row['email'], $status]);
            $teamId = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        create_team_session((int)$teamId);
        redirect_to('/game');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function invalid_magic_link_response(string $token): void
{
    $tokenRow = find_auth_token_history($token);
    $admin = current_admin();
    $team = current_team();

    if (($tokenRow['purpose'] ?? null) === 'admin' && $admin) {
        flash('Oled selles brauseris juba adminina sisse logitud. Vana linki ei kasutatud.');
        redirect_to('/admin');
    }
    if (($tokenRow['purpose'] ?? null) === 'team' && $team) {
        flash('Oled selles brauseris juba mänguga seotud. Vana linki ei kasutatud.');
        redirect_to('/game');
    }
    if ($admin) {
        flash('Oled selles brauseris juba adminina sisse logitud. Vana linki ei kasutatud.');
        redirect_to('/admin');
    }
    if ($team) {
        flash('Oled selles brauseris juba mänguga seotud. Vana linki ei kasutatud.');
        redirect_to('/game');
    }

    http_response_code(410);
    render('error', ['message' => 'See sisselogimislink ei kehti enam või on juba kasutatud. Küsi uus link ja ava uusim e-mail.']);
    exit;
}

function admin_dispatch(string $method, string $route): void
{
    require_admin();
    $admin = current_admin();

    if ($method === 'GET' && $route === '/admin') {
        $games = accessible_games($admin);
        $admins = (int)$admin['is_super'] === 1 ? db()->query('SELECT * FROM admins ORDER BY email')->fetchAll() : [];
        $nutilogi = (int)$admin['is_super'] === 1 ? nutilogi_sync_status() : null;
        render('admin_games', ['games' => $games, 'admin' => $admin, 'admins' => $admins, 'nutilogi' => $nutilogi]);
        return;
    }
    if ($method === 'POST' && $route === '/admin/nutilogi-sync') {
        require_csrf();
        if ((int)$admin['is_super'] !== 1) {
            http_response_code(403);
            render('error', ['message' => 'Ainult peadmin saab Nutilogi mänge sünkroonida.']);
            return;
        }
        try {
            $apply = (string)($_POST['apply'] ?? '') === '1';
            $result = nutilogi_sync_latest((int)$admin['id'], $apply);
            flash(nutilogi_sync_message($result, $apply));
        } catch (Throwable $exception) {
            error_log($exception);
            flash('Nutilogi sünkroonimine ebaõnnestus: ' . $exception->getMessage());
        }
        redirect_to('/admin');
    }
    if ($method === 'POST' && $route === '/admin/games') {
        require_csrf();
        $stmt = db()->prepare('INSERT INTO games (name, status, default_visit_points, default_wrong_penalty, created_by_admin_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            trim((string)$_POST['name']),
            'draft',
            (int)($_POST['default_visit_points'] ?? 3),
            (int)($_POST['default_wrong_penalty'] ?? 2),
            (int)$admin['id'],
        ]);
        $gameId = (int)db()->lastInsertId();
        db()->prepare('INSERT IGNORE INTO game_admins (game_id, admin_id) VALUES (?, ?)')->execute([$gameId, (int)$admin['id']]);
        audit('game_created', $gameId);
        redirect_to('/admin');
    }
    if (preg_match('#^/admin/admins$#', $route) && $method === 'POST') {
        admin_create_admin();
        return;
    }
    if (preg_match('#^/admin/admins/(\d+)/delete$#', $route, $m) && $method === 'POST') {
        admin_delete_admin((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/delete$#', $route, $m) && $method === 'POST') {
        admin_game_delete((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/play$#', $route, $m) && $method === 'POST') {
        admin_game_play((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)$#', $route, $m)) {
        $method === 'POST' ? admin_game_update((int)$m[1]) : admin_game((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/map$#', $route, $m) && $method === 'POST') {
        admin_map_upload((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/map-generate$#', $route, $m) && $method === 'POST') {
        admin_map_generate((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/speed-sync$#', $route, $m) && $method === 'POST') {
        admin_speed_sync((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/speed-zones$#', $route, $m) && $method === 'POST') {
        admin_speed_zone_create((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/speed-zones/(\d+)/delete$#', $route, $m) && $method === 'POST') {
        admin_speed_zone_delete((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/gpx$#', $route, $m) && $method === 'POST') {
        admin_gpx_import((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/gpx-sample$#', $route, $m) && $method === 'GET') {
        admin_gpx_sample((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/checkpoints$#', $route, $m) && $method === 'POST') {
        admin_checkpoint_create((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/checkpoints/(\d+)/delete$#', $route, $m) && $method === 'POST') {
        admin_checkpoint_delete((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/checkpoints/(\d+)$#', $route, $m) && $method === 'POST') {
        admin_checkpoint_update((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/admins$#', $route, $m) && $method === 'POST') {
        admin_game_admin_add((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/games/(\d+)/admins/(\d+)/delete$#', $route, $m) && $method === 'POST') {
        admin_game_admin_delete((int)$m[1], (int)$m[2]);
        return;
    }
    if (preg_match('#^/admin/teams/(\d+)/(approve|reject|note)$#', $route, $m) && $method === 'POST') {
        admin_team_action((int)$m[1], $m[2]);
        return;
    }
    if (preg_match('#^/admin/teams/(\d+)/results$#', $route, $m) && $method === 'POST') {
        admin_team_results_action((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/submissions/(\d+)/adjust$#', $route, $m) && $method === 'POST') {
        admin_submission_adjust((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/speeding/(\d+)/(confirm|dismiss)$#', $route, $m) && $method === 'POST') {
        admin_speeding_action((int)$m[1], $m[2]);
        return;
    }
    if (preg_match('#^/admin/live/(\d+)$#', $route, $m)) {
        admin_live((int)$m[1]);
        return;
    }
    if (preg_match('#^/admin/locations/(\d+)$#', $route, $m)) {
        admin_locations_json((int)$m[1]);
        return;
    }

    http_response_code(404);
    render('error', ['message' => 'Admini lehte ei leitud.']);
}

function admin_game(int $id): void
{
    require_game_access($id);
    $game = find_game($id);
    $checkpointPageSize = 50;
    $checkpointPage = max(1, (int)($_GET['checkpoint_page'] ?? 1));
    $checkpointSearch = trim((string)($_GET['checkpoint_search'] ?? ''));
    $selectedCheckpointId = max(0, (int)($_GET['checkpoint_id'] ?? 0));
    $teams = db()->prepare('SELECT * FROM teams WHERE game_id = ? ORDER BY created_at DESC');
    $teams->execute([$id]);

    $where = 'c.game_id = ?';
    $params = [$id];
    if ($selectedCheckpointId > 0) {
        $where .= ' AND c.id = ?';
        $params[] = $selectedCheckpointId;
        $checkpointPage = 1;
    } elseif ($checkpointSearch !== '') {
        $where .= ' AND (c.number LIKE ? OR c.title LIKE ?)';
        $like = '%' . $checkpointSearch . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $countStmt = db()->prepare("SELECT COUNT(*) FROM checkpoints c WHERE {$where}");
    $countStmt->execute($params);
    $checkpointCount = (int)$countStmt->fetchColumn();
    $checkpointPages = max(1, (int)ceil($checkpointCount / $checkpointPageSize));
    $checkpointPage = min($checkpointPage, $checkpointPages);

    $checkpoints = db()->prepare("
        SELECT c.*, q.id AS question_id, q.type AS question_type, q.text AS question_text
        FROM checkpoints c
        LEFT JOIN questions q ON q.checkpoint_id = c.id
        WHERE {$where}
        ORDER BY CAST(c.number AS UNSIGNED), c.number
        LIMIT ? OFFSET ?
    ");
    foreach ($params as $index => $value) {
        $checkpoints->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $checkpoints->bindValue(count($params) + 1, $checkpointPageSize, PDO::PARAM_INT);
    $checkpoints->bindValue(count($params) + 2, ($checkpointPage - 1) * $checkpointPageSize, PDO::PARAM_INT);
    $checkpoints->execute();
    $checkpointRows = $checkpoints->fetchAll();
    $mapPointsStmt = db()->prepare('SELECT id, number, title, lat, lng, difficulty FROM checkpoints WHERE game_id = ? ORDER BY id');
    $mapPointsStmt->execute([$id]);
    $mapPoints = $mapPointsStmt->fetchAll();
    $speedZones = db()->prepare('SELECT * FROM speed_zones WHERE game_id = ? ORDER BY source, name');
    $speedZones->execute([$id]);
    $options = [];
    $questionIds = array_values(array_filter(array_map(fn($cp) => $cp['question_id'] ?? null, $checkpointRows)));
    if ($questionIds) {
        $in = implode(',', array_fill(0, count($questionIds), '?'));
        $optionStmt = db()->prepare("SELECT * FROM answer_options WHERE question_id IN ({$in}) ORDER BY id");
        $optionStmt->execute($questionIds);
        foreach ($optionStmt->fetchAll() as $option) {
            $options[(int)$option['question_id']][] = $option;
        }
    }
    render('admin_game', [
        'game' => $game,
        'teams' => $teams->fetchAll(),
        'checkpoints' => $checkpointRows,
        'checkpointOptions' => $options,
        'mapPoints' => $mapPoints,
        'checkpointCount' => $checkpointCount,
        'checkpointPage' => $checkpointPage,
        'checkpointPages' => $checkpointPages,
        'checkpointSearch' => $checkpointSearch,
        'selectedCheckpointId' => $selectedCheckpointId,
        'scoreboard' => scoreboard($id),
        'nextNumber' => next_checkpoint_number($id),
        'mapPrompt' => ai_map_prompt($game, $mapPoints),
        'gpxPrompt' => gpx_ai_prompt($game),
        'registerLink' => config()['url'] . '/register?game=' . $id,
        'admin' => current_admin(),
        'gameAdmins' => game_admins($id),
        'allAdmins' => db()->query('SELECT * FROM admins ORDER BY email')->fetchAll(),
        'speedZones' => $speedZones->fetchAll(),
    ]);
}

function admin_game_update(int $id): void
{
    require_game_access($id);
    require_csrf();
    $status = (string)($_POST['status'] ?? 'draft');
    $allowed = ['draft','registration_open','waiting_start','running','finished','results_review','results_public'];
    if (!in_array($status, $allowed, true)) {
        $status = 'draft';
    }
    $startFrom = datetime_input_value($_POST['start_window_from'] ?? null);
    $startTo = datetime_input_value($_POST['start_window_to'] ?? null);
    $duration = max(0, (int)($_POST['duration_minutes'] ?? 0)) ?: null;
    $stmt = db()->prepare('UPDATE games SET name = ?, status = ?, default_visit_points = ?, default_wrong_penalty = ?, auto_approve_teams = ?, public_results_enabled = ?, allow_gpx_export = ?, show_traffic_restrictions = ?, duration_minutes = ?, start_window_from = ?, start_window_to = ?, speeding_penalty = ?, started_at = IF(? = "running" AND started_at IS NULL, NOW(), started_at), finished_at = IF(? IN ("finished","results_review","results_public") AND finished_at IS NULL, NOW(), finished_at) WHERE id = ?');
    $stmt->execute([
        trim((string)$_POST['name']),
        $status,
        (int)$_POST['default_visit_points'],
        (int)$_POST['default_wrong_penalty'],
        isset($_POST['auto_approve_teams']) ? 1 : 0,
        isset($_POST['public_results_enabled']) ? 1 : 0,
        isset($_POST['allow_gpx_export']) ? 1 : 0,
        isset($_POST['show_traffic_restrictions']) ? 1 : 0,
        $duration,
        $startFrom,
        $startTo,
        max(0, (int)($_POST['speeding_penalty'] ?? 7)),
        $status,
        $status,
        $id,
    ]);
    audit('game_updated', $id, null, ['status' => $status]);
    redirect_to('/admin/games/' . $id);
}

function datetime_input_value(mixed $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    return $date ? $date->format('Y-m-d H:i:s') : null;
}

function admin_map_upload(int $id): void
{
    require_game_access($id);
    require_csrf();
    if (empty($_FILES['map']['tmp_name'])) {
        flash('Kaardifail puudub.');
        redirect_to('/admin/games/' . $id);
    }
    $tmp = $_FILES['map']['tmp_name'];
    if (!is_uploaded_file($tmp) || (int)($_FILES['map']['size'] ?? 0) > 8 * 1024 * 1024) {
        flash('Kaardifail peab olema kuni 8 MB JPG või PNG.');
        redirect_to('/admin/games/' . $id);
    }
    $type = mime_content_type($tmp);
    $imageInfo = getimagesize($tmp);
    if (!$imageInfo || !in_array($type, ['image/jpeg', 'image/png'], true) || !in_array($imageInfo['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
        flash('Kaart peab olema JPG või PNG.');
        redirect_to('/admin/games/' . $id);
    }
    $ext = $type === 'image/png' ? 'png' : 'jpg';
    $dir = __DIR__ . '/../storage/uploads/maps';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = 'game-' . $id . '-' . time() . '.' . $ext;
    move_uploaded_file($tmp, $dir . '/' . $name);
    $path = '/uploads/maps/' . $name;
    $stmt = db()->prepare('UPDATE games SET map_path = ? WHERE id = ?');
    $stmt->execute([$path, $id]);
    audit('map_uploaded', $id);
    redirect_to('/admin/games/' . $id);
}

function admin_map_generate(int $id): void
{
    require_game_access($id);
    require_csrf();
    try {
        generate_player_map($id);
        audit('map_generated', $id);
        flash('Halltoonides mängijakaart genereeriti punktide ulatuse järgi.');
    } catch (Throwable $e) {
        error_log($e);
        flash('Kaarti ei saanud genereerida: ' . $e->getMessage());
    }
    redirect_to('/admin/games/' . $id);
}

function admin_speed_sync(int $id): void
{
    require_game_access($id);
    require_csrf();
    try {
        $osmCount = sync_overpass_speed_zones($id);
        $tarkteeCount = sync_tarktee_speed_zones($id);
        audit('speed_zones_synced', $id, null, ['osm' => $osmCount, 'tarktee' => $tarkteeCount]);
        flash('Sünkrooniti ' . $osmCount . ' OSM-i teelõiku ja ' . $tarkteeCount . ' Tarktee numbrilist piirangut.');
    } catch (Throwable $e) {
        error_log($e);
        flash('Kiiruspiiranguid ei saanud sünkroonida: ' . $e->getMessage());
    }
    redirect_to('/admin/games/' . $id);
}

function admin_speed_zone_create(int $gameId): void
{
    require_game_access($gameId);
    require_csrf();
    $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $limit = (int)($_POST['speed_limit_kmh'] ?? 0);
    $radius = (int)($_POST['radius_m'] ?? 0);
    if ($lat === false || $lng === false || $limit < 5 || $limit > 200 || $radius < 10 || $radius > 10000) {
        flash('Kontrolli kiirusala koordinaate, piirangut ja raadiust.');
        redirect_to('/admin/games/' . $gameId);
    }
    $sourceId = 'manual:' . bin2hex(random_bytes(8));
    db()->prepare('INSERT INTO speed_zones (game_id, source, source_id, name, speed_limit_kmh, geometry_type, center_lat, center_lng, radius_m) VALUES (?, "admin", ?, ?, ?, "circle", ?, ?, ?)')
        ->execute([$gameId, $sourceId, trim((string)($_POST['name'] ?? 'Kiirusala')), $limit, $lat, $lng, $radius]);
    audit('speed_zone_created', $gameId, null, ['limit' => $limit]);
    redirect_to('/admin/games/' . $gameId);
}

function admin_speed_zone_delete(int $zoneId): void
{
    require_csrf();
    $stmt = db()->prepare('SELECT game_id FROM speed_zones WHERE id = ?');
    $stmt->execute([$zoneId]);
    $gameId = (int)$stmt->fetchColumn();
    require_game_access($gameId);
    db()->prepare('DELETE FROM speed_zones WHERE id = ?')->execute([$zoneId]);
    audit('speed_zone_deleted', $gameId, null, ['speed_zone_id' => $zoneId]);
    redirect_to('/admin/games/' . $gameId);
}

function admin_gpx_import(int $gameId): void
{
    require_game_access($gameId);
    require_csrf();
    if (empty($_FILES['gpx']['tmp_name']) || !is_uploaded_file($_FILES['gpx']['tmp_name'])) {
        flash('GPX fail puudub.');
        redirect_to('/admin/games/' . $gameId);
    }
    if ((int)($_FILES['gpx']['size'] ?? 0) > 1024 * 1024) {
        flash('GPX fail peab olema kuni 1 MB.');
        redirect_to('/admin/games/' . $gameId);
    }

    $previousXmlErrors = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $xml = simplexml_load_file($_FILES['gpx']['tmp_name'], 'SimpleXMLElement', LIBXML_NONET);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previousXmlErrors);
    if (!$xml) {
        $detail = $xmlErrors ? trim($xmlErrors[0]->message) : 'fail ei ole korrektne XML/GPX.';
        flash('GPX faili ei saanud lugeda: ' . $detail);
        redirect_to('/admin/games/' . $gameId);
    }

    $overwrite = isset($_POST['overwrite_gpx']);
    $imported = 0;
    $skipped = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($overwrite) {
            $pdo->prepare('DELETE FROM checkpoints WHERE game_id = ?')->execute([$gameId]);
        }
        $waypoints = $xml->xpath('//*[local-name()="wpt"]') ?: [];
        foreach ($waypoints as $index => $wpt) {
            $latRaw = (string)($wpt['lat'] ?? '');
            $lngRaw = (string)($wpt['lon'] ?? '');
            if (!is_numeric($latRaw) || !is_numeric($lngRaw)) {
                $skipped++;
                continue;
            }
            $lat = (float)$latRaw;
            $lng = (float)$lngRaw;
            $name = trim(gpx_child_text($wpt, 'name'));
            $number = trim(gpx_child_text($wpt, 'number'));
            if ($number === '') {
                $number = $name;
            }
            $title = trim(gpx_child_text($wpt, 'title'));
            if ($title === '') {
                $title = trim(gpx_child_text($wpt, 'type'));
            }
            if ($title === '' && preg_match('/^(\d+|[A-Za-z]\d*)\s*[-:.)]\s*(.+)$/u', $name, $match)) {
                $number = $number === $name ? $match[1] : $number;
                $title = trim($match[2]);
            }
            if ($number === '') {
                $number = (string)($index + 1);
            }
            $exists = $pdo->prepare('SELECT id FROM checkpoints WHERE game_id = ? AND number = ?');
            $exists->execute([$gameId, $number]);
            if ($exists->fetchColumn()) {
                $skipped++;
                continue;
            }

            if ($title === '') {
                $title = 'Punkt ' . $number;
            }
            $questionText = trim(gpx_child_text($wpt, 'desc'));
            if ($questionText === '') {
                $questionText = 'Kinnita kohalolek punktis ' . $number . '.';
            }
            $options = gpx_options(gpx_child_text($wpt, 'cmt'));
            $type = count($options) >= 2 ? 'choice' : 'ok';
            $difficulty = checkpoint_difficulty(gpx_child_text($wpt, 'difficulty'));

            $stmt = $pdo->prepare('INSERT INTO checkpoints (game_id, number, title, lat, lng, radius_m, difficulty) VALUES (?, ?, ?, ?, ?, 50, ?)');
            $stmt->execute([$gameId, $number, $title, $lat, $lng, $difficulty]);
            $checkpointId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO questions (checkpoint_id, type, text) VALUES (?, ?, ?)');
            $stmt->execute([$checkpointId, $type, $questionText]);
            $questionId = (int)$pdo->lastInsertId();

            if ($type === 'choice') {
                foreach (array_slice($options, 0, 5) as $option) {
                    $stmt = $pdo->prepare('INSERT INTO answer_options (question_id, label, is_correct) VALUES (?, ?, ?)');
                    $stmt->execute([$questionId, $option['label'], $option['correct'] ? 1 : 0]);
                }
            }
            $imported++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('gpx_imported', $gameId, null, ['imported' => $imported, 'skipped' => $skipped, 'overwrite' => $overwrite]);
    $mapMessage = '';
    if ($imported > 0) {
        try {
            generate_player_map($gameId);
            $mapMessage = ' Mängijakaart genereeriti uuesti.';
        } catch (Throwable $e) {
            error_log($e);
            $mapMessage = ' Punktid imporditi, kuid kaarti ei saanud automaatselt genereerida.';
        }
    }
    flash('GPX import valmis: lisatud ' . $imported . ', vahele jäetud ' . $skipped . '.' . $mapMessage);
    redirect_to('/admin/games/' . $gameId);
}

function gpx_options(string $comment): array
{
    $parts = array_values(array_filter(array_map('trim', explode('|', $comment)), fn($part) => $part !== ''));
    if (count($parts) < 2) {
        return [];
    }
    $options = [];
    $hasCorrect = false;
    foreach ($parts as $part) {
        $correct = str_starts_with($part, '*');
        $label = ltrim($part, '* ');
        if ($label === '') {
            continue;
        }
        $hasCorrect = $hasCorrect || $correct;
        $options[] = ['label' => $label, 'correct' => $correct];
    }
    if (!$hasCorrect && $options) {
        $options[0]['correct'] = true;
    }
    return $options;
}

function gpx_child_text(SimpleXMLElement $element, string $name): string
{
    $found = $element->xpath('.//*[local-name()="' . $name . '"]');
    if (!$found || !isset($found[0])) {
        return '';
    }
    return (string)$found[0];
}

function admin_gpx_sample(int $gameId): void
{
    require_game_access($gameId);
    $game = find_game($gameId);
    $name = preg_replace('/[^a-z0-9-]+/i', '-', strtolower((string)$game['name'])) ?: 'pimepunkt';
    header('Content-Type: application/gpx+xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '-naidis.gpx"');
    echo sample_gpx((string)$game['name']);
    exit;
}

function admin_checkpoint_create(int $gameId): void
{
    require_game_access($gameId);
    require_csrf();
    $number = trim((string)($_POST['number'] ?? ''));
    if ($number === '') {
        $number = next_checkpoint_number($gameId);
    }
    $duplicate = db()->prepare('SELECT id FROM checkpoints WHERE game_id = ? AND number = ?');
    $duplicate->execute([$gameId, $number]);
    if ($duplicate->fetchColumn()) {
        flash('Sellise numbriga punkt on selles mängus juba olemas.');
        redirect_to('/admin/games/' . $gameId);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO checkpoints (game_id, number, title, lat, lng, radius_m, difficulty, visit_points, wrong_penalty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $gameId,
            $number,
            trim((string)$_POST['title']),
            (float)$_POST['lat'],
            (float)$_POST['lng'],
            (int)$_POST['radius_m'],
            checkpoint_difficulty($_POST['difficulty'] ?? 1),
            $_POST['visit_points'] === '' ? null : (int)$_POST['visit_points'],
            $_POST['wrong_penalty'] === '' ? null : (int)$_POST['wrong_penalty'],
        ]);
        $checkpointId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO questions (checkpoint_id, type, text) VALUES (?, ?, ?)');
        $type = (string)$_POST['question_type'] === 'ok' ? 'ok' : 'choice';
        $stmt->execute([$checkpointId, $type, trim((string)$_POST['question_text'])]);
        $questionId = (int)$pdo->lastInsertId();
        if ($type === 'choice') {
            $labels = $_POST['option_label'] ?? [];
            $correct = (int)($_POST['correct_option'] ?? -1);
            foreach ($labels as $index => $label) {
                $label = trim((string)$label);
                if ($label === '') {
                    continue;
                }
                $stmt = $pdo->prepare('INSERT INTO answer_options (question_id, label, is_correct) VALUES (?, ?, ?)');
                $stmt->execute([$questionId, $label, $index === $correct ? 1 : 0]);
            }
        }
        $pdo->commit();
        audit('checkpoint_created', $gameId, null, ['checkpoint_id' => $checkpointId]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    redirect_to('/admin/games/' . $gameId . '?checkpoint_id=' . $checkpointId . '#checkpoint-' . $checkpointId);
}

function admin_checkpoint_update(int $checkpointId): void
{
    require_csrf();
    $stmt = db()->prepare('
        SELECT c.*, q.id AS question_id
        FROM checkpoints c
        LEFT JOIN questions q ON q.checkpoint_id = c.id
        WHERE c.id = ?
    ');
    $stmt->execute([$checkpointId]);
    $checkpoint = $stmt->fetch();
    if (!$checkpoint) {
        http_response_code(404);
        render('error', ['message' => 'Punkti ei leitud.']);
        return;
    }
    $gameId = (int)$checkpoint['game_id'];
    require_game_access($gameId);

    $number = trim((string)($_POST['number'] ?? ''));
    if ($number === '') {
        flash('Punkti number on kohustuslik.');
        redirect_to('/admin/games/' . $gameId . '?checkpoint_id=' . $checkpointId . '#checkpoint-' . $checkpointId);
    }
    $duplicate = db()->prepare('SELECT id FROM checkpoints WHERE game_id = ? AND number = ? AND id <> ?');
    $duplicate->execute([$gameId, $number, $checkpointId]);
    if ($duplicate->fetchColumn()) {
        flash('Sellise numbriga punkt on selles mängus juba olemas.');
        redirect_to('/admin/games/' . $gameId . '?checkpoint_id=' . $checkpointId . '#checkpoint-' . $checkpointId);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            UPDATE checkpoints
            SET number = ?, title = ?, lat = ?, lng = ?, radius_m = ?, difficulty = ?, visit_points = ?, wrong_penalty = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $number,
            trim((string)($_POST['title'] ?? '')),
            (float)($_POST['lat'] ?? 0),
            (float)($_POST['lng'] ?? 0),
            (int)($_POST['radius_m'] ?? 50),
            checkpoint_difficulty($_POST['difficulty'] ?? 1),
            ($_POST['visit_points'] ?? '') === '' ? null : (int)$_POST['visit_points'],
            ($_POST['wrong_penalty'] ?? '') === '' ? null : (int)$_POST['wrong_penalty'],
            $checkpointId,
        ]);

        $type = (string)($_POST['question_type'] ?? 'choice') === 'ok' ? 'ok' : 'choice';
        $questionText = trim((string)($_POST['question_text'] ?? ''));
        if ($checkpoint['question_id']) {
            $questionId = (int)$checkpoint['question_id'];
            $pdo->prepare('UPDATE questions SET type = ?, text = ? WHERE id = ?')->execute([$type, $questionText, $questionId]);
            $pdo->prepare('DELETE FROM answer_options WHERE question_id = ?')->execute([$questionId]);
        } else {
            $pdo->prepare('INSERT INTO questions (checkpoint_id, type, text) VALUES (?, ?, ?)')->execute([$checkpointId, $type, $questionText]);
            $questionId = (int)$pdo->lastInsertId();
        }

        if ($type === 'choice') {
            $labels = $_POST['option_label'] ?? [];
            $correct = (int)($_POST['correct_option'] ?? -1);
            foreach ($labels as $index => $label) {
                $label = trim((string)$label);
                if ($label === '') {
                    continue;
                }
                $stmt = $pdo->prepare('INSERT INTO answer_options (question_id, label, is_correct) VALUES (?, ?, ?)');
                $stmt->execute([$questionId, $label, $index === $correct ? 1 : 0]);
            }
        }

        $pdo->commit();
        audit('checkpoint_updated', $gameId, null, ['checkpoint_id' => $checkpointId]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    redirect_to('/admin/games/' . $gameId . '?checkpoint_id=' . $checkpointId . '#checkpoint-' . $checkpointId);
}

function admin_checkpoint_delete(int $checkpointId): void
{
    require_csrf();
    $stmt = db()->prepare('SELECT game_id FROM checkpoints WHERE id = ?');
    $stmt->execute([$checkpointId]);
    $gameId = (int)$stmt->fetchColumn();
    if (!$gameId) {
        http_response_code(404);
        render('error', ['message' => 'Punkti ei leitud.']);
        return;
    }
    require_game_access($gameId);
    db()->prepare('DELETE FROM checkpoints WHERE id = ?')->execute([$checkpointId]);
    audit('checkpoint_deleted', $gameId, null, ['checkpoint_id' => $checkpointId]);
    flash('Punkt kustutatud.');
    redirect_to('/admin/games/' . $gameId);
}

function admin_team_action(int $teamId, string $action): void
{
    require_csrf();
    $team = find_team($teamId);
    require_game_access((int)$team['game_id']);
    if ($action === 'approve') {
        db()->prepare("UPDATE teams SET status = 'approved' WHERE id = ?")->execute([$teamId]);
    } elseif ($action === 'reject') {
        db()->prepare("UPDATE teams SET status = 'rejected' WHERE id = ?")->execute([$teamId]);
    } else {
        db()->prepare('UPDATE teams SET admin_note = ? WHERE id = ?')->execute([trim((string)($_POST['admin_note'] ?? '')), $teamId]);
    }
    audit('team_' . $action, (int)$team['game_id'], $teamId);
    redirect_to('/admin/games/' . $team['game_id']);
}

function admin_team_results_action(int $teamId): void
{
    require_csrf();
    $team = find_team($teamId);
    require_game_access((int)$team['game_id']);
    $excluded = isset($_POST['excluded_from_results']) ? 1 : 0;
    db()->prepare('UPDATE teams SET excluded_from_results = ? WHERE id = ?')->execute([$excluded, $teamId]);
    audit('team_results_' . ($excluded ? 'excluded' : 'included'), (int)$team['game_id'], $teamId);
    redirect_to('/admin/games/' . $team['game_id']);
}

function admin_submission_adjust(int $submissionId): void
{
    require_csrf();
    $row = db()->prepare('SELECT t.game_id, s.team_id FROM submissions s JOIN teams t ON t.id = s.team_id WHERE s.id = ?');
    $row->execute([$submissionId]);
    $data = $row->fetch();
    if (!$data) {
        http_response_code(404);
        render('error', ['message' => 'Vastust ei leitud.']);
        return;
    }
    require_game_access((int)$data['game_id']);
    $stmt = db()->prepare('UPDATE submissions SET admin_correct_override = ?, admin_score_adjustment = ?, admin_note = ? WHERE id = ?');
    $override = ($_POST['admin_correct_override'] ?? '') === '' ? null : (int)$_POST['admin_correct_override'];
    $stmt->execute([$override, (int)($_POST['admin_score_adjustment'] ?? 0), trim((string)($_POST['admin_note'] ?? '')), $submissionId]);
    audit('submission_adjusted', (int)$data['game_id'], (int)$data['team_id'], ['submission_id' => $submissionId]);
    redirect_to('/admin/live/' . $data['game_id']);
}

function admin_speeding_action(int $eventId, string $action): void
{
    require_csrf();
    $stmt = db()->prepare('SELECT t.game_id FROM speeding_events se JOIN teams t ON t.id=se.team_id WHERE se.id=?');
    $stmt->execute([$eventId]);
    $gameId = (int)$stmt->fetchColumn();
    require_game_access($gameId);
    $status = $action === 'dismiss' ? 'dismissed' : 'confirmed';
    $penalty = $status === 'confirmed' ? (int)find_game($gameId)['speeding_penalty'] : 0;
    db()->prepare('UPDATE speeding_events SET status=?, penalty_points=? WHERE id=?')->execute([$status, $penalty, $eventId]);
    audit('speeding_' . $status, $gameId, null, ['event_id' => $eventId]);
    redirect_to('/admin/live/' . $gameId);
}

function admin_live(int $gameId): void
{
    require_game_access($gameId);
    $game = find_game($gameId);
    $submissions = db()->prepare('
        SELECT s.*, t.name AS team_name, c.number AS checkpoint_number, q.text AS question_text, ao.label AS answer_label
        FROM submissions s
        JOIN teams t ON t.id = s.team_id
        JOIN checkpoints c ON c.id = s.checkpoint_id
        JOIN questions q ON q.id = s.question_id
        LEFT JOIN answer_options ao ON ao.id = s.answer_option_id
        WHERE t.game_id = ?
        ORDER BY s.created_at DESC
        LIMIT 100
    ');
    $submissions->execute([$gameId]);
    $teams = db()->prepare('SELECT id, name, excluded_from_results FROM teams WHERE game_id = ? ORDER BY name');
    $teams->execute([$gameId]);
    $speeding = db()->prepare('SELECT se.*, t.name team_name, sz.name zone_name FROM speeding_events se JOIN teams t ON t.id=se.team_id LEFT JOIN speed_zones sz ON sz.id=se.speed_zone_id WHERE t.game_id=? ORDER BY se.started_at DESC LIMIT 100');
    $speeding->execute([$gameId]);
    render('admin_live', [
        'game' => $game,
        'scoreboard' => scoreboard($gameId),
        'submissions' => $submissions->fetchAll(),
        'teams' => $teams->fetchAll(),
        'speedingEvents' => $speeding->fetchAll(),
    ]);
}

function register_form(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $gameId = (int)($_GET['game'] ?? 0);
    $params = [];
    $sql = "SELECT * FROM games WHERE status IN ('registration_open','waiting_start','running')";
    if ($gameId > 0) {
        $sql .= ' AND id = ?';
        $params[] = $gameId;
    }
    if ($q !== '') {
        $sql .= ' AND name LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $games = $stmt->fetchAll();
    $overviewBounds = $gameId > 0 && $games ? game_overview_bounds($gameId) : null;
    $played = db()->query("SELECT * FROM games WHERE status = 'results_public' AND public_results_enabled = 1 ORDER BY finished_at DESC, created_at DESC LIMIT 20")->fetchAll();
    render('register', [
        'games' => $games,
        'playedGames' => $played,
        'query' => $q,
        'selectedGameId' => $gameId,
        'selectedGame' => $gameId > 0 ? ($games[0] ?? null) : null,
        'overviewBounds' => $overviewBounds,
    ]);
}

function register_post(): void
{
    require_csrf();
    $gameId = (int)$_POST['game_id'];
    $registerPath = $gameId > 0 ? '/register?game=' . $gameId : '/register';
    $email = strtolower(trim((string)$_POST['email']));
    $teamName = trim((string)$_POST['team_name']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $teamName === '') {
        flash('Sisesta tiiminimi ja korrektne e-mail.');
        redirect_to($registerPath);
    }
    $stmt = db()->prepare("SELECT id FROM games WHERE id = ? AND status IN ('registration_open','waiting_start','running')");
    $stmt->execute([$gameId]);
    if (!$stmt->fetchColumn()) {
        flash('Sellele mängule registreerimine ei ole avatud.');
        redirect_to($registerPath);
    }
    if (!can_send_auth_token('team', $email)) {
        flash('Sellele e-mailile on hiljuti juba mitu linki saadetud. Proovi natuke hiljem uuesti.');
        redirect_to($registerPath);
    }
    $token = create_auth_token('team', $email, $gameId, $teamName);
    send_magic_link($email, config()['url'] . '/auth/magic?token=' . urlencode($token), 'team');
    flash('E-mail on saadetud. Kontrolli oma postkasti ja ava Pimepunkti registreerimislink samas telefonis/brauseris.');
    redirect_to($registerPath);
}

function verify_form(): void
{
    flash('Kinnituskoodi asemel kasuta e-mailile saadetud turvalist linki.');
    redirect_to('/register');
}

function verify_post(): void
{
    require_csrf();
    $email = strtolower(trim((string)($_SESSION['pending_email'] ?? $_POST['email'] ?? '')));
    $gameId = (int)($_SESSION['pending_game_id'] ?? $_POST['game_id'] ?? 0);
    $code = trim((string)$_POST['code']);
    $stmt = db()->prepare('SELECT * FROM login_codes WHERE game_id = ? AND email = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute([$gameId, $email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($code . config()['secret'], $row['code_hash'])) {
        flash('Kinnituskood ei sobinud või on aegunud.');
        redirect_to('/verify');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE login_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        $stmt = $pdo->prepare('SELECT id FROM teams WHERE game_id = ? AND email = ?');
        $stmt->execute([$gameId, $email]);
        $teamId = $stmt->fetchColumn();
        if ($teamId) {
            $pdo->prepare('UPDATE teams SET name = ?, email_verified_at = NOW() WHERE id = ?')->execute([$row['team_name'], $teamId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO teams (game_id, name, email, status, email_verified_at) VALUES (?, ?, ?, "pending", NOW())');
            $stmt->execute([$gameId, $row['team_name'], $email]);
            $teamId = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        create_team_session((int)$teamId);
        redirect_to('/game');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function game_view(): void
{
    $team = current_team();
    if (!$team) {
        redirect_to('/register');
    }
    $game = find_game((int)$team['game_id']);
    if ($team['status'] !== 'approved' || $game['status'] !== 'running') {
        if ($game['status'] === 'results_public') {
            redirect_to('/results/' . $game['id']);
        }
        render('game_wait', ['team' => $team, 'game' => $game]);
        return;
    }
    if ($game['duration_minutes'] && !$team['play_started_at']) {
        render('game_start', [
            'team' => $team,
            'game' => $game,
            'overviewBounds' => game_overview_bounds((int)$game['id']),
        ]);
        return;
    }
    $progressStmt = db()->prepare('SELECT COUNT(*) total_count, COUNT(s.id) answered_count FROM checkpoints c LEFT JOIN submissions s ON s.checkpoint_id=c.id AND s.team_id=? WHERE c.game_id=?');
    $progressStmt->execute([(int)$team['id'], (int)$team['game_id']]);
    $progress = $progressStmt->fetch() ?: ['total_count' => 0, 'answered_count' => 0];
    $latestStmt = db()->prepare('SELECT lat,lng FROM location_logs WHERE team_id=? AND ignored_reason IS NULL ORDER BY id DESC LIMIT 1');
    $latestStmt->execute([(int)$team['id']]);
    $latestLocation = $latestStmt->fetch();

    $order = 'CAST(c.number AS UNSIGNED), c.number';
    $params = [(int)$team['id'], (int)$team['game_id']];
    if ($latestLocation) {
        $order = 'POWER(c.lat - ?, 2) + POWER((c.lng - ?) * COS(RADIANS(?)), 2), CAST(c.number AS UNSIGNED), c.number';
        $params[] = (float)$latestLocation['lat'];
        $params[] = (float)$latestLocation['lng'];
        $params[] = (float)$latestLocation['lat'];
    }
    $stmt = db()->prepare('
        SELECT c.*, q.id AS question_id, q.type AS question_type, q.text AS question_text,
               s.id AS submission_id
        FROM checkpoints c
        JOIN questions q ON q.checkpoint_id = c.id
        LEFT JOIN submissions s ON s.checkpoint_id = c.id AND s.team_id = ?
        WHERE c.game_id = ? AND s.id IS NULL
        ORDER BY ' . $order . '
        LIMIT 60
    ');
    $stmt->execute($params);
    $checkpoints = $stmt->fetchAll();
    $options = [];
    if ($checkpoints) {
        $ids = array_column($checkpoints, 'question_id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT * FROM answer_options WHERE question_id IN ({$in}) ORDER BY id");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $option) {
            $options[(int)$option['question_id']][] = $option;
        }
    }
    render('game_play', [
        'team' => $team,
        'game' => $game,
        'checkpoints' => $checkpoints,
        'options' => $options,
        'progress' => $progress,
        'questionsLimited' => ((int)$progress['total_count'] - (int)$progress['answered_count']) > count($checkpoints),
        'hasLocationForQuestions' => (bool)$latestLocation,
        'deadline' => team_deadline($team, $game),
        'timeExpired' => team_time_expired($team, $game),
    ]);
}

function game_start_post(): void
{
    require_csrf();
    $team = current_team();
    if (!$team || $team['status'] !== 'approved' || $team['game_status'] !== 'running') {
        http_response_code(403);
        exit('Mängu ei saa alustada.');
    }
    $game = find_game((int)$team['game_id']);
    $now = new DateTimeImmutable();
    if (($game['start_window_from'] && $now < new DateTimeImmutable($game['start_window_from'])) ||
        ($game['start_window_to'] && $now > new DateTimeImmutable($game['start_window_to']))) {
        flash('Mängu alustamine ei ole praegu lubatud stardiaknas.');
        redirect_to('/game');
    }
    db()->prepare('UPDATE teams SET play_started_at = COALESCE(play_started_at, NOW()) WHERE id = ?')->execute([(int)$team['id']]);
    audit('team_game_started', (int)$team['game_id'], (int)$team['id']);
    redirect_to('/game');
}

function game_pause_post(bool $resume): void
{
    require_csrf();
    $team = current_team();
    if (!$team || $team['status'] !== 'approved' || $team['game_status'] !== 'running') {
        http_response_code(403);
        exit('Mäng ei ole aktiivne.');
    }
    if (!$team['play_started_at']) {
        http_response_code(409);
        exit('Mäng ei ole veel alanud.');
    }
    $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false) {
        flash('Pausi muutmiseks on vaja GPS-asukohta.');
        redirect_to('/game');
    }
    if ($resume) {
        if (!$team['paused_at']) redirect_to('/game');
        if (haversine_m((float)$team['pause_lat'], (float)$team['pause_lng'], (float)$lat, (float)$lng) > 100) {
            flash('Jätkata saab kuni 100 m kaugusel pausi asukohast.');
            redirect_to('/game');
        }
        db()->prepare('UPDATE teams SET paused_seconds = paused_seconds + TIMESTAMPDIFF(SECOND, paused_at, NOW()), paused_at = NULL, pause_lat = NULL, pause_lng = NULL WHERE id = ?')
            ->execute([(int)$team['id']]);
        audit('team_game_resumed', (int)$team['game_id'], (int)$team['id']);
    } else {
        if ($team['paused_at']) redirect_to('/game');
        db()->prepare('UPDATE teams SET paused_at = NOW(), pause_lat = ?, pause_lng = ? WHERE id = ?')
            ->execute([$lat, $lng, (int)$team['id']]);
        audit('team_game_paused', (int)$team['game_id'], (int)$team['id']);
    }
    redirect_to('/game');
}

function answer_post(): void
{
    require_csrf();
    $team = current_team();
    if (!$team || $team['status'] !== 'approved' || $team['game_status'] !== 'running') {
        http_response_code(403);
        exit('Mäng ei ole vastamiseks avatud.');
    }
    $game = find_game((int)$team['game_id']);
    if (($game['duration_minutes'] && !$team['play_started_at']) || $team['paused_at'] || team_time_expired($team, $game)) {
        if ($game['duration_minutes'] && !$team['play_started_at']) {
            flash('Alusta mäng enne vastamist.');
            redirect_to('/game');
        }
        flash($team['paused_at'] ? 'Mäng on pausil.' : 'Mänguaeg on lõppenud.');
        redirect_to('/game');
    }
    $checkpointId = (int)$_POST['checkpoint_id'];
    $lat = (float)$_POST['lat'];
    $lng = (float)$_POST['lng'];
    $accuracy = $_POST['accuracy'] === '' ? null : (float)$_POST['accuracy'];
    $stmt = db()->prepare('
        SELECT c.*, q.id AS question_id, q.type AS question_type
        FROM checkpoints c
        JOIN questions q ON q.checkpoint_id = c.id
        WHERE c.id = ? AND c.game_id = ?
    ');
    $stmt->execute([$checkpointId, $team['game_id']]);
    $checkpoint = $stmt->fetch();
    if (!$checkpoint) {
        http_response_code(404);
        exit('Punkti ei leitud.');
    }
    $distance = distance_m($lat, $lng, (float)$checkpoint['lat'], (float)$checkpoint['lng']);
    if ($distance > (float)$checkpoint['radius_m']) {
        flash('Sa ei ole veel selle punkti alas.');
        redirect_to('/game');
    }
    $already = db()->prepare('SELECT id FROM submissions WHERE team_id = ? AND checkpoint_id = ?');
    $already->execute([$team['id'], $checkpointId]);
    if ($already->fetchColumn()) {
        flash('Sellele punktile on juba vastatud.');
        redirect_to('/game');
    }

    $optionId = null;
    $ok = 0;
    $isCorrect = 0;
    if ($checkpoint['question_type'] === 'ok') {
        $ok = 1;
        $isCorrect = 1;
    } else {
        $optionId = (int)$_POST['answer_option_id'];
        $stmt = db()->prepare('SELECT is_correct FROM answer_options WHERE id = ? AND question_id = ?');
        $stmt->execute([$optionId, $checkpoint['question_id']]);
        $isCorrect = (int)$stmt->fetchColumn() === 1 ? 1 : 0;
    }
    $stmt = db()->prepare('
        INSERT INTO submissions (team_id, checkpoint_id, question_id, answer_option_id, ok_answer, lat, lng, accuracy_m, distance_m, is_correct)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$team['id'], $checkpointId, $checkpoint['question_id'], $optionId, $ok, $lat, $lng, $accuracy, $distance, $isCorrect]);
    $progress = db()->prepare('
        SELECT
          (SELECT COUNT(*) FROM checkpoints WHERE game_id = ?) AS total_count,
          (SELECT COUNT(*) FROM submissions WHERE team_id = ?) AS answered_count
    ');
    $progress->execute([(int)$team['game_id'], (int)$team['id']]);
    $counts = $progress->fetch();
    if ($counts && (int)$counts['total_count'] > 0 && (int)$counts['answered_count'] >= (int)$counts['total_count']) {
        flash('Tubli! Kõik küsimused on vastatud ja mäng on edukalt läbitud.');
    } else {
        flash('Vastus on vastu võetud.');
    }
    redirect_to('/game');
}

function location_post(): void
{
    $team = current_team();
    if (!$team || $team['status'] !== 'approved' || $team['game_status'] !== 'running' || !$team['play_started_at'] || $team['paused_at']) {
        http_response_code(204);
        return;
    }
    $game = find_game((int)$team['game_id']);
    if (team_time_expired($team, $game)) {
        http_response_code(204);
        return;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $lat = filter_var($payload['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($payload['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $accuracy = filter_var($payload['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $accuracy === false || abs((float)$lat) > 90 || abs((float)$lng) > 180) {
        http_response_code(422);
        return;
    }
    $result = record_location_and_speed($team, (float)$lat, (float)$lng, max(0, (float)$accuracy));
    header('Content-Type: application/json');
    echo json_encode($result);
}

function game_gpx_download(int $gameId): void
{
    $game = find_game($gameId);
    $team = current_team();
    $admin = current_admin();
    $allowedTeam = $team && (int)$team['game_id'] === $gameId && $team['status'] === 'approved';
    if (!$admin && (!$allowedTeam || (int)$game['allow_gpx_export'] !== 1)) {
        http_response_code(404);
        exit('GPX eksport ei ole lubatud.');
    }
    header('Content-Type: application/gpx+xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pimepunkt-' . $gameId . '.gpx"');
    echo game_gpx($gameId);
}

function game_traffic_json(int $gameId): void
{
    $game = find_game($gameId);
    $team = current_team();
    $admin = current_admin();
    if (!$admin && (!$team || (int)$team['game_id'] !== $gameId || $team['status'] !== 'approved')) {
        http_response_code(403);
        return;
    }
    $restrictions = ['type' => 'FeatureCollection', 'features' => []];
    if ((int)$game['show_traffic_restrictions'] === 1) {
        try { $restrictions = tarktee_restrictions_geojson($gameId); } catch (Throwable $e) { error_log($e); }
    }
    $zones = db()->prepare('SELECT id, source, name, speed_limit_kmh, geometry_type, center_lat, center_lng, radius_m, geometry_json FROM speed_zones WHERE game_id = ?');
    $zones->execute([$gameId]);
    header('Content-Type: application/json');
    echo json_encode(['restrictions' => $restrictions, 'speed_zones' => $zones->fetchAll()], JSON_UNESCAPED_UNICODE);
}

function admin_locations_json(int $gameId): void
{
    require_admin();
    require_game_access($gameId);
    $stmt = db()->prepare('
        SELECT t.id AS team_id, t.name, l.lat, l.lng, l.accuracy_m, l.filtered_speed_kmh, l.speed_limit_kmh, l.ignored_reason, l.created_at
        FROM teams t
        JOIN location_logs l ON l.team_id = t.id
        WHERE t.game_id = ?
        ORDER BY t.name, l.created_at
    ');
    $stmt->execute([$gameId]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
}

function public_results(int $gameId): void
{
    $game = find_game($gameId);
    if ($game['status'] !== 'results_public' || (int)$game['public_results_enabled'] !== 1) {
        http_response_code(404);
        render('error', ['message' => 'Tulemused ei ole veel avalikud.']);
        return;
    }
    $paths = db()->prepare('SELECT t.id, t.name, l.lat, l.lng, l.accuracy_m, l.created_at FROM teams t LEFT JOIN location_logs l ON l.team_id = t.id WHERE t.game_id = ? AND t.excluded_from_results = 0 ORDER BY t.name, l.created_at');
    $paths->execute([$gameId]);
    render('results', ['game' => $game, 'scoreboard' => scoreboard($gameId), 'paths' => $paths->fetchAll()]);
}

function admin_create_admin(): void
{
    require_csrf();
    $admin = current_admin();
    if ((int)$admin['is_super'] !== 1) {
        http_response_code(403);
        render('error', ['message' => 'Ainult peadmin saab süsteemi admine lisada.']);
        return;
    }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Sisesta korrektne e-mail.');
        redirect_to('/admin');
    }
    db()->prepare('INSERT IGNORE INTO admins (email, is_super) VALUES (?, 0)')->execute([$email]);
    audit('admin_created', null, null, ['email' => $email]);
    redirect_to('/admin');
}

function admin_delete_admin(int $adminId): void
{
    require_csrf();
    $admin = current_admin();
    if ((int)$admin['is_super'] !== 1 || (int)$admin['id'] === $adminId) {
        http_response_code(403);
        render('error', ['message' => 'Seda admini ei saa eemaldada.']);
        return;
    }
    db()->prepare('DELETE FROM admins WHERE id = ? AND is_super = 0')->execute([$adminId]);
    audit('admin_deleted', null, null, ['admin_id' => $adminId]);
    redirect_to('/admin');
}

function admin_game_delete(int $gameId): void
{
    require_game_owner_or_super($gameId);
    require_csrf();
    db()->prepare('DELETE FROM games WHERE id = ?')->execute([$gameId]);
    audit('game_deleted', $gameId);
    redirect_to('/admin');
}

function admin_game_play(int $gameId): void
{
    require_game_access($gameId);
    require_csrf();

    $admin = current_admin();
    if (!$admin) {
        redirect_to('/admin/login');
    }

    $game = find_game($gameId);
    if ($game['status'] === 'results_public') {
        redirect_to('/results/' . $gameId);
    }

    $email = strtolower((string)$admin['email']);
    $name = 'Admin: ' . $email;
    $stmt = db()->prepare('SELECT id FROM teams WHERE game_id = ? AND email = ?');
    $stmt->execute([$gameId, $email]);
    $teamId = $stmt->fetchColumn();
    if ($teamId) {
        db()->prepare('UPDATE teams SET name = ?, status = "approved", email_verified_at = NOW() WHERE id = ?')->execute([$name, $teamId]);
    } else {
        $stmt = db()->prepare('INSERT INTO teams (game_id, name, email, status, email_verified_at) VALUES (?, ?, ?, "approved", NOW())');
        $stmt->execute([$gameId, $name, $email]);
        $teamId = (int)db()->lastInsertId();
    }

    create_team_session((int)$teamId);
    audit('admin_started_playing', $gameId, (int)$teamId);
    redirect_to('/game');
}

function admin_game_admin_add(int $gameId): void
{
    require_game_owner_or_super($gameId);
    require_csrf();
    $adminId = (int)($_POST['admin_id'] ?? 0);
    if ($adminId > 0) {
        db()->prepare('INSERT IGNORE INTO game_admins (game_id, admin_id) VALUES (?, ?)')->execute([$gameId, $adminId]);
        audit('game_admin_added', $gameId, null, ['admin_id' => $adminId]);
    }
    redirect_to('/admin/games/' . $gameId);
}

function admin_game_admin_delete(int $gameId, int $adminId): void
{
    require_game_owner_or_super($gameId);
    require_csrf();
    $game = find_game($gameId);
    if ((int)$game['created_by_admin_id'] !== $adminId) {
        db()->prepare('DELETE FROM game_admins WHERE game_id = ? AND admin_id = ?')->execute([$gameId, $adminId]);
        audit('game_admin_deleted', $gameId, null, ['admin_id' => $adminId]);
    }
    redirect_to('/admin/games/' . $gameId);
}

function accessible_games(array $admin): array
{
    if ((int)$admin['is_super'] === 1) {
        return db()->query('SELECT g.*, a.email AS owner_email FROM games g LEFT JOIN admins a ON a.id = g.created_by_admin_id ORDER BY g.id DESC')->fetchAll();
    }
    $stmt = db()->prepare('
        SELECT DISTINCT g.*, a.email AS owner_email
        FROM games g
        LEFT JOIN admins a ON a.id = g.created_by_admin_id
        LEFT JOIN game_admins ga ON ga.game_id = g.id
        WHERE g.created_by_admin_id = ? OR ga.admin_id = ?
        ORDER BY g.id DESC
    ');
    $stmt->execute([(int)$admin['id'], (int)$admin['id']]);
    return $stmt->fetchAll();
}

function can_access_game(array $admin, int $gameId): bool
{
    if ((int)$admin['is_super'] === 1) {
        return true;
    }
    $stmt = db()->prepare('SELECT 1 FROM games g LEFT JOIN game_admins ga ON ga.game_id = g.id AND ga.admin_id = ? WHERE g.id = ? AND (g.created_by_admin_id = ? OR ga.admin_id IS NOT NULL)');
    $stmt->execute([(int)$admin['id'], $gameId, (int)$admin['id']]);
    return (bool)$stmt->fetchColumn();
}

function require_game_access(int $gameId): void
{
    $admin = current_admin();
    if (!$admin || !can_access_game($admin, $gameId)) {
        http_response_code(403);
        render('error', ['message' => 'Sul ei ole selle mängu õigust.']);
        exit;
    }
}

function require_game_owner_or_super(int $gameId): void
{
    $admin = current_admin();
    $game = find_game($gameId);
    if (!$admin || ((int)$admin['is_super'] !== 1 && (int)$game['created_by_admin_id'] !== (int)$admin['id'])) {
        http_response_code(403);
        render('error', ['message' => 'Ainult mängu looja saab selle mängu admine muuta.']);
        exit;
    }
}

function game_admins(int $gameId): array
{
    $stmt = db()->prepare('SELECT a.* FROM game_admins ga JOIN admins a ON a.id = ga.admin_id WHERE ga.game_id = ? ORDER BY a.email');
    $stmt->execute([$gameId]);
    return $stmt->fetchAll();
}

function find_game(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM games WHERE id = ?');
    $stmt->execute([$id]);
    $game = $stmt->fetch();
    if (!$game) {
        throw new RuntimeException('Mängu ei leitud.');
    }
    return $game;
}

function find_team(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM teams WHERE id = ?');
    $stmt->execute([$id]);
    $team = $stmt->fetch();
    if (!$team) {
        throw new RuntimeException('Tiimi ei leitud.');
    }
    return $team;
}

function next_checkpoint_number(int $gameId): string
{
    $stmt = db()->prepare('SELECT number FROM checkpoints WHERE game_id = ?');
    $stmt->execute([$gameId]);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
        if (preg_match('/^\d+$/', (string)$number)) {
            $max = max($max, (int)$number);
        }
    }
    return (string)($max + 1);
}

function ai_map_prompt(array $game, array $checkpoints): string
{
    $pointLines = [];
    $lats = [];
    $lngs = [];
    foreach ($checkpoints as $index => $checkpoint) {
        $lat = (float)$checkpoint['lat'];
        $lng = (float)$checkpoint['lng'];
        $lats[] = $lat;
        $lngs[] = $lng;
        if ($index < 200) {
            $pointLines[] = sprintf(
                '- Punkt %s: %.7F, %.7F, raskus %d (%s)',
                (string)$checkpoint['number'],
                $lat,
                $lng,
                checkpoint_difficulty($checkpoint['difficulty'] ?? 1),
                checkpoint_difficulty_label($checkpoint['difficulty'] ?? 1)
            );
        }
    }
    if (count($checkpoints) > 200) {
        $pointLines[] = sprintf('- Ülejäänud %d punkti on prompti mahu tõttu välja jäetud; kasuta täielikuks andmestikuks GPX eksporti.', count($checkpoints) - 200);
    }

    $bounds = '';
    if ($lats && $lngs) {
        $bounds = sprintf(
            "Ala ligikaudsed piirid: lat %.7F kuni %.7F, lng %.7F kuni %.7F.\n",
            min($lats),
            max($lats),
            min($lngs),
            max($lngs)
        );
    }

    $points = $pointLines ? implode("\n", $pointLines) : '- Punkte pole veel lisatud. Loo esmalt kontrollpunktid ja uuenda prompti.';

    return trim(
        "Loo Pimepunkti mängu jaoks prinditav ja mobiilis loetav JPG/PNG kaart.\n" .
        "Kasuta kaasa pandud päris kaardi pilti alusena. Ära loo uut kujuteldavat kaardialust.\n" .
        "Mängu nimi: " . (string)$game['name'] . "\n" .
        $bounds .
        "Kontrollpunktid:\n" . $points . "\n\n" .
        "Oluline:\n" .
        "- Säilita kaasa pandud kaardi teede, radade, majade, põldude, metsaalade, kraavide ja veekogude tegelik paiknemine.\n" .
        "- Ära lisa uusi teid, veekogusid, maju, rannajoont ega muid objekte, mida aluskaardil ei ole.\n" .
        "- Ära kasuta koordinaate kaardi väljamõtlemiseks; koordinaadid on ainult punktide kontrolliks.\n" .
        "- Eemalda või peida aluskaardilt kohanimed, talunimed, tee numbrid ja muud tekstilised vihjed.\n\n" .
        "Kaardi stiil:\n" .
        "- Skandinaavialikult lihtne, hele ja puhas kujundus.\n" .
        "- Kasuta pastelseid Muhu värve aktsentidena: roosa, oranž, kollane, salveiroheline ja suitsusinine.\n" .
        "- Kaart võib olla veidi lihtsustatud ja 90 kraadi pööratud, aga aluskaardi geomeetria peab jääma äratuntavaks.\n" .
        "- Märgi raskus 1 ringi, raskus 2 kolmnurga, raskus 3 nelinurga, raskus 4 viisnurga, raskus 5 kuusnurga ja raskus 6 seitsenurgaga; kujundi keskel olgu punkt ning juures ainult punkti number.\n" .
        "- Väldi liigset dekoratsiooni; kaart peab olema mängijale päriselt navigeeritav.\n" .
        "- Väljund horisontaalne 16:9 või A4 landscape, kõrge resolutsioon, JPG/PNG."
    );
}

function gpx_ai_prompt(array $game): string
{
    return trim(
        "Loo Pimepunkti mängu \"" . (string)$game['name'] . "\" jaoks GPX fail kontrollpunktidega.\n" .
        "Kasuta ainult GPX waypoint'e ehk <wpt> elemente.\n" .
        "Iga waypoint peab olema kujul:\n" .
        "- lat ja lon atribuudid sisaldavad punkti GPS koordinaate.\n" .
        "- <name> sisaldab punkti numbrit, näiteks 1.\n" .
        "- <type> sisaldab punkti või küsimuse nime, näiteks Vana kaev.\n" .
        "- <desc> sisaldab küsimuse teksti.\n" .
        "- <extensions><difficulty>1</difficulty></extensions> sisaldab raskust 1 kuni 6: 1 kerge tee ääres, 2 keerukam metsateel, 3 keerukas kehvemal metsateel, 4 eriti keerukas ja võib olla mudane, 5 väga keerukas, 6 ekstreemne.\n" .
        "- <cmt> sisaldab valikvastuseid kujul \"*õige vastus|vale vastus|vale vastus\". Kui küsimus on ainult kohaloleku kinnitamine, jäta <cmt> tühjaks.\n\n" .
        "Tee 2 kuni 5 vastusevarianti. Märgi täpselt üks õige vastus tärniga. Ära lisa marsruute ega träkke."
    );
}

function sample_gpx(string $gameName): string
{
    $safeName = htmlspecialchars($gameName, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return <<<GPX
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="Pimepunkt" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata>
    <name>{$safeName} näidis</name>
  </metadata>
  <wpt lat="58.6389430" lon="23.1680829">
    <name>1</name>
    <type>Vana kaev</type>
    <desc>Mis värvi on punkti juures olev märk?</desc>
    <cmt>*sinine|punane|kollane</cmt>
    <extensions><difficulty>1</difficulty></extensions>
  </wpt>
  <wpt lat="58.6388489" lon="23.1692863">
    <name>2</name>
    <type>Teine kontrollpunkt</type>
    <desc>Kinnita kohalolek teises punktis.</desc>
    <cmt></cmt>
    <extensions><difficulty>3</difficulty></extensions>
  </wpt>
</gpx>
GPX;
}

function scoreboard(int $gameId): array
{
    $game = find_game($gameId);
    $stmt = db()->prepare('
        SELECT t.id, t.name, t.status,
               COUNT(s.id) AS visited,
               SUM(CASE WHEN COALESCE(s.admin_correct_override, s.is_correct) = 1 THEN 1 ELSE 0 END) AS correct_count,
               SUM(CASE WHEN s.id IS NOT NULL AND COALESCE(s.admin_correct_override, s.is_correct) = 0 THEN 1 ELSE 0 END) AS wrong_count,
               COALESCE(SUM(
                 COALESCE(c.visit_points, ? + CASE c.difficulty WHEN 2 THEN 2 WHEN 3 THEN 4 WHEN 4 THEN 7 WHEN 5 THEN 10 WHEN 6 THEN 13 ELSE 0 END) -
                 CASE WHEN COALESCE(s.admin_correct_override, s.is_correct) = 1 THEN 0 ELSE COALESCE(c.wrong_penalty, ?) END +
                 s.admin_score_adjustment
               ), 0) - COALESCE((SELECT SUM(se.penalty_points) FROM speeding_events se WHERE se.team_id = t.id AND se.status = "confirmed"), 0) AS score,
               COALESCE((SELECT SUM(se.penalty_points) FROM speeding_events se WHERE se.team_id = t.id AND se.status = "confirmed"), 0) AS speeding_penalty,
               CASE WHEN t.play_started_at IS NULL THEN NULL ELSE
                 LEAST(?, GREATEST(0,
                   CAST(TIMESTAMPDIFF(SECOND, t.play_started_at,
                     GREATEST(
                       COALESCE((SELECT MAX(s2.created_at) FROM submissions s2 WHERE s2.team_id = t.id), t.play_started_at),
                       COALESCE((SELECT MAX(l2.created_at) FROM location_logs l2 WHERE l2.team_id = t.id), t.play_started_at)
                     )
                   ) AS SIGNED) - CAST(t.paused_seconds AS SIGNED)
                 ))
               END AS elapsed_seconds
        FROM teams t
        LEFT JOIN submissions s ON s.team_id = t.id
        LEFT JOIN checkpoints c ON c.id = s.checkpoint_id
        WHERE t.game_id = ? AND t.excluded_from_results = 0
        GROUP BY t.id
        ORDER BY score DESC, correct_count DESC, visited DESC, t.name
    ');
    $maximumSeconds = $game['duration_minutes'] ? (int)$game['duration_minutes'] * 60 : 2147483647;
    $stmt->execute([(int)$game['default_visit_points'], (int)$game['default_wrong_penalty'], $maximumSeconds, $gameId]);
    return $stmt->fetchAll();
}
