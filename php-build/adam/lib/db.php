<?php
declare(strict_types=1);

/**
 * SQLite database connection + schema bootstrap.
 * Uses the native SQLite3 class (PDO may be disabled on shared hosting).
 */

function db(): SQLite3
{
    static $db = null;
    if ($db === null) {
        $file = DATA_DIR . '/choreo.db';
        $db = new SQLite3($file);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA foreign_keys = ON');
        bootstrap_db($db);
    }
    return $db;
}

function bootstrap_db(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS organizations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        plan TEXT DEFAULT "internal",
        created_at TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL,
        email TEXT NOT NULL,
        name TEXT DEFAULT "",
        google_sub TEXT UNIQUE NOT NULL,
        role TEXT DEFAULT "artist",
        approved INTEGER DEFAULT 0,
        created_at TEXT,
        last_login TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS blueprints (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT DEFAULT "",
        created_at TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS blueprint_states (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        blueprint_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        default_looping INTEGER DEFAULT 0,
        default_duration TEXT DEFAULT "",
        default_description TEXT DEFAULT ""
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT DEFAULT "",
        created_at TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS template_blueprints (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id INTEGER NOT NULL,
        blueprint_id INTEGER NOT NULL,
        sort_order INTEGER DEFAULT 0
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        template_id INTEGER,
        status TEXT DEFAULT "active",
        game_type TEXT DEFAULT "",
        customer TEXT DEFAULT "",
        deadline TEXT,
        summary TEXT,
        asset_link TEXT,
        created_at TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        element_name TEXT NOT NULL,
        animation_name TEXT DEFAULT "",
        looping INTEGER DEFAULT 0,
        duration TEXT DEFAULT "",
        description TEXT DEFAULT "",
        artist TEXT DEFAULT "",
        projected_hours REAL DEFAULT 0,
        actual_hours REAL DEFAULT 0,
        priority TEXT DEFAULT "Medium",
        phase TEXT,
        alert_flag INTEGER DEFAULT 0,
        alert_flag_reason TEXT,
        image_path TEXT DEFAULT "",
        status TEXT DEFAULT "Not Started",
        asset_link TEXT DEFAULT ""
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS entry_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entry_id INTEGER NOT NULL,
        image_path TEXT NOT NULL,
        sort_order INTEGER DEFAULT 0,
        uploaded_at TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        name TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        entry_id INTEGER,
        author_id INTEGER NOT NULL,
        body TEXT NOT NULL,
        linked_comment_id INTEGER,
        created_at TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS invite_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL,
        token TEXT UNIQUE NOT NULL,
        email_optional TEXT,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS whitelisted_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        added_by TEXT DEFAULT "",
        created_at TEXT
    )');

    // Seed organization
    $count = (int)db_scalar('SELECT COUNT(*) FROM organizations WHERE id = 1');
    if ($count === 0) {
        $now = now_iso();
        $db->exec("INSERT INTO organizations (id, name, plan, created_at) VALUES (1, 'Internal', 'internal', '$now')");
    }

    // Seed initial whitelisted admin email
    $wlCount = (int)db_scalar('SELECT COUNT(*) FROM whitelisted_emails');
    if ($wlCount === 0) {
        $now = now_iso();
        $db->exec("INSERT INTO whitelisted_emails (email, added_by, created_at) VALUES ('adam@siamkoala.com', 'system', '$now')");
    }

    seed_blueprints($db);
}

function seed_blueprints(SQLite3 $db): void
{
    $count = (int)db_scalar('SELECT COUNT(*) FROM blueprints');
    if ($count > 0) {
        return;
    }

    $now = now_iso();
    $seed = [
        ['Wild', 'Wild substitute symbol', [
            ['Idle', true, '5 sec', 'Looping idle animation'],
            ['Win', false, '3 sec', 'Win celebration animation'],
            ['SRS', false, '4 sec', 'Super Re-Spin trigger animation'],
        ]],
        ['Scatter', 'Scatter bonus trigger symbol', [
            ['Idle', true, '5 sec', 'Looping idle animation'],
            ['Win', false, '3 sec', 'Scatter win / bonus trigger'],
            ['SRS', false, '4 sec', 'Super Re-Spin scatter animation'],
        ]],
        ['Pot', 'Prize pot symbol (multi-skin, multi-level)', [
            ['Idle', true, '5 sec', 'Looping idle animation'],
            ['Hit', false, '2 sec', 'Pot hit / land animation'],
            ['Level up', false, '3 sec', 'Pot level up transition'],
            ['Trigger', false, '4 sec', 'Pot bonus trigger animation'],
        ]],
        ['Winbox', 'Win presentation box', [
            ['Idle', true, '5 sec', 'Looping idle animation'],
            ['Win', false, '4 sec', 'Win celebration / big win'],
        ]],
        ['Coin', 'Coin collect symbol', [
            ['Idle', true, '5 sec', 'Looping idle animation'],
            ['Win', false, '3 sec', 'Coin collect / win animation'],
        ]],
        ['Collect', 'Collect / aggregator symbol', [
            ['Idle', true, '5 sec', 'Looping idle animation'],
            ['Win', false, '3 sec', 'Collect animation'],
        ]],
    ];

    $ids = [];
    foreach ($seed as $item) {
        [$name, $desc, $states] = $item;
        $db->exec("INSERT INTO blueprints (organization_id, name, description, created_at) VALUES (1, "
            . db_quote($name) . ', ' . db_quote($desc) . ", '$now')");
        $bpId = (int)$db->lastInsertRowID();
        $ids[] = $bpId;
        foreach ($states as $s) {
            [$sName, $sLoop, $sDur, $sDesc] = $s;
            $db->exec('INSERT INTO blueprint_states (blueprint_id, name, default_looping, default_duration, default_description) VALUES ('
                . $bpId . ', ' . db_quote($sName) . ', ' . ($sLoop ? 1 : 0) . ', '
                . db_quote($sDur) . ', ' . db_quote($sDesc) . ')');
        }
    }

    $db->exec("INSERT INTO templates (organization_id, name, description, created_at) VALUES (1, "
        . db_quote('3-Pot Hold & Win') . ', ' . db_quote('Standard 3-pot bonus game with wild, scatter, and collect') . ", '$now')");
    $tplId = (int)$db->lastInsertRowID();
    foreach ($ids as $i => $bpId) {
        $db->exec("INSERT INTO template_blueprints (template_id, blueprint_id, sort_order) VALUES ($tplId, $bpId, $i)");
    }
}