<?php
declare(strict_types=1);

/**
 * CRUD handlers for blueprints, templates, projects, entries, tags, comments.
 * Mirrors the FastAPI backend route logic 1:1.
 */

// ══════════════ BLUEPRINTS ══════════════

function handle_blueprints(string $method, array $seg): void
{
    $user = require_auth();
    $orgId = $user['organization_id'];

    if (count($seg) === 1) {
        if ($method === 'GET') {
            $showArchived = (int)($_GET['archived'] ?? 0);
            $rows = db_rows("SELECT * FROM blueprints WHERE organization_id = $orgId AND archived = $showArchived ORDER BY name");
            $out = [];
            foreach ($rows as $r) {
                $out[] = blueprint_out($r);
            }
            json_out($out);
        }
        if ($method === 'POST') {
            $data = json_body();
            $name = get_str($data, 'name');
            if ($name === '') {
                throw new ApiError('Name is required');
            }
            // Only active rows block a reuse of the name; an archived row with
            // the same name is restorable rather than a conflict.
            $existing = db_row("SELECT id FROM blueprints WHERE name = " . db_quote($name) . " AND organization_id = $orgId AND archived = 0");
            if ($existing) {
                throw new ApiError('Blueprint name already exists', 400);
            }
            $desc = get_str($data, 'description');
            $priority = safe_priority(get_str($data, 'priority', 'Medium'));
            $id = db_insert("INSERT INTO blueprints (organization_id, name, description, priority, created_at) VALUES "
                . "($orgId, " . db_quote($name) . ", " . db_quote($desc) . ", " . db_quote($priority) . ", '" . now_iso() . "')");
            foreach ((array)($data['states'] ?? []) as $s) {
                insert_state($id, $s);
            }
            json_out(blueprint_out(db_row('SELECT * FROM blueprints WHERE id = ' . $id)), 201);
        }
        throw new ApiError('Method not allowed', 405);
    }

    if (count($seg) === 2) {
        $id = (int)$seg[1];
        $bp = db_row("SELECT * FROM blueprints WHERE id = $id AND organization_id = $orgId");
        if (!$bp) {
            throw new ApiError('Not found', 404);
        }
        if ($method === 'GET') {
            json_out(blueprint_out($bp));
        }
        if ($method === 'PUT') {
            $data = json_body();
            $sets = [];
            if (isset($data['archived'])) {
                $sets[] = "archived = " . (int)$data['archived'];
            }
            $name = get_str($data, 'name');
            $desc = get_str($data, 'description');
            if ($name !== '') {
                $sets[] = "name = " . db_quote($name);
            }
            $sets[] = "description = " . db_quote($desc);
            $priority = get_str($data, 'priority');
            if ($priority !== '') {
                $sets[] = "priority = " . db_quote(safe_priority($priority));
            }
            if ($sets) {
                db_exec("UPDATE blueprints SET " . implode(', ', $sets) . " WHERE id = $id");
            }
            if (isset($data['states'])) {
                db_exec("UPDATE blueprint_states SET archived = 1 WHERE blueprint_id = $id");
                foreach ((array)($data['states'] ?? []) as $s) {
                    insert_state($id, $s);
                }
            }
            json_out(blueprint_out(db_row('SELECT * FROM blueprints WHERE id = ' . $id)));
        }
        if ($method === 'DELETE') {
            db_exec("UPDATE blueprints SET archived = 1 WHERE id = $id");
            json_out(['ok' => true]);
        }
        throw new ApiError('Method not allowed', 405);
    }

    throw new ApiError('Not found', 404);
}

function insert_state(int $bpId, array $s): void
{
    $name = get_str($s, 'name');
    $loop = get_bool($s, 'default_looping', false);
    $dur = get_str($s, 'default_duration');
    $desc = get_str($s, 'default_description');
    db_exec("INSERT INTO blueprint_states (blueprint_id, name, default_looping, default_duration, default_description) VALUES "
        . "($bpId, " . db_quote($name) . ", " . ($loop ? 1 : 0) . ", " . db_quote($dur) . ", " . db_quote($desc) . ")");
}

