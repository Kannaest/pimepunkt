<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Tallinn');

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $basePath = rtrim(env_value('APP_BASE_PATH', '/pimepunkt') ?? '', '/');
    if ($basePath === '') {
        $basePath = '';
    }

    $config = [
        'name' => env_value('APP_NAME', 'Pimepunkt'),
        'version' => env_value('APP_VERSION', '0.1.0'),
        'base_path' => $basePath,
        'url' => rtrim(env_value('APP_URL', 'http://localhost:8088') ?? '', '/'),
        'secret' => env_value('APP_SECRET', 'dev-secret'),
        'db' => [
            'host' => env_value('DB_HOST', 'mysql'),
            'port' => env_value('DB_PORT', '3306'),
            'name' => env_value('DB_NAME', 'pimepunkt'),
            'user' => env_value('DB_USER', 'pimepunkt'),
            'password' => env_value('DB_PASSWORD', ''),
        ],
        'admin' => [
            'email' => env_value('ADMIN_EMAIL', 'admin@example.com'),
        ],
        'mail' => [
            'from' => env_value('MAIL_FROM', 'no-reply@kand.ee'),
            'mode' => env_value('MAIL_MODE', 'log'),
            'smtp_host' => env_value('SMTP_HOST', ''),
            'smtp_port' => (int)(env_value('SMTP_PORT', '587') ?? '587'),
            'smtp_user' => env_value('SMTP_USER', ''),
            'smtp_password' => env_value('SMTP_PASSWORD', ''),
            'smtp_secure' => env_value('SMTP_SECURE', 'tls'),
        ],
        'nutilogi' => [
            'firebase_api_key' => env_value('NUTILOGI_FIREBASE_API_KEY', 'AIzaSyDIGG0DLBM_HOzM9gubl9ckeYZT05slo58'),
        ],
    ];

    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = config()['db'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => (config()['base_path'] ?: '/') . '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('CSRF kontroll ebaõnnestus.');
    }
}

function path(string $path = ''): string
{
    $base = config()['base_path'];
    $path = '/' . ltrim($path, '/');
    return ($base ?: '') . ($path === '/' ? '' : $path);
}

function asset_version(string $path): string
{
    $file = __DIR__ . '/../public/' . ltrim($path, '/');
    return is_file($file) ? (string)filemtime($file) : config()['version'];
}

function redirect_to(string $path): never
{
    header('Location: ' . path($path));
    exit;
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(self)');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://unpkg.com; img-src 'self' data: https://tiles.maaamet.ee https://unpkg.com; connect-src 'self' https://unpkg.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

function session_token_hash(string $token): string
{
    return hash('sha256', $token . config()['secret']);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function checkpoint_difficulty(int|string|null $value): int
{
    $difficulty = (int)$value;
    if ($difficulty <= 1) {
        return 1;
    }
    return min($difficulty, 6);
}

function checkpoint_difficulty_label(int|string|null $value): string
{
    return match (checkpoint_difficulty($value)) {
        2 => 'Keerukam',
        3 => 'Keerukas',
        4 => 'Eriti keerukas',
        5 => 'Väga keerukas',
        6 => 'Ekstreemne',
        default => 'Kerge',
    };
}

function checkpoint_difficulty_bonus(int|string|null $value): int
{
    return match (checkpoint_difficulty($value)) {
        2 => 2,
        3 => 4,
        4 => 7,
        5 => 10,
        6 => 13,
        default => 0,
    };
}

function checkpoint_visit_points(array $checkpoint, array $game): int
{
    if ($checkpoint['visit_points'] !== null) {
        return (int)$checkpoint['visit_points'];
    }
    return (int)$game['default_visit_points'] + checkpoint_difficulty_bonus($checkpoint['difficulty'] ?? 1);
}

function flash(?string $message = null): ?string
{
    start_session();
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $current = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $current;
}

function is_admin(): bool
{
    return current_admin() !== null;
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect_to('/admin/login');
    }
}

function current_admin(): ?array
{
    start_session();
    if (!empty($_SESSION['admin_id'])) {
        $admin = find_admin_by_id((int)$_SESSION['admin_id']);
        if ($admin) {
            return $admin;
        }
    }

    $token = $_COOKIE['pimepunkt_admin'] ?? null;
    if (!is_string($token) || $token === '') {
        return null;
    }
    $tokenHash = session_token_hash($token);
    $stmt = db()->prepare('
        SELECT a.*
        FROM admin_sessions s
        JOIN admins a ON a.id = s.admin_id
        WHERE s.token IN (?, ?) AND s.expires_at > NOW()
    ');
    $stmt->execute([$tokenHash, $token]);
    $admin = $stmt->fetch();
    if (!$admin) {
        return null;
    }
    $_SESSION['admin_id'] = (int)$admin['id'];
    db()->prepare('UPDATE admin_sessions SET token = ?, expires_at = ? WHERE token IN (?, ?)')->execute([
        $tokenHash,
        (new DateTimeImmutable('+180 days'))->format('Y-m-d H:i:s'),
        $tokenHash,
        $token,
    ]);
    set_admin_cookie($token);
    return $admin;
}

function find_admin_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$id]);
    $admin = $stmt->fetch();
    return $admin ?: null;
}

