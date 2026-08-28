<?php
declare(strict_types=1);

/**
 * Dev-mode auth. Mirrors the FastAPI backend: when no Google OAuth is
 * configured, every request is treated as the dev admin user.
 */

function current_user_array(): array
{
    $user = db_row('SELECT * FROM users WHERE google_sub = ' . db_quote('dev-local'));
    if ($user) {
        return normalize_user($user);
    }

    $count = (int)db_scalar('SELECT COUNT(*) FROM organizations WHERE id = 1');
    if ($count === 0) {
        db_exec("INSERT INTO organizations (id, name, plan, created_at) VALUES (1, 'Internal', 'internal', '" . now_iso() . "')");
    }

    $id = db_insert("INSERT INTO users (organization_id, email, name, google_sub, role, approved, created_at) VALUES ("
        . "1, 'dev@localhost', 'Dev User', 'dev-local', 'admin', 1, '" . now_iso() . "')");

    return normalize_user(db_row('SELECT * FROM users WHERE id = ' . $id));
}

function require_auth(): array
{
    return current_user_array();
}

function require_admin(): array
{
    $user = current_user_array();
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