function blueprint_out(array $r): array
{
    $states = db_rows('SELECT * FROM blueprint_states WHERE blueprint_id = ' . (int)$r['id'] . ' AND archived = 0 ORDER BY id');
    return [
        'id' => (int)$r['id'],
        'organization_id' => (int)$r['organization_id'],
        'name' => (string)$r['name'],
        'description' => (string)($r['description'] ?? ''),
        'priority' => (string)($r['priority'] ?? 'Medium'),
        'archived' => to_bool($r['archived'] ?? 0),
        'created_at' => (string)$r['created_at'],
        'states' => array_map(function ($s) {
            return [
                'id' => (int)$s['id'],
                'name' => (string)$s['name'],
                'default_looping' => to_bool($s['default_looping']),
                'default_duration' => (string)$s['default_duration'],
                'default_description' => (string)$s['default_description'],
            ];
        }, $states),
    ];
}

// ══════════════ TEMPLATES ══════════════

function handle_templates(string $method, array $seg): void
{
    $user = require_auth();
    $orgId = $user['organization_id'];

    if (count($seg) === 1) {
        if ($method === 'GET') {
            $showArchived = (int)($_GET['archived'] ?? 0);
            $rows = db_rows("SELECT * FROM templates WHERE organization_id = $orgId AND archived = $showArchived ORDER BY name");
            $out = [];
            foreach ($rows as $r) {
                $out[] = template_out($r);
            }
            json_out($out);
        }
        if ($method === 'POST') {
            $data = json_body();
            $name = get_str($data, 'name');
            if ($name === '') {
                throw new ApiError('Name is required');
            }
            $desc = get_str($data, 'description');
            $id = db_insert("INSERT INTO templates (organization_id, name, description, created_at) VALUES "
                . "($orgId, " . db_quote($name) . ", " . db_quote($desc) . ", '" . now_iso() . "')");
            foreach ((array)($data['blueprint_ids'] ?? []) as $i => $bpId) {
                db_exec("INSERT INTO template_blueprints (template_id, blueprint_id, sort_order) VALUES ($id, " . (int)$bpId . ", " . $i . ")");
            }
            json_out(template_out(db_row('SELECT * FROM templates WHERE id = ' . $id)), 201);
        }
        throw new ApiError('Method not allowed', 405);
    }

    if (count($seg) === 2) {
        $id = (int)$seg[1];
        $tpl = db_row("SELECT * FROM templates WHERE id = $id AND organization_id = $orgId");
        if (!$tpl) {
            throw new ApiError('Not found', 404);
        }
        if ($method === 'GET') {
            json_out(template_out($tpl));
        }
        if ($method === 'PUT') {
            $data = json_body();
            $sets = [];
            if (isset($data['archived'])) {
                $sets[] = "archived = " . (int)$data['archived'];
            }
            $name = get_str($data, 'name');
            $desc = get_str($data, 'description');
            if ($name !== '') {
                $sets[] = "name = " . db_quote($name);
            }
            $sets[] = "description = " . db_quote($desc);
            if ($sets) {
                db_exec("UPDATE templates SET " . implode(', ', $sets) . " WHERE id = $id");
            }
            if (isset($data['blueprint_ids'])) {
                db_exec("DELETE FROM template_blueprints WHERE template_id = $id");
                foreach ((array)($data['blueprint_ids'] ?? []) as $i => $bpId) {
                    db_exec("INSERT INTO template_blueprints (template_id, blueprint_id, sort_order) VALUES ($id, " . (int)$bpId . ", " . $i . ")");
                }
            }
            json_out(template_out(db_row('SELECT * FROM templates WHERE id = ' . $id)));
        }
        if ($method === 'DELETE') {
            db_exec("UPDATE templates SET archived = 1 WHERE id = $id");
            json_out(['ok' => true]);
        }
        throw new ApiError('Method not allowed', 405);
    }

    throw new ApiError('Not found', 404);
}

function template_out(array $r): array
{
    $links = db_rows('SELECT * FROM template_blueprints WHERE template_id = ' . (int)$r['id'] . ' ORDER BY sort_order');
    $blueprints = [];
    foreach ($links as $l) {
        $bp = db_row('SELECT * FROM blueprints WHERE id = ' . (int)$l['blueprint_id'] . ' AND archived = 0');
        if (!$bp) {
            continue;
        }
        $blueprints[] = [
            'id' => (int)$l['id'],
            'blueprint_id' => (int)$l['blueprint_id'],
            'sort_order' => (int)$l['sort_order'],
            'blueprint' => blueprint_out($bp),
        ];
    }
    return [
        'id' => (int)$r['id'],
        'organization_id' => (int)$r['organization_id'],
        'name' => (string)$r['name'],
        'description' => (string)($r['description'] ?? ''),
        'archived' => to_bool($r['archived'] ?? 0),
        'created_at' => (string)$r['created_at'],
        'blueprints' => $blueprints,
    ];
}

