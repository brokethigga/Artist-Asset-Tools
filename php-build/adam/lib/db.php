<?php
declare(strict_types=1);

/**
 * Database layer with two drivers:
 *   - sqlite (default)  : file-based, uses the native SQLite3 class.
 *   - mysql  (optional) : used when config/db.php exists with driver=mysql.
 * The query API (db_scalar/db_rows/db_exec/db_insert) is identical for both.
 */

function db_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = ['driver' => 'mysql'];
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $candidates = [];
        if ($docRoot !== '') {
            $candidates[] = dirname($docRoot) . '/admin/pass.php';
        }
        $candidates[] = __DIR__ . '/../../admin/pass.php';
        $candidates[] = __DIR__ . '/../../../../admin/pass.php';
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $loaded = require $file;
                if (is_array($loaded)) {
                    $cfg = array_merge($cfg, $loaded);
                }
                break;
            }
        }
    }
    return $cfg;
}

function db_driver(): string
{
    return (string)(db_config()['driver'] ?? 'mysql');
}

// ── MySQL adapter ──

function mysql_connect_candidates(array $cfg): array
{
    $name = (string)($cfg['name'] ?? '');
    $cands = [$name];
    $user = (string)($cfg['user'] ?? '');
    $u = strtolower(trim($user));
    $us = strpos($u, '_');
    if ($us !== false && $name !== '') {
        $prefixed = substr($u, 0, $us) . '_' . $name;
        if (!in_array($prefixed, $cands, true)) {
            $cands[] = $prefixed;
        }
    }
    return $cands;
}

function mysql_attempt_connect(array $cfg, array $candidates, array &$errors, ?string &$chosen): ?mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    foreach ($candidates as $dbName) {
        $c = @new mysqli(
            (string)($cfg['host'] ?? 'localhost'),
            (string)($cfg['user'] ?? ''),
            (string)($cfg['password'] ?? ''),
            $dbName === '' ? null : $dbName
        );
        if ($c->connect_errno) {
            $errors[$dbName === '' ? '(default)' : $dbName] = $c->connect_error;
            // 1049 = unknown database; try the next candidate name.
            if ($c->connect_errno === 1049) {
                continue;
            }
            return null;
        }
        $chosen = $dbName;
        return $c;
    }
    return null;
}

class MysqlResult
{
    private $res;

    public function __construct($res)
    {
        $this->res = $res;
    }

    public function fetchArray(int $mode): ?array
    {
        if (!$this->res instanceof mysqli_result) {
            return null;
        }
        if ($mode === SQLITE3_ASSOC) {
            return $this->res->fetch_assoc();
        }
        if ($mode === SQLITE3_NUM) {
            return $this->res->fetch_row();
        }
        return null;
    }
}

class MysqlConn
{
    private $mysqli;
    private $dbName = '';
    private $connectCandidates = [];
    private $connectErrors = [];

    public function __construct(array $cfg)
    {
        $password = (string)($cfg['password'] ?? '');
        if ($password === '') {
            throw new ApiError('Database password not configured (admin/pass.php)', 500);
        }
        $this->connectCandidates = mysql_connect_candidates($cfg);
        $this->mysqli = mysql_attempt_connect($cfg, $this->connectCandidates, $this->connectErrors, $this->dbName);
        if ($this->mysqli === null) {
            throw new ApiError('Database connection failed', 500);
        }
        $this->mysqli->set_charset('utf8mb4');
    }

    public function raw(): mysqli
    {
        return $this->mysqli;
    }

    public function dbName(): string
    {
        return $this->dbName;
    }

    public function connectCandidates(): array
    {
        return $this->connectCandidates;
    }

    public function connectErrors(): array
    {
        return $this->connectErrors;
    }

    public function query(string $sql)
    {
        $res = $this->mysqli->query($sql);
        if ($res === false) {
            throw new ApiError('Query failed: ' . $this->mysqli->error . ' | SQL: ' . substr($sql, 0, 200), 500);
        }
        return new MysqlResult($res);
    }

    public function exec(string $sql): void
    {
        $this->query($sql);
    }

    public function lastInsertRowID(): int
    {
        return (int)$this->mysqli->insert_id;
    }
}

// ── Connection ──

