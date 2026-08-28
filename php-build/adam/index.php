<?php
declare(strict_types=1);

// ── Bootstrap ──
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('UTC');

define('APP_ROOT', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('FRONTEND_DIR', APP_ROOT . '/frontend');

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/helpers.php';
require_once APP_ROOT . '/lib/auth.php';
require_once APP_ROOT . '/lib/handlers.php';
require_once APP_ROOT . '/lib/import_docx.php';
require_once APP_ROOT . '/lib/export.php';

// ── Base path detection ──
// The app can live at /adam, /some/path, or the domain root.
// Compute the URL base from the script's own location so it works anywhere.
$scriptUrl = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = rtrim(str_replace('\\', '/', dirname($scriptUrl)), '/');
define('APP_BASE', $basePath);

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$queryPos = strpos($requestUri, '?');
if ($queryPos !== false) {
    $requestUri = substr($requestUri, 0, $queryPos);
}
$requestPath = rtrim($requestUri, '/');

// Route: strip base path to get app-relative path
$rel = $requestPath;
if (APP_BASE !== '' && APP_BASE !== '/') {
    if ($requestPath === APP_BASE) {
        $rel = '';
    } elseif (strncmp($requestPath, APP_BASE . '/', strlen(APP_BASE) + 1) === 0) {
        $rel = substr($requestPath, strlen(APP_BASE) + 1);
    }
}
$rel = ltrim($rel, '/');

// ── Dispatch ──
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($rel === '' || $rel === 'index.php' || $rel === 'index.html') {
    serve_frontend();
}

if (strncmp($rel, 'api/', 4) === 0) {
    $apiPath = substr($rel, 4);
    handle_api($method, $apiPath);
}

// Static assets (app.js, style.css, etc.) inside frontend/ are served by
// Apache directly since they exist as real files — but if reached here, fall back.
if (preg_match('/^frontend\/.+\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf)$/', $rel)) {
    serve_file(FRONTEND_DIR . '/' . substr($rel, strlen('frontend/')));
}

http_response_code(404);
json_out(['detail' => 'Not found'], 404);

// ── Helpers ──
function serve_frontend(): void
{
    $file = FRONTEND_DIR . '/index.html';
    if (!is_file($file)) {
        http_response_code(500);
        exit('frontend/index.html missing');
    }
    $html = file_get_contents($file);
    $html = str_replace('{{BASE}}', APP_BASE, $html);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function serve_file(string $path): void
{
    if (!is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = [
        'css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    ];
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    readfile($path);
    exit;
}

function handle_api(string $method, string $apiPath): void
{
    $segments = $apiPath === '' ? [] : explode('/', $apiPath);

    try {
        if (empty($segments)) {
            throw new ApiError('Not found', 404);
        }

        switch ($segments[0]) {
            case 'health':
                json_out(['ok' => true]);
                break;

            case 'me':
                require_auth();
                json_out(current_user_array());
                break;

            case 'blueprints':
                handle_blueprints($method, $segments);
                break;

            case 'templates':
                handle_templates($method, $segments);
                break;

            case 'projects':
                handle_projects($method, $segments);
                break;

            case 'entries':
                handle_entries($method, $segments);
                break;

            case 'tags':
                handle_tags($method, $segments);
                break;

            case 'comments':
                handle_comments($method, $segments);
                break;

            case 'import-docx':
                handle_import_docx();
                break;

            default:
                throw new ApiError('Not found', 404);
        }
    } catch (ApiError $e) {
        json_out(['detail' => $e->getMessage()], $e->getCode());
    } catch (Throwable $e) {
        error_log('API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        json_out(['detail' => 'Internal server error'], 500);
    }
}