// ══════════════ PROJECTS ══════════════

/**
 * Ordinal rank for the high → low element ordering. High sorts before Medium,
 * Medium before Low; anything unexpected is treated as Medium.
 */
function element_priority_rank(string $p): int
{
    if ($p === 'High') {
        return 0;
    }
    if ($p === 'Low') {
        return 2;
    }
    return 1;
}

function handle_projects(string $method, array $seg): void
{
    $user = require_auth();
    $orgId = $user['organization_id'];

    // /projects
    if (count($seg) === 1) {
        if ($method === 'GET') {
            $showArchived = (int)($_GET['archived'] ?? 0);
            $rows = db_rows("SELECT * FROM projects WHERE organization_id = $orgId AND archived = $showArchived ORDER BY created_at DESC");
            $out = [];
            foreach ($rows as $r) {
                $out[] = project_out($r);
            }
            json_out($out);
        }
        if ($method === 'POST') {
            $data = json_body();
            $name = get_str($data, 'name');
            if ($name === '') {
                throw new ApiError('Name is required');
            }
            $templateId = $data['template_id'] ?? null;
            $templateId = $templateId === null || $templateId === '' ? null : (int)$templateId;
            $deadline = get_opt_str($data, 'deadline');
            $gameType = get_str($data, 'game_type');
            $customer = get_str($data, 'customer');
            $assetLink = get_opt_str($data, 'asset_link');

            $pid = db_insert("INSERT INTO projects (organization_id, name, template_id, status, game_type, customer, deadline, asset_link, created_at) VALUES "
                . "($orgId, " . db_quote($name) . ", " . ($templateId ?: 'NULL') . ", 'active', "
                . db_quote($gameType) . ", " . db_quote($customer) . ", "
                . ($deadline !== null ? db_quote($deadline) : 'NULL') . ", "
                . db_quote($assetLink ?? '') . ", '" . now_iso() . "')");

            if ($templateId) {
                $tpl = db_row("SELECT * FROM templates WHERE id = $templateId AND organization_id = $orgId");
                if ($tpl) {
                    $links = db_rows('SELECT * FROM template_blueprints WHERE template_id = ' . $templateId . ' ORDER BY sort_order');
                    foreach ($links as $l) {
                        // Archived blueprints are excluded from templates in the
                        // UI, so they must not leak back into new projects either.
                        $bp = db_row('SELECT * FROM blueprints WHERE id = ' . (int)$l['blueprint_id'] . ' AND archived = 0');
                        if (!$bp) {
                            continue;
                        }
                        $states = db_rows('SELECT * FROM blueprint_states WHERE blueprint_id = ' . (int)$bp['id'] . ' AND archived = 0 ORDER BY id');
                        // A blueprint with no animation states still needs an
                        // element row, or the element ("static symbols", "reel")
                        // silently vanishes from the project.
                        if (count($states) === 0) {
                            db_exec("INSERT INTO entries (project_id, element_name, animation_name, blueprint_id, looping, duration, description, projected_hours, actual_hours) VALUES ("
                                . $pid . ", " . db_quote((string)$bp['name']) . ", '', " . (int)$bp['id'] . ", 0, '', "
                                . db_quote((string)($bp['description'] ?? '')) . ", 0, 0)");
                            continue;
                        }
                        foreach ($states as $s) {
                            db_exec("INSERT INTO entries (project_id, element_name, animation_name, blueprint_id, looping, duration, description, projected_hours, actual_hours) VALUES ("
                                . $pid . ", " . db_quote((string)$bp['name']) . ", " . db_quote((string)$s['name']) . ", " . (int)$bp['id'] . ", "
                                . ((int)$s['default_looping'] ? 1 : 0) . ", " . db_quote((string)$s['default_duration']) . ", "
                                . db_quote((string)$s['default_description']) . ", 0, 0)");
                        }
                    }
                }
            }

            json_out(project_out(db_row('SELECT * FROM projects WHERE id = ' . $pid)), 201);
        }
        throw new ApiError('Method not allowed', 405);
    }

    $pid = (int)$seg[1];
    $project = db_row("SELECT * FROM projects WHERE id = $pid AND organization_id = $orgId");
    if (!$project) {
        throw new ApiError('Not found', 404);
    }

    // /projects/{id}
    if (count($seg) === 2) {
        if ($method === 'GET') {
            json_out(project_out($project));
        }
        if ($method === 'PUT') {
            $data = json_body();
            $allowed = ['name', 'status', 'game_type', 'customer', 'deadline', 'summary', 'asset_link', 'exported_location', 'exported_format', 'source_location', 'source_format', 'skin_list', 'resize_from_spine', 'preview_link'];
            // Columns declared NOT NULL in the schema. Writing NULL to these
            // raises a MySQL error, which is what made inline edits (such as
            // clearing the project name) fail to save.
            $notNull = ['name', 'status', 'game_type', 'customer', 'asset_link', 'exported_location', 'exported_format', 'source_location', 'source_format', 'resize_from_spine', 'preview_link'];
            $sets = [];
            foreach ($allowed as $field) {
                if (!array_key_exists($field, $data)) {
                    continue;
                }
                $v = $data[$field];
                if ($v === null) {
                    if (in_array($field, $notNull, true)) {
                        $v = '';
                    } else {
                        $sets[] = "$field = NULL";
                        continue;
                    }
                }
                $sets[] = "$field = " . db_quote((string)$v);
            }
            // archived is a TINYINT flag, not a string.
            if (array_key_exists('archived', $data)) {
                $sets[] = 'archived = ' . (int)(bool)$data['archived'];
            }
            if ($sets) {
                db_exec('UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = ' . $pid);
            }
            json_out(project_out(db_row('SELECT * FROM projects WHERE id = ' . $pid)));
        }
        if ($method === 'DELETE') {
            db_exec("UPDATE projects SET archived = 1 WHERE id = $pid");
            json_out(['ok' => true]);
        }
        throw new ApiError('Method not allowed', 405);
    }

    $sub = $seg[2];

    // /projects/{id}/entries
    if ($sub === 'entries' && count($seg) === 3 && $method === 'GET') {
        $archivedFilter = db_have_archived('entries') ? ' AND archived = 0' : '';
        $rows = db_rows("SELECT * FROM entries WHERE project_id = $pid$archivedFilter");
        // Element sections are ordered by their blueprint's priority
        // (High → Medium → Low), then by element name, then by id. Entries
        // without a blueprint sort as Medium, so manual entries mix in evenly.
        $prioById = [];
        foreach (db_rows('SELECT id, priority FROM blueprints WHERE organization_id = ' . $orgId) as $b) {
            $prioById[(int)$b['id']] = (string)($b['priority'] ?? 'Medium');
        }
        usort($rows, function (array $a, array $b) use ($prioById): int {
            $ra = element_priority_rank($prioById[(int)($a['blueprint_id'] ?? 0)] ?? 'Medium');
            $rb = element_priority_rank($prioById[(int)($b['blueprint_id'] ?? 0)] ?? 'Medium');
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $cmp = strcmp((string)$a['element_name'], (string)$b['element_name']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return (int)$a['id'] <=> (int)$b['id'];
        });
        $out = [];
        foreach ($rows as $e) {
            $out[] = entry_out($e);
        }
        json_out($out);
    }

    // /projects/{id}/rollup
    if ($sub === 'rollup' && count($seg) === 3 && $method === 'GET') {
        json_out(project_rollup($pid));
    }

    // /projects/{id}/tags
    if ($sub === 'tags' && count($seg) === 3) {
        if ($method === 'GET') {
            $rows = db_rows("SELECT * FROM tags WHERE project_id = $pid ORDER BY name");
            $out = [];
            foreach ($rows as $t) {
                $out[] = tag_out($t);
            }
            json_out($out);
        }
        if ($method === 'POST') {
            $data = json_body();
            $name = get_str($data, 'name');
            if ($name === '') {
                throw new ApiError('Name is required');
            }
            $existing = db_row("SELECT id FROM tags WHERE project_id = $pid AND name = " . db_quote($name));
            if ($existing) {
                throw new ApiError('Tag already exists', 400);
            }
            $tid = db_insert("INSERT INTO tags (project_id, name) VALUES ($pid, " . db_quote($name) . ")");
            json_out(tag_out(db_row('SELECT * FROM tags WHERE id = ' . $tid)), 201);
        }
        throw new ApiError('Method not allowed', 405);
    }

    // /projects/{id}/comments
    if ($sub === 'comments' && count($seg) === 3) {
        if ($method === 'GET') {
            $rows = db_rows("SELECT * FROM comments WHERE project_id = $pid ORDER BY created_at");
            $out = [];
            foreach ($rows as $c) {
                $out[] = comment_out($c);
            }
            json_out($out);
        }
        if ($method === 'POST') {
            $data = json_body();
            $body = get_str($data, 'body');
            if ($body === '') {
                throw new ApiError('Body is required');
            }
            $entryId = $data['entry_id'] ?? null;
            $entryId = $entryId === null ? 'NULL' : (int)$entryId;
            $linkedId = $data['linked_comment_id'] ?? null;
            $linkedId = $linkedId === null ? 'NULL' : (int)$linkedId;
            $cid = db_insert("INSERT INTO comments (project_id, entry_id, author_id, body, linked_comment_id, created_at) VALUES "
                . "($pid, $entryId, " . (int)$user['id'] . ", " . db_quote($body) . ", $linkedId, '" . now_iso() . "')");
            json_out(comment_out(db_row('SELECT * FROM comments WHERE id = ' . $cid)), 201);
        }
        throw new ApiError('Method not allowed', 405);
    }

    // /projects/{id}/export
    if ($sub === 'export' && count($seg) === 3 && $method === 'GET') {
        $format = $_GET['format'] ?? 'xlsx';
        handle_export((int)$pid, (string)$format);
    }

    throw new ApiError('Not found', 404);
}

function project_out(array $r): array
{
    return [
        'id' => (int)$r['id'],
        'organization_id' => (int)$r['organization_id'],
        'name' => (string)$r['name'],
        'template_id' => $r['template_id'] !== null ? (int)$r['template_id'] : null,
        'status' => (string)$r['status'],
        'game_type' => (string)($r['game_type'] ?? ''),
        'customer' => (string)($r['customer'] ?? ''),
        'deadline' => $r['deadline'] !== null && $r['deadline'] !== '' ? (string)$r['deadline'] : null,
        'summary' => $r['summary'] !== null ? (string)$r['summary'] : null,
        'asset_link' => $r['asset_link'] !== null ? (string)$r['asset_link'] : null,
        'exported_location' => (string)($r['exported_location'] ?? ''),
        'exported_format' => (string)($r['exported_format'] ?? ''),
        'source_location' => (string)($r['source_location'] ?? ''),
        'source_format' => (string)($r['source_format'] ?? ''),
        'skin_list' => $r['skin_list'] !== null ? (string)$r['skin_list'] : null,
        'resize_from_spine' => (string)($r['resize_from_spine'] ?? ''),
        'preview_link' => (string)($r['preview_link'] ?? ''),
        'archived' => to_bool($r['archived'] ?? 0),
        'created_at' => (string)$r['created_at'],
    ];
}

function project_rollup(int $pid): array
{
    $archivedFilter = db_have_archived('entries') ? ' AND archived = 0' : '';
    $entries = db_rows("SELECT * FROM entries WHERE project_id = $pid$archivedFilter");
    $totalProjected = 0.0;
    $totalActual = 0.0;
    $byElement = [];
    $byArtist = [];
    $flagged = [];

    foreach ($entries as $e) {
        $ph = (float)($e['projected_hours'] ?? 0);
        $ah = (float)($e['actual_hours'] ?? 0);
        $totalProjected += $ph;
        $totalActual += $ah;

        $el = (string)$e['element_name'];
        if (!isset($byElement[$el])) {
            $byElement[$el] = ['element' => $el, 'projected' => 0.0, 'actual' => 0.0, 'count' => 0];
        }
        $byElement[$el]['projected'] += $ph;
        $byElement[$el]['actual'] += $ah;
        $byElement[$el]['count'] += 1;

        $artist = (string)($e['artist'] ?? '');
        if ($artist !== '') {
            if (!isset($byArtist[$artist])) {
                $byArtist[$artist] = ['artist' => $artist, 'projected' => 0.0, 'actual' => 0.0, 'count' => 0];
            }
            $byArtist[$artist]['projected'] += $ph;
            $byArtist[$artist]['actual'] += $ah;
            $byArtist[$artist]['count'] += 1;
        }

        if ((int)$e['alert_flag']) {
            $flagged[] = (int)$e['id'];
        }
    }

    usort($byElement, fn($a, $b) => $b['actual'] <=> $a['actual']);
    usort($byArtist, fn($a, $b) => $b['actual'] <=> $a['actual']);

    return [
        'total_projected' => $totalProjected,
        'total_actual' => $totalActual,
        'total_entries' => count($entries),
        'flagged_count' => count($flagged),
        'by_element' => array_values($byElement),
        'by_artist' => array_values($byArtist),
    ];
}

function tag_out(array $t): array
{
    return [
        'id' => (int)$t['id'],
        'project_id' => (int)$t['project_id'],
        'name' => (string)$t['name'],
    ];
}

function comment_out(array $c): array
{
    $author = db_row('SELECT * FROM users WHERE id = ' . (int)$c['author_id']);
    return [
        'id' => (int)$c['id'],
        'project_id' => (int)$c['project_id'],
        'entry_id' => $c['entry_id'] !== null ? (int)$c['entry_id'] : null,
        'author_id' => (int)$c['author_id'],
        'body' => (string)$c['body'],
        'linked_comment_id' => $c['linked_comment_id'] !== null ? (int)$c['linked_comment_id'] : null,
        'created_at' => (string)$c['created_at'],
        'author' => $author ? normalize_user($author) : null,
    ];
}

// ══════════════ ENTRIES ══════════════

function handle_entries(string $method, array $seg): void
{
    $user = require_auth();

    // /entries (POST create)
    if (count($seg) === 1 && $method === 'POST') {
        $data = json_body();
        $projectId = (int)get_int($data, 'project_id');
        $project = db_row("SELECT * FROM projects WHERE id = $projectId AND organization_id = " . $user['organization_id']);
        if (!$project) {
            throw new ApiError('Not found', 404);
        }
        $bpId = (isset($data['blueprint_id']) && $data['blueprint_id'] !== '') ? (int)$data['blueprint_id'] : 'NULL';
        $eid = db_insert("INSERT INTO entries (project_id, blueprint_id, element_name, animation_name, looping, duration, description, artist, projected_hours, actual_hours, priority, phase, status, asset_link) VALUES ("
            . $projectId . ", " . $bpId . ", " . db_quote(get_str($data, 'element_name')) . ", "
            . db_quote(get_str($data, 'animation_name')) . ", " . (get_bool($data, 'looping', false) ? 1 : 0) . ", "
            . db_quote(get_str($data, 'duration')) . ", " . db_quote(get_str($data, 'description')) . ", "
            . db_quote(get_str($data, 'artist')) . ", " . get_float($data, 'projected_hours') . ", "
            . get_float($data, 'actual_hours') . ", " . db_quote(get_str($data, 'priority', 'Medium')) . ", "
            . db_quote(get_str($data, 'phase', 'Animating')) . ", "
            . db_quote(get_str($data, 'status', 'Not Started')) . ", "
            . db_quote(get_str($data, 'asset_link')) . ")");
        json_out(entry_out(db_row('SELECT * FROM entries WHERE id = ' . $eid)), 201);
    }

    if (count($seg) >= 2) {
        $eid = (int)$seg[1];
        $entry = db_row('SELECT * FROM entries WHERE id = ' . $eid);
        if (!$entry) {
            throw new ApiError('Not found', 404);
        }
        $project = db_row('SELECT * FROM projects WHERE id = ' . (int)$entry['project_id'] . ' AND organization_id = ' . $user['organization_id']);
        if (!$project) {
            throw new ApiError('Forbidden', 403);
        }

        // /entries/{id}
        if (count($seg) === 2) {
            if ($method === 'GET') {
                json_out(entry_out($entry));
            }
            if ($method === 'PUT') {
                $data = json_body();
                $allowed = ['element_name', 'animation_name', 'looping', 'duration', 'description', 'artist', 'projected_hours', 'actual_hours', 'priority', 'phase', 'alert_flag', 'alert_flag_reason', 'image_path', 'status', 'asset_link', 'archived'];
                // Columns declared NOT NULL in the schema; NULL there is a
                // hard SQL error rather than a clearable value.
                $notNull = ['element_name', 'animation_name', 'duration', 'description', 'artist', 'priority', 'status', 'asset_link', 'image_path'];
                $sets = [];
                $updatedHours = false;
                foreach ($allowed as $field) {
                    if (!array_key_exists($field, $data)) {
                        continue;
                    }
                    $v = $data[$field];
                    if ($field === 'archived') {
                        $sets[] = 'archived = ' . (int)(bool)$v;
                        continue;
                    }
                    if ($v === null) {
                        if (in_array($field, $notNull, true)) {
                            $v = '';
                        } else {
                            $sets[] = "$field = NULL";
                            continue;
                        }
                    }
                    if ($field === 'looping' || $field === 'alert_flag') {
                        $sets[] = "$field = " . (get_bool($data, $field, false) ? 1 : 0);
                    } elseif (in_array($field, ['projected_hours', 'actual_hours'], true)) {
                        $sets[] = "$field = " . get_float($data, $field);
                        $updatedHours = true;
                    } else {
                        $sets[] = "$field = " . db_quote((string)$v);
                    }
                }
                if ($sets) {
                    db_exec('UPDATE entries SET ' . implode(', ', $sets) . ' WHERE id = ' . $eid);
                }
                // Alert logic: auto-flag if actual > projected * threshold
                if ($updatedHours) {
                    $entry = db_row('SELECT * FROM entries WHERE id = ' . $eid);
                    $threshold = 1.25;
                    $ph = (float)$entry['projected_hours'];
                    $ah = (float)$entry['actual_hours'];
                    if ($ph > 0 && $ah > $ph * $threshold) {
                        db_exec("UPDATE entries SET alert_flag = 1, alert_flag_reason = " . db_quote("auto: {$ah}h > {$ph}h * {$threshold}") . " WHERE id = $eid");
                    }
                }
                json_out(entry_out(db_row('SELECT * FROM entries WHERE id = ' . $eid)));
            }
            if ($method === 'DELETE') {
                // Soft delete. Hard `DELETE FROM entries` needs the DELETE
                // grant, which shared hosts commonly withhold, and it would
                // throw away the entry's history and attached images.
                if (!db_have_archived('entries')) {
                    throw new ApiError(
                        'Soft delete unavailable: the `entries.archived` column is missing. '
                        . 'The database user needs ALTER to add it (see /api/db-check).',
                        500
                    );
                }
                db_exec("UPDATE entries SET archived = 1 WHERE id = $eid");
                json_out(['ok' => true]);
            }
            throw new ApiError('Method not allowed', 405);
        }

        // /entries/{id}/flag
        if ($seg[2] === 'flag' && count($seg) === 3 && $method === 'POST') {
            $data = json_body();
            $flag = array_key_exists('alert_flag', $data) ? get_bool($data, 'alert_flag', false) : !((bool)$entry['alert_flag']);
            $reason = get_opt_str($data, 'alert_flag_reason') ?? 'manual: flagged by ' . $user['name'];
            db_exec("UPDATE entries SET alert_flag = " . ($flag ? 1 : 0) . ", alert_flag_reason = " . db_quote($reason) . " WHERE id = $eid");
            json_out(['ok' => true]);
        }

        // /entries/{id}/images (GET list, POST upload)
        if ($seg[2] === 'images' && count($seg) === 3) {
            handle_entry_images_list_upload($method, $eid);
        }

        // /entries/{id}/images/reorder (PUT)
        if ($seg[2] === 'images' && count($seg) === 4 && $seg[3] === 'reorder' && $method === 'PUT') {
            $data = json_body();
            $order = (array)($data['order'] ?? []);
            foreach ($order as $idx => $imgId) {
                db_exec('UPDATE entry_images SET sort_order = ' . (int)$idx . ' WHERE id = ' . (int)$imgId . ' AND entry_id = ' . $eid);
            }
            json_out(['ok' => true]);
        }

        // /entries/{id}/images/{imageId} (DELETE)
        if ($seg[2] === 'images' && count($seg) === 4 && $method === 'DELETE') {
            $imgId = (int)$seg[3];
            $img = db_row("SELECT * FROM entry_images WHERE id = $imgId AND entry_id = $eid");
            if (!$img) {
                throw new ApiError('Not found', 404);
            }
            $path = UPLOAD_DIR . '/' . $img['image_path'];
            if (is_file($path)) {
                @unlink($path);
            }
            db_exec("DELETE FROM entry_images WHERE id = $imgId");
            json_out(['ok' => true]);
        }

        throw new ApiError('Not found', 404);
    }

    throw new ApiError('Not found', 404);
}

function entry_out(array $e): array
{
    return [
        'id' => (int)$e['id'],
        'project_id' => (int)$e['project_id'],
        'blueprint_id' => $e['blueprint_id'] !== null ? (int)$e['blueprint_id'] : null,
        'element_name' => (string)$e['element_name'],
        'animation_name' => (string)($e['animation_name'] ?? ''),
        'archived' => to_bool($e['archived'] ?? 0),
        'looping' => to_bool($e['looping']),
        'duration' => (string)($e['duration'] ?? ''),
        'description' => (string)($e['description'] ?? ''),
        'artist' => (string)($e['artist'] ?? ''),
        'projected_hours' => (float)($e['projected_hours'] ?? 0),
        'actual_hours' => (float)($e['actual_hours'] ?? 0),
        'priority' => (string)($e['priority'] ?? 'Medium'),
        'phase' => $e['phase'] !== null ? (string)$e['phase'] : null,
        'alert_flag' => to_bool($e['alert_flag']),
        'alert_flag_reason' => $e['alert_flag_reason'] !== null ? (string)$e['alert_flag_reason'] : null,
        'image_path' => (string)($e['image_path'] ?? ''),
        'status' => (string)($e['status'] ?? 'Not Started'),
        'asset_link' => (string)($e['asset_link'] ?? ''),
    ];
}

// ══════════════ ENTRY IMAGES ══════════════

function handle_entry_images_list_upload(string $method, int $eid): void
{
    if ($method === 'GET') {
        $rows = db_rows("SELECT * FROM entry_images WHERE entry_id = $eid ORDER BY sort_order");
        $out = [];
        foreach ($rows as $img) {
            $out[] = [
                'id' => (int)$img['id'],
                'entry_id' => (int)$img['entry_id'],
                'image_path' => (string)$img['image_path'],
                'sort_order' => (int)$img['sort_order'],
                'uploaded_at' => (string)$img['uploaded_at'],
            ];
        }
        json_out($out);
    }

    if ($method === 'POST') {
        if (!isset($_FILES['file'])) {
            throw new ApiError('No file uploaded', 400);
        }
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new ApiError('Upload failed', 400);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true)) {
            throw new ApiError('Unsupported file type', 400);
        }
        $filename = 'entry_' . $eid . '_' . time() . '.' . $ext;
        $dest = UPLOAD_DIR . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new ApiError('Could not save file', 500);
        }
        $count = (int)db_scalar("SELECT COUNT(*) FROM entry_images WHERE entry_id = $eid");
        $iid = db_insert("INSERT INTO entry_images (entry_id, image_path, sort_order, uploaded_at) VALUES ($eid, " . db_quote($filename) . ", $count, '" . now_iso() . "')");
        $img = db_row('SELECT * FROM entry_images WHERE id = ' . $iid);
        json_out([
            'id' => (int)$img['id'],
            'entry_id' => (int)$img['entry_id'],
            'image_path' => (string)$img['image_path'],
            'sort_order' => (int)$img['sort_order'],
            'uploaded_at' => (string)$img['uploaded_at'],
        ], 201);
    }

    throw new ApiError('Method not allowed', 405);
}

// ══════════════ TAGS ══════════════

function handle_tags(string $method, array $seg): void
{
    $user = require_auth();
    // DELETE /tags/{id}
    if (count($seg) === 2 && $method === 'DELETE') {
        $tagId = (int)$seg[1];
        $tag = db_row('SELECT * FROM tags WHERE id = ' . $tagId);
        if (!$tag) {
            throw new ApiError('Not found', 404);
        }
        $project = db_row('SELECT * FROM projects WHERE id = ' . (int)$tag['project_id'] . ' AND organization_id = ' . $user['organization_id']);
        if (!$project) {
            throw new ApiError('Forbidden', 403);
        }
        db_exec('DELETE FROM tags WHERE id = ' . $tagId);
        json_out(['ok' => true]);
    }
    throw new ApiError('Not found', 404);
}

// ══════════════ COMMENTS ══════════════

function handle_comments(string $method, array $seg): void
{
    $user = require_auth();
    // DELETE /comments/{id}
    if (count($seg) === 2 && $method === 'DELETE') {
        $cid = (int)$seg[1];
        $comment = db_row('SELECT * FROM comments WHERE id = ' . $cid);
        if (!$comment) {
            throw new ApiError('Not found', 404);
        }
        $project = db_row('SELECT * FROM projects WHERE id = ' . (int)$comment['project_id'] . ' AND organization_id = ' . $user['organization_id']);
        if (!$project) {
            throw new ApiError('Forbidden', 403);
        }
        if ((int)$comment['author_id'] !== (int)$user['id'] && $user['role'] !== 'admin') {
            throw new ApiError('Forbidden', 403);
        }
        db_exec('DELETE FROM comments WHERE id = ' . $cid);
        json_out(['ok' => true]);
    }
    throw new ApiError('Not found', 404);
}