function db()
{
    static $db = null;
    if ($db === null) {
        if (db_driver() === 'mysql') {
            $db = new MysqlConn(db_config());
            bootstrap_db($db, 'mysql');
        } else {
            $file = DATA_DIR . '/choreo.db';
            $db = new SQLite3($file);
            $db->enableExceptions(true);
            $db->busyTimeout(5000);
            $db->exec('PRAGMA journal_mode = WAL');
            $db->exec('PRAGMA foreign_keys = ON');
            bootstrap_db($db, 'sqlite');
        }
    }
    return $db;
}

// ── Schema ──

/**
 * True when $table has a column named $column. Driver-aware: MySQL is checked
 * via information_schema, SQLite via PRAGMA table_info.
 */
function table_column_exists(string $table, string $column): bool
{
    if (db_driver() === 'mysql') {
        // Deliberately avoids db_quote(): this runs from the db-check
        // diagnostic, which has to work even when the main connection is the
        // thing that is broken. Values here are hardcoded identifiers.
        $t = str_replace("'", "''", $table);
        $c = str_replace("'", "''", $column);
        return (int)db_scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c'"
        ) > 0;
    }
    $rows = db_rows('PRAGMA table_info(' . $table . ')');
    foreach ($rows as $row) {
        if (isset($row['name']) && strcasecmp((string)$row['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * True when $table supports soft delete. Used to let the API report a clear
 * error instead of a raw SQL error when the archived migration was blocked.
 */
function db_have_archived(string $table): bool
{
    $ready = $GLOBALS['__archived_tables'] ?? null;
    if (!is_array($ready)) {
        return table_column_exists($table, 'archived');
    }
    return in_array($table, $ready, true);
}

function bootstrap_db($db, string $driver): void
{
    if ($driver === 'mysql') {
        mysql_schema($db);
    } else {
        sqlite_schema($db);
    }

    // Soft-delete (archived) columns.
    //
    // These tables are archived with `UPDATE ... SET archived = 1` instead of
    // `DELETE FROM ...` so the app never needs the DELETE grant (many shared
    // hosts do not grant it) and so data can be restored later.
    //
    // 'entries' MUST be in this list: entry rows are what a project "State /
    // Animation" is made of, and archiving them is the only way to remove one
    // without a hard DELETE.
    $archivedTables = ['blueprints', 'templates', 'projects', 'blueprint_states', 'entries'];

    // The migration below needs ALTER; a host without it must degrade to a
    // logged warning rather than 500 every single request.
    $softDeleteReady = [];
    foreach ($archivedTables as $t) {
        try {
            $needsColumn = !table_column_exists($t, 'archived');
            if ($needsColumn) {
                db_exec("ALTER TABLE `$t` ADD COLUMN archived TINYINT NOT NULL DEFAULT 0");
                error_log("[bootstrap] added archived column to `$t`");
            }
            $softDeleteReady[] = $t;
        } catch (Throwable $e) {
            error_log('[bootstrap] could not add `archived` to `' . $t . '`: ' . $e->getMessage());
        }
    }
    // Cache for db_have_archived() so request code can degrade gracefully.
    $GLOBALS['__archived_tables'] = $softDeleteReady;

    // Columns introduced later than the original schema. Same guard pattern as
    // `archived`: add when missing, log and carry on when a host blocks ALTER.
    $extraColumns = [];
    if ($driver === 'mysql') {
        $extraColumns = [
            'blueprints.priority' => "ALTER TABLE `blueprints` ADD COLUMN priority VARCHAR(50) NOT NULL DEFAULT 'Medium'",
            'blueprints.phase' => "ALTER TABLE `blueprints` ADD COLUMN phase VARCHAR(50) NOT NULL DEFAULT 'Art'",
            'entries.blueprint_id' => 'ALTER TABLE `entries` ADD COLUMN blueprint_id INT NULL',
            'entries.phase' => "ALTER TABLE `entries` ADD COLUMN phase VARCHAR(50) NULL",
            'entries.priority' => "ALTER TABLE `entries` ADD COLUMN priority VARCHAR(50) NOT NULL DEFAULT 'Medium'",
            'projects.exported_location' => "ALTER TABLE `projects` ADD COLUMN exported_location VARCHAR(500) NOT NULL DEFAULT ''",
            'projects.exported_format' => "ALTER TABLE `projects` ADD COLUMN exported_format VARCHAR(255) NOT NULL DEFAULT ''",
            'projects.source_location' => "ALTER TABLE `projects` ADD COLUMN source_location VARCHAR(500) NOT NULL DEFAULT ''",
            'projects.source_format' => "ALTER TABLE `projects` ADD COLUMN source_format VARCHAR(255) NOT NULL DEFAULT ''",
            'projects.skin_list' => 'ALTER TABLE `projects` ADD COLUMN skin_list TEXT NULL',
            'projects.resize_from_spine' => "ALTER TABLE `projects` ADD COLUMN resize_from_spine VARCHAR(50) NOT NULL DEFAULT ''",
            'projects.preview_link' => "ALTER TABLE `projects` ADD COLUMN preview_link VARCHAR(500) NOT NULL DEFAULT ''",
        ];
    } else {
        $extraColumns = [
            'blueprints.priority' => "ALTER TABLE blueprints ADD COLUMN priority TEXT DEFAULT 'Medium'",
            'blueprints.phase' => "ALTER TABLE blueprints ADD COLUMN phase TEXT DEFAULT 'Art'",
            'entries.blueprint_id' => 'ALTER TABLE entries ADD COLUMN blueprint_id INTEGER',
            'entries.phase' => 'ALTER TABLE entries ADD COLUMN phase TEXT',
            'entries.priority' => "ALTER TABLE entries ADD COLUMN priority TEXT DEFAULT 'Medium'",
            'projects.exported_location' => "ALTER TABLE projects ADD COLUMN exported_location TEXT DEFAULT ''",
            'projects.exported_format' => "ALTER TABLE projects ADD COLUMN exported_format TEXT DEFAULT ''",
            'projects.source_location' => "ALTER TABLE projects ADD COLUMN source_location TEXT DEFAULT ''",
            'projects.source_format' => "ALTER TABLE projects ADD COLUMN source_format TEXT DEFAULT ''",
            'projects.skin_list' => 'ALTER TABLE projects ADD COLUMN skin_list TEXT',
            'projects.resize_from_spine' => "ALTER TABLE projects ADD COLUMN resize_from_spine TEXT DEFAULT ''",
            'projects.preview_link' => "ALTER TABLE projects ADD COLUMN preview_link TEXT DEFAULT ''",
        ];
    }
    foreach ($extraColumns as $key => $sql) {
        [$table, $col] = explode('.', $key, 2);
        try {
            if (!table_column_exists($table, $col)) {
                db_exec($sql);
                error_log("[bootstrap] added `$col` to `$table`");
            }
        } catch (Throwable $e) {
            error_log('[bootstrap] could not add `' . $col . '` to `' . $table . '`: ' . $e->getMessage());
        }
    }

    // Entry type rename: legacy "Drawing" is now called "Art". Idempotent —
    // after the first run no rows still match "Drawing".
    try {
        db_exec("UPDATE entries SET phase = 'Art' WHERE phase = 'Drawing'");
    } catch (Throwable $e) {
        error_log('[bootstrap] phase rename Drawing->Art failed: ' . $e->getMessage());
    }

    // Seed organization
    $count = (int)db_scalar('SELECT COUNT(*) FROM organizations WHERE id = 1');
    if ($count === 0) {
        $now = now_iso();
        db_exec("INSERT INTO organizations (id, name, plan, created_at) VALUES (1, 'Internal', 'internal', '" . $now . "')");
    }

    // Seed initial whitelisted admin email
    $wlCount = (int)db_scalar('SELECT COUNT(*) FROM whitelisted_emails');
    if ($wlCount === 0) {
        $now = now_iso();
        db_exec("INSERT INTO whitelisted_emails (email, added_by, created_at) VALUES ('adam@siamkoala.com', 'system', '" . $now . "')");
    }

    seed_blueprints($db);

    // ── Element backfill ──
    //
    // Blueprints with zero animation states used to be copied into a project
    // as nothing, so the element silently vanished ("static symbols", "reel").
    // Project creation now writes a placeholder entry; this one-time repair
    // adds the placeholder to projects that were created before the fix. The
    // app_meta row records that the repair has run so it never repeats.
    if ($driver === 'mysql') {
        $db->exec('CREATE TABLE IF NOT EXISTS app_meta (
            k VARCHAR(64) PRIMARY KEY,
            v TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } else {
        $db->exec('CREATE TABLE IF NOT EXISTS app_meta (
            k TEXT PRIMARY KEY,
            v TEXT
        )');
    }
    try {
        $done = (int)db_scalar("SELECT COUNT(*) FROM app_meta WHERE k = 'backfill_elements_v1'");
        if ($done === 0 && table_column_exists('entries', 'blueprint_id')) {
            backfill_missing_elements();
            $now = now_iso();
            if ($driver === 'mysql') {
                db_exec("INSERT IGNORE INTO app_meta (k, v) VALUES ('backfill_elements_v1', '$now')");
            } else {
                db_exec("INSERT OR IGNORE INTO app_meta (k, v) VALUES ('backfill_elements_v1', '$now')");
            }
        }
    } catch (Throwable $e) {
        error_log('[bootstrap] element backfill failed: ' . $e->getMessage());
    }
}

function sqlite_schema(SQLite3 $db): void
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
        priority TEXT DEFAULT "Medium",
        phase TEXT DEFAULT "Art",
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
        exported_location TEXT DEFAULT "",
        exported_format TEXT DEFAULT "",
        source_location TEXT DEFAULT "",
        source_format TEXT DEFAULT "",
        skin_list TEXT,
        resize_from_spine TEXT DEFAULT "",
        preview_link TEXT DEFAULT "",
        created_at TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        blueprint_id INTEGER,
        element_name TEXT NOT NULL,
        animation_name TEXT DEFAULT "",
        archived INTEGER NOT NULL DEFAULT 0,
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

    $db->exec('CREATE TABLE IF NOT EXISTS sessions (
        id TEXT PRIMARY KEY,
        data TEXT DEFAULT "",
        expires_at TEXT
    )');
}

function mysql_schema($db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS organizations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL DEFAULT "Internal",
        plan VARCHAR(50) NOT NULL DEFAULT "internal",
        created_at VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NOT NULL DEFAULT 1,
        email VARCHAR(255) NOT NULL,
        name VARCHAR(255) NOT NULL DEFAULT "",
        google_sub VARCHAR(255) NOT NULL UNIQUE,
        role VARCHAR(50) NOT NULL DEFAULT "artist",
        approved TINYINT NOT NULL DEFAULT 0,
        created_at VARCHAR(32) NOT NULL,
        last_login VARCHAR(32) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS blueprints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NOT NULL DEFAULT 1,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        priority VARCHAR(50) NOT NULL DEFAULT "Medium",
        phase VARCHAR(50) NOT NULL DEFAULT "Art",
        created_at VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS blueprint_states (
        id INT AUTO_INCREMENT PRIMARY KEY,
        blueprint_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        default_looping TINYINT NOT NULL DEFAULT 0,
        default_duration VARCHAR(50) NOT NULL DEFAULT "",
        default_description TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NOT NULL DEFAULT 1,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        created_at VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS template_blueprints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_id INT NOT NULL,
        blueprint_id INT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NOT NULL DEFAULT 1,
        name VARCHAR(255) NOT NULL,
        template_id INT NULL,
        status VARCHAR(50) NOT NULL DEFAULT "active",
        game_type VARCHAR(255) NOT NULL DEFAULT "",
        customer VARCHAR(255) NOT NULL DEFAULT "",
        deadline VARCHAR(32) NULL,
        summary TEXT NULL,
        asset_link VARCHAR(500) NOT NULL DEFAULT "",
        exported_location VARCHAR(500) NOT NULL DEFAULT "",
        exported_format VARCHAR(255) NOT NULL DEFAULT "",
        source_location VARCHAR(500) NOT NULL DEFAULT "",
        source_format VARCHAR(255) NOT NULL DEFAULT "",
        skin_list TEXT NULL,
        resize_from_spine VARCHAR(50) NOT NULL DEFAULT "",
        preview_link VARCHAR(500) NOT NULL DEFAULT "",
        created_at VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        blueprint_id INT NULL,
        element_name VARCHAR(255) NOT NULL,
        animation_name VARCHAR(255) NOT NULL DEFAULT "",
        archived TINYINT NOT NULL DEFAULT 0,
        looping TINYINT NOT NULL DEFAULT 0,
        duration VARCHAR(50) NOT NULL DEFAULT "",
        description TEXT NOT NULL,
        artist VARCHAR(255) NOT NULL DEFAULT "",
        projected_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
        actual_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
        priority VARCHAR(50) NOT NULL DEFAULT "Medium",
        phase VARCHAR(50) NULL,
        alert_flag TINYINT NOT NULL DEFAULT 0,
        alert_flag_reason VARCHAR(500) NULL,
        image_path VARCHAR(500) NOT NULL DEFAULT "",
        status VARCHAR(50) NOT NULL DEFAULT "Not Started",
        asset_link VARCHAR(500) NOT NULL DEFAULT ""
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS entry_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id INT NOT NULL,
        image_path VARCHAR(500) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        uploaded_at VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        name VARCHAR(500) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        entry_id INT NULL,
        author_id INT NOT NULL,
        body TEXT NOT NULL,
        linked_comment_id INT NULL,
        created_at VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS invite_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NOT NULL DEFAULT 1,
        token VARCHAR(64) NOT NULL UNIQUE,
        email_optional VARCHAR(255) NULL,
        expires_at VARCHAR(32) NOT NULL,
        used_at VARCHAR(32) NULL,
        created_at VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS whitelisted_emails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        added_by VARCHAR(255) NOT NULL DEFAULT "",
        created_at VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS sessions (
        id VARCHAR(64) PRIMARY KEY,
        data TEXT NULL,
        expires_at VARCHAR(32) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function seed_blueprints($db): void
{
    $count = (int)db_scalar('SELECT COUNT(*) FROM blueprints WHERE organization_id = 1');
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
            . db_quote($name) . ', ' . db_quote($desc) . ", '" . $now . "')");
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
        . db_quote('3-Pot Hold & Win') . ', ' . db_quote('Standard 3-pot bonus game with wild, scatter, and collect') . ", '" . $now . "')");
    $tplId = (int)$db->lastInsertRowID();
    foreach ($ids as $i => $bpId) {
        $db->exec("INSERT INTO template_blueprints (template_id, blueprint_id, sort_order) VALUES ($tplId, $bpId, $i)");
    }
}

// ── Diagnostics & migration ──

/**
 * One-time repair for projects created before the "no animation states" fix.
 * For every project created from a template it:
 *  - stamps entries.blueprint_id onto existing rows whose element matches a
 *    template blueprint by name, and
 *  - inserts a placeholder entry (empty animation_name) for each template
 *    blueprint that has no entry rows at all, so the element is not lost.
 * It never runs twice: bootstrap_db records a marker in app_meta once done.
 */
function backfill_missing_elements(): void
{
    $projects = db_rows("SELECT id, template_id FROM projects WHERE template_id IS NOT NULL AND archived = 0");
    foreach ($projects as $p) {
        $pid = (int)$p['id'];
        $bpRows = db_rows(
            'SELECT b.id, b.name, b.description, b.phase FROM template_blueprints tb'
            . ' JOIN blueprints b ON b.id = tb.blueprint_id'
            . ' WHERE tb.template_id = ' . (int)$p['template_id'] . ' AND b.archived = 0'
            . ' ORDER BY tb.sort_order'
        );
        foreach ($bpRows as $bp) {
            $elem = (string)$bp['name'];
            $stamped = (int)db_scalar(
                'SELECT COUNT(*) FROM entries WHERE project_id = ' . $pid
                . ' AND element_name = ' . db_quote($elem) . ' AND blueprint_id IS NULL'
            );
            if ($stamped > 0) {
                db_exec(
                    'UPDATE entries SET blueprint_id = ' . (int)$bp['id']
                    . ' WHERE project_id = ' . $pid . ' AND element_name = ' . db_quote($elem)
                    . ' AND blueprint_id IS NULL'
                );
            }
            $missing = (int)db_scalar(
                'SELECT COUNT(*) FROM entries WHERE project_id = ' . $pid
                . ' AND element_name = ' . db_quote($elem) . ' AND archived = 0'
            );
            if ($missing === 0) {
                db_exec(
                    'INSERT INTO entries (project_id, element_name, animation_name, blueprint_id, looping, duration, description, projected_hours, actual_hours, phase) VALUES ('
                    . $pid . ', ' . db_quote($elem) . ", '', " . (int)$bp['id'] . ', 0, ' . db_quote('')
                    . ', ' . db_quote((string)($bp['description'] ?? '')) . ', 0, 0, '
                    . db_quote((string)($bp['phase'] ?? 'Art')) . ')'
                );
            }
        }
    }
}

function db_connection_report(): array
{
    if (db_driver() !== 'mysql') {
        return ['driver' => 'sqlite', 'note' => 'Using SQLite; add admin/pass.php with driver=mysql to switch.'];
    }
    $cfg = db_config();
    $candidates = mysql_connect_candidates($cfg);
    $errors = [];
    $chosen = null;
    $conn = mysql_attempt_connect($cfg, $candidates, $errors, $chosen);
    $report = [
        'driver' => 'mysql',
        'host' => (string)($cfg['host'] ?? 'localhost'),
        'user' => (string)($cfg['user'] ?? ''),
        'configured_db' => (string)($cfg['name'] ?? ''),
        'connected_db' => $chosen ?? null,
        'version' => $conn ? $conn->server_info : null,
        'tested_candidates' => $candidates,
        'errors' => $errors,
        'ok' => $conn !== null,
    ];
    if ($conn !== null) {
        $report['grants'] = mysql_grant_summary($conn);
    }
    return $report;
}

/**
 * Summarise what the configured MySQL user is actually allowed to do.
 *
 * The app archives (soft-deletes) instead of deleting, so the grants that
 * matter day to day are SELECT / INSERT / UPDATE. DELETE and ALTER are
 * reported too: DELETE is not required, ALTER is only needed once so the
 * `archived` columns can be added.
 */
function mysql_grant_summary(mysqli $conn): array
{
    $out = [
        'raw' => [],
        'has_select' => false,
        'has_insert' => false,
        'has_update' => false,
        'has_delete' => false,
        'has_alter' => false,
        'archived_columns' => [],
        'missing_archived_columns' => [],
    ];

    $res = $conn->query('SHOW GRANTS');
    if ($res) {
        while ($row = $res->fetch_row()) {
            $out['raw'][] = (string)$row[0];
        }
    }
    $grants = strtoupper(implode(' ', $out['raw']));
    $out['has_select'] = strpos($grants, 'SELECT') !== false;
    $out['has_insert'] = strpos($grants, 'INSERT') !== false;
    $out['has_update'] = strpos($grants, 'UPDATE') !== false;
    $out['has_delete'] = strpos($grants, 'DELETE') !== false;
    $out['has_alter'] = strpos($grants, 'ALTER') !== false;

    // The app needs the soft-delete column on these tables to archive anything.
    // Probed defensively: a failure here must not blank out the whole report.
    $probeError = null;
    foreach (['blueprints', 'templates', 'projects', 'blueprint_states', 'entries'] as $t) {
        try {
            if (table_column_exists($t, 'archived')) {
                $out['archived_columns'][] = $t;
            } else {
                $out['missing_archived_columns'][] = $t;
            }
        } catch (Throwable $e) {
            $probeError = $e->getMessage();
            $out['missing_archived_columns'][] = $t;
        }
    }
    if ($probeError !== null) {
        $out['column_probe_error'] = $probeError;
    }
    $out['soft_delete_ok'] = count($out['missing_archived_columns']) === 0;
    return $out;
}

function migrate_sqlite_to_mysql(): array
{
    if (db_driver() !== 'mysql') {
        throw new ApiError('Not in MySQL mode (admin/pass.php missing or not mysql)', 400);
    }
    $srcFile = DATA_DIR . '/choreo.db';
    if (!is_file($srcFile)) {
        throw new ApiError('No SQLite file found to migrate (' . $srcFile . '). Nothing to copy.', 400);
    }

    $src = new SQLite3($srcFile);
    $tables = [];
    $res = $src->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    while ($row = $res->fetchArray(SQLITE3_NUM)) {
        $tables[] = $row[0];
    }
    if (!$tables) {
        $src->close();
        throw new ApiError('SQLite source has no tables', 500);
    }

    $db = db();
    $mysqli = $db->raw();
    $run = function (string $sql) use ($mysqli): void {
        if (!$mysqli->query($sql)) {
            throw new ApiError('DB error during migration: ' . $mysqli->error, 500);
        }
    };
    $run('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) {
        $run('TRUNCATE TABLE `' . $t . '`');
    }
    $counts = [];
    foreach ($tables as $t) {
        $res = $src->query('SELECT * FROM "' . $t . '"');
        $cols = [];
        $counts[$t] = 0;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            if (!$cols) {
                $cols = array_keys($row);
            }
            $vals = [];
            foreach ($row as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = (string)$v;
                } else {
                    $vals[] = db_quote((string)$v);
                }
            }
            $run('INSERT INTO `' . $t . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $vals) . ')');
            $counts[$t]++;
        }
    }
    $run('SET FOREIGN_KEY_CHECKS=1');
    $src->close();

    $moved = $srcFile . '.migrated';
    if (is_file($moved)) {
        @unlink($moved);
    }
    if (!@rename($srcFile, $moved)) {
        throw new ApiError('Data copied but could not rename the SQLite file. Delete data/choreo.db manually once confirmed.', 500);
    }

    return ['status' => 'ok', 'copied' => $counts];
}