function find_admin_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM admins WHERE email = ?');
    $stmt->execute([strtolower($email)]);
    $admin = $stmt->fetch();
    return $admin ?: null;
}

function ensure_config_admin(): array
{
    $email = strtolower(config()['admin']['email']);
    $admin = find_admin_by_email($email);
    if ($admin) {
        if ((int)$admin['is_super'] !== 1) {
            db()->prepare('UPDATE admins SET is_super = 1 WHERE id = ?')->execute([$admin['id']]);
            $admin['is_super'] = 1;
        }
        return $admin;
    }
    $stmt = db()->prepare('INSERT INTO admins (email, is_super) VALUES (?, 1)');
    $stmt->execute([$email]);
    return find_admin_by_id((int)db()->lastInsertId());
}

function create_admin_session(int $adminId): void
{
    start_session();
    session_regenerate_id(true);
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+180 days'))->format('Y-m-d H:i:s');
    db()->prepare('INSERT INTO admin_sessions (admin_id, token, expires_at) VALUES (?, ?, ?)')->execute([$adminId, session_token_hash($token), $expires]);
    $_SESSION['admin_id'] = $adminId;
    set_admin_cookie($token);
}

function set_admin_cookie(string $token): void
{
    setcookie('pimepunkt_admin', $token, [
        'expires' => time() + 60 * 60 * 24 * 180,
        'path' => (config()['base_path'] ?: '/') . '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function current_team(): ?array
{
    start_session();
    $token = $_SESSION['team_token'] ?? ($_COOKIE['pimepunkt_team'] ?? null);
    if (!is_string($token) || $token === '') {
        return null;
    }
    $tokenHash = session_token_hash($token);

    $stmt = db()->prepare('
        SELECT t.*, g.name AS game_name, g.status AS game_status, g.map_path
        FROM sessions s
        JOIN teams t ON t.id = s.team_id
        JOIN games g ON g.id = t.game_id
        WHERE s.token IN (?, ?) AND s.expires_at > NOW()
    ');
    $stmt->execute([$tokenHash, $token]);
    $team = $stmt->fetch();
    if (!$team) {
        return null;
    }

    $_SESSION['team_token'] = $token;
    db()->prepare('UPDATE sessions SET token = ?, expires_at = ? WHERE token IN (?, ?)')->execute([
        $tokenHash,
        (new DateTimeImmutable('+180 days'))->format('Y-m-d H:i:s'),
        $tokenHash,
        $token,
    ]);
    set_team_cookie($token);
    return $team;
}

function create_team_session(int $teamId): void
{
    start_session();
    session_regenerate_id(true);
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+180 days'))->format('Y-m-d H:i:s');
    $stmt = db()->prepare('INSERT INTO sessions (team_id, token, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$teamId, session_token_hash($token), $expires]);
    $_SESSION['team_token'] = $token;
    set_team_cookie($token);
}

function set_team_cookie(string $token): void
{
    setcookie('pimepunkt_team', $token, [
        'expires' => time() + 60 * 60 * 24 * 180,
        'path' => (config()['base_path'] ?: '/') . '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function create_auth_token(string $purpose, string $email, ?int $gameId = null, ?string $teamName = null): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token . config()['secret']);
    $expires = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
    $stmt = db()->prepare('
        INSERT INTO auth_tokens (purpose, game_id, team_name, email, token_hash, expires_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$purpose, $gameId, $teamName, $email, $hash, $expires]);
    return $token;
}

function can_send_auth_token(string $purpose, string $email): bool
{
    $stmt = db()->prepare('
        SELECT COUNT(*)
        FROM auth_tokens
        WHERE purpose = ? AND email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ');
    $stmt->execute([$purpose, strtolower($email)]);
    return (int)$stmt->fetchColumn() < 5;
}

function find_auth_token(string $token): ?array
{
    $hash = hash('sha256', $token . config()['secret']);
    $stmt = db()->prepare('SELECT * FROM auth_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_auth_token_history(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $hash = hash('sha256', $token . config()['secret']);
    $stmt = db()->prepare('SELECT * FROM auth_tokens WHERE token_hash = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function use_auth_token(int $id): void
{
    $stmt = db()->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE id = ?');
    $stmt->execute([$id]);
}

function audit(string $action, ?int $gameId = null, ?int $teamId = null, array $data = []): void
{
    $stmt = db()->prepare('INSERT INTO audit_log (game_id, team_id, actor, action, data_json) VALUES (?, ?, ?, ?, ?)');
    $admin = current_admin();
    $actor = $admin ? 'admin:' . $admin['email'] : 'system';
    $stmt->execute([$gameId, $teamId, $actor, $action, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

function render(string $template, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $appName = config()['name'];
    $flash = flash();
    $currentAdmin = current_admin();
    $currentTeam = current_team();
    require __DIR__ . '/../templates/layout.php';
}

function partial(string $template, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require __DIR__ . '/../templates/' . $template . '.php';
}

function send_magic_link(string $email, string $link, string $purpose): void
{
    $subject = $purpose === 'admin' ? 'Pimepunkt admini sisselogimislink' : 'Pimepunkt registreerimislink';
    $body = "Ava Pimepunkti jätkamiseks see link:\n\n{$link}\n\nLink kehtib 30 minutit ja seda saab kasutada üks kord.";
    if (config()['mail']['mode'] === 'smtp') {
        send_smtp_email($email, $subject, $body);
        return;
    }
    if (config()['mail']['mode'] === 'mail') {
        @mail($email, $subject, $body, 'From: ' . config()['mail']['from']);
        return;
    }

    $line = sprintf("[%s] To: %s Purpose: %s Link: %s\n", date('c'), $email, $purpose, $link);
    file_put_contents(__DIR__ . '/../storage/mail/magic-links.log', $line, FILE_APPEND);
}

function send_smtp_email(string $to, string $subject, string $body): void
{
    $mail = config()['mail'];
    if ($mail['smtp_host'] === '' || $mail['smtp_user'] === '' || $mail['smtp_password'] === '') {
        throw new RuntimeException('SMTP ei ole seadistatud.');
    }

    $secure = strtolower((string)$mail['smtp_secure']);
    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $mail['smtp_host'] . ':' . $mail['smtp_port'];
    $socket = stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException("SMTP ühendus ebaõnnestus: {$errstr}");
    }
    stream_set_timeout($socket, 20);

    smtp_expect($socket, 220);
    smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'kand.ee'), 250);

    if ($secure === 'tls') {
        smtp_command($socket, 'STARTTLS', 220);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('SMTP TLS käivitamine ebaõnnestus.');
        }
        smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'kand.ee'), 250);
    }

    smtp_command($socket, 'AUTH LOGIN', 334);
    smtp_command($socket, base64_encode((string)$mail['smtp_user']), 334);
    smtp_command($socket, base64_encode((string)$mail['smtp_password']), 235);

    smtp_command($socket, 'MAIL FROM:<' . $mail['from'] . '>', 250);
    smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    smtp_command($socket, 'DATA', 354);

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'From: Pimepunkt <' . $mail['from'] . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));
    $message = preg_replace('/^\./m', '..', $message);
    fwrite($socket, $message . "\r\n.\r\n");
    smtp_expect($socket, 250);
    smtp_command($socket, 'QUIT', 221);
    fclose($socket);
}

function smtp_command($socket, string $command, int|array $expected): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expected);
}

function smtp_expect($socket, int|array $expected): string
{
    $expectedCodes = is_array($expected) ? $expected : [$expected];
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('SMTP server ei vastanud.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');

    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP vastus ei sobinud: ' . trim($response));
    }
    return $response;
}

function distance_m(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
