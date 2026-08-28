<?php
declare(strict_types=1);

/**
 * Authentication: Google OAuth primary, email whitelist fallback.
 * Uses a signed cookie token (stateless, no server-side sessions) to avoid
 * SQLite locking issues on shared hosting under concurrent requests.
 */

// ── Config ──
$oauthConfigFile = APP_ROOT . '/config/oauth.php';
if (is_file($oauthConfigFile)) {
    $oauthConfig = require $oauthConfigFile;
} else {
    $oauthConfig = ['client_id' => '', 'client_secret' => '', 'auth_secret' => 'choreo-default-secret-change-me'];
}
define('GOOGLE_CLIENT_ID', $oauthConfig['client_id']);
define('GOOGLE_CLIENT_SECRET', $oauthConfig['client_secret']);
define('AUTH_SECRET', $oauthConfig['auth_secret'] ?? 'choreo-default-secret-change-me');
define('GOOGLE_SCOPES', 'email profile');

// ── Signed token helpers ──
function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return (string)base64_decode(strtr($data, '-_', '+/'), true);
}

function make_token(int $userId): string
{
    $payload = json_encode(['uid' => $userId, 'exp' => time() + 86400 * 30]);
    $sig = hash_hmac('sha256', $payload, AUTH_SECRET, true);
    return base64url_encode($payload) . '.' . base64url_encode($sig);
}

function verify_token(string $token): ?int
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    $payload = base64url_decode($parts[0]);
    $sig = base64url_decode($parts[1]);
    $expected = hash_hmac('sha256', $payload, AUTH_SECRET, true);
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $data = json_decode($payload, true);
    if (!$data || !isset($data['uid']) || (int)($data['exp'] ?? 0) < time()) {
        return null;
    }
    return (int)$data['uid'];
}

function set_auth_cookie(int $userId): void
{
    $ok = setcookie('auth_token', make_token($userId), [
        'expires' => time() + 86400 * 30,
        'path' => APP_BASE ?: '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!$ok) {
        error_log('Failed to set auth cookie');
    }
}

function clear_auth_cookie(): void
{
    setcookie('auth_token', '', [
        'expires' => 1,
        'path' => APP_BASE ?: '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function current_user_array(): ?array
{
    if (empty($_COOKIE['auth_token'])) {
        return null;
    }
    $uid = verify_token($_COOKIE['auth_token']);
    if ($uid === null) {
        return null;
    }
    $user = db_row('SELECT * FROM users WHERE id = ' . $uid);
    if ($user) {
        return normalize_user($user);
    }
    return null;
}

function require_auth(): array
{
    $user = current_user_array();
    if ($user === null) {
        http_response_code(401);
        json_out(['detail' => 'Not authenticated', 'auth_required' => true]);
        exit;
    }
    if (!$user['approved']) {
        http_response_code(403);
        json_out(['detail' => 'Account not approved. Contact admin.']);
        exit;
    }
    return $user;
}

function require_admin(): array
{
    $user = require_auth();
    if ($user['role'] !== 'admin') {
        throw new ApiError('Admin required', 403);
    }
    return $user;
}

function normalize_user(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'organization_id' => (int)$row['organization_id'],
        'email' => (string)$row['email'],
        'name' => (string)$row['name'],
        'role' => (string)$row['role'],
        'approved' => to_bool($row['approved']),
        'created_at' => (string)$row['created_at'],
        'last_login' => $row['last_login'] ? (string)$row['last_login'] : null,
    ];
}

// ── Google OAuth ──

function google_redirect_uri(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'siamkoala.com';
    return 'https://' . $host . APP_BASE . '/auth/google/callback';
}

function google_auth_redirect(): void
{
    $params = http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => GOOGLE_SCOPES,
        'access_type' => 'offline',
        'prompt' => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

function google_auth_callback(): void
{
    if (empty($_GET['code'])) {
        throw new ApiError('No code received from Google', 400);
    }

    $tokenData = google_exchange_code($_GET['code']);
    if (empty($tokenData['access_token'])) {
        throw new ApiError('Failed to get access token from Google', 400);
    }

    $googleUser = google_get_user_info($tokenData['access_token']);
    if (empty($googleUser['id']) || empty($googleUser['email'])) {
        throw new ApiError('Failed to get user info from Google', 400);
    }

    $user = find_or_create_google_user($googleUser);
    set_auth_cookie($user['id']);

    header('Location: ' . APP_BASE);
    exit;
}

function google_exchange_code(string $code): array
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri' => google_redirect_uri(),
            'grant_type' => 'authorization_code',
        ]),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

function google_get_user_info(string $accessToken): array
{
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

function find_or_create_google_user(array $googleUser): array
{
    $googleSub = $googleUser['id'];
    $email = $googleUser['email'];
    $name = $googleUser['name'] ?? $email;

    $user = db_row('SELECT * FROM users WHERE google_sub = ' . db_quote($googleSub));
    if ($user) {
        db_exec('UPDATE users SET last_login = ' . db_quote(now_iso()) . ' WHERE id = ' . $user['id']);
        return normalize_user(db_row('SELECT * FROM users WHERE id = ' . $user['id']));
    }

    $approved = is_email_whitelisted($email) ? 1 : 0;
    $orgId = ensure_default_org();
    $id = db_insert("INSERT INTO users (organization_id, email, name, google_sub, role, approved, created_at, last_login) VALUES ("
        . $orgId . ', ' . db_quote($email) . ', ' . db_quote($name) . ', ' . db_quote($googleSub)
        . ", 'artist', $approved, '" . now_iso() . "', '" . now_iso() . "')");

    return normalize_user(db_row('SELECT * FROM users WHERE id = ' . $id));
}

// ── Email whitelist fallback ──

function is_email_whitelisted(string $email): bool
{
    $email = strtolower(trim($email));
    $row = db_row('SELECT id FROM whitelisted_emails WHERE LOWER(email) = ' . db_quote($email));
    return $row !== null;
}

function email_login(string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        throw new ApiError('Email required', 400);
    }

    if (!is_email_whitelisted($email)) {
        throw new ApiError('Email not authorized. Contact admin to get access.', 403);
    }

    $user = db_row('SELECT * FROM users WHERE LOWER(email) = ' . db_quote($email));
    if ($user) {
        db_exec('UPDATE users SET last_login = ' . db_quote(now_iso()) . ' WHERE id = ' . $user['id']);
        $user = db_row('SELECT * FROM users WHERE id = ' . $user['id']);
    } else {
        $orgId = ensure_default_org();
        $name = strtok($email, '@');
        $id = db_insert("INSERT INTO users (organization_id, email, name, google_sub, role, approved, created_at, last_login) VALUES ("
            . $orgId . ', ' . db_quote($email) . ', ' . db_quote($name)
            . ", 'email-" . md5($email) . "', 'artist', 1, '" . now_iso() . "', '" . now_iso() . "')");
        $user = db_row('SELECT * FROM users WHERE id = ' . $id);
    }

    set_auth_cookie($user['id']);
    return normalize_user($user);
}

function logout(): void
{
    clear_auth_cookie();
    header('Location: ' . APP_BASE);
    exit;
}

// ── Helpers ──

function ensure_default_org(): int
{
    $count = (int)db_scalar('SELECT COUNT(*) FROM organizations WHERE id = 1');
    if ($count === 0) {
        db_exec("INSERT INTO organizations (id, name, plan, created_at) VALUES (1, 'Internal', 'internal', '" . now_iso() . "')");
    }
    return 1;
}

function add_whitelisted_email(string $email, string $addedBy): void
{
    $email = strtolower(trim($email));
    if ($email === '') {
        throw new ApiError('Email required', 400);
    }
    $exists = db_row('SELECT id FROM whitelisted_emails WHERE LOWER(email) = ' . db_quote($email));
    if ($exists) {
        throw new ApiError('Email already whitelisted', 400);
    }
    db_exec("INSERT INTO whitelisted_emails (email, added_by, created_at) VALUES ("
        . db_quote($email) . ', ' . db_quote($addedBy) . ", '" . now_iso() . "')");
}

function remove_whitelisted_email(int $id): void
{
    db_exec('DELETE FROM whitelisted_emails WHERE id = ' . $id);
}
