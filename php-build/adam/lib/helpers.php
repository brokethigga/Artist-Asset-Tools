<?php
declare(strict_types=1);

/**
 * Shared helpers: SQLite query wrappers, JSON output, date helpers, input parsing.
 */

class ApiError extends Exception
{
    public function __construct(string $message, int $code = 400)
    {
        parent::__construct($message, $code);
    }
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function db_quote(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function db_scalar(string $sql): mixed
{
    $db = db();
    $res = $db->query($sql);
    if ($res === false) {
        throw new ApiError('Query failed', 500);
    }
    $row = $res->fetchArray(SQLITE3_NUM);
    return $row ? $row[0] : null;
}

function db_rows(string $sql): array
{
    $db = db();
    $res = $db->query($sql);
    if ($res === false) {
        throw new ApiError('Query failed', 500);
    }
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function db_row(string $sql): ?array
{
    $rows = db_rows($sql);
    return $rows[0] ?? null;
}

function db_exec(string $sql): void
{
    $db = db();
    $db->exec($sql);
}

function db_insert(string $sql): int
{
    $db = db();
    $db->exec($sql);
    return (int)$db->lastInsertRowID();
}

function json_out(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ApiError('Invalid JSON body', 400);
    }
    return $data;
}

function get_str(array $data, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return $default;
    }
    return (string)$data[$key];
}

function get_opt_str(array $data, string $key): ?string
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return null;
    }
    return (string)$data[$key];
}

function get_bool(array $data, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return $default;
    }
    $v = $data[$key];
    if (is_bool($v)) {
        return $v;
    }
    return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}

function get_float(array $data, string $key, float $default = 0.0): float
{
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
        return $default;
    }
    return (float)$data[$key];
}

function get_int(array $data, string $key, int $default = 0): int
{
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
        return $default;
    }
    return (int)$data[$key];
}

function to_bool($v): bool
{
    return (bool)$v;
}

/**
 * Character count (UTF-8 aware). mbstring may be unavailable, so fall back
 * to iconv, then PCRE, then byte length.
 */
function char_len(string $s): int
{
    if ($s === '') {
        return 0;
    }
    if (function_exists('iconv_strlen')) {
        $n = @iconv_strlen($s, 'UTF-8');
        if ($n !== false) {
            return $n;
        }
    }
    if (preg_match_all('/./us', $s, $m)) {
        return count($m[0]);
    }
    return strlen($s);
}