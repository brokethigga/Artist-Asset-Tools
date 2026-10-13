# Artist Asset Tool — Update Log

Track all changes, decisions, and progress here.

**Format:** `## [Date] — Phase/Feature` then bullet points.

---

## 2026-10-13 — PHP app: keep element rows, blueprints priority, asset guide

**Target: `php-build/adam/` (live app, deployed via FileZilla). Four approved
changes. Auto-migrates on first request after upload.**

### 1. Blueprints with zero animation states still appear in projects
- Applying a template with a `states: []` blueprint now inserts a placeholder
  entry (`animation_name = ''`) instead of silently dropping the element.
- Template apply also skips archived blueprints and stamps `entries.blueprint_id`.
- One-time backfill (new `app_meta` table, key `backfill_elements_v1`, guarded
  by `INSERT ... OR/IGNORE`): existing template-created projects get
  (a) `blueprint_id` stamped on matching entries and (b) placeholder entries
  for missing no-state elements. Runs during `bootstrap_db()`, once, non-fatal.

### 2. "+ Add Entry" can pull from Blueprints
- Add Entry modal gains a Blueprint select + animation (state) picker. Choosing
  one prefills element / animation / looping / duration / description.
- `POST /entries` accepts optional `blueprint_id`; `entry_out` returns it.

### 3. Blueprint priority orders element sections
- New `blueprints.priority` (`High`/`Medium`/`Low`, default `Medium`),
  validated by `safe_priority()` in `helpers.php`.
- `GET /projects/{id}/entries` sorts in PHP: priority rank
  (High=0, Medium=1, Low=2) → `element_name` → `id`. Project view shows a
  priority badge in each element header. Exports keep `element_name` ordering.

### 4. Asset Guide per project + Word export
- New project fields: `exported_location`, `exported_format`, `source_location`,
  `source_format` (default `''`), `skin_list` (nullable), `resize_from_spine`
  (default `''`), `preview_link` (default `''`).
- Project detail has an Asset Guide panel (collapsible) and **Export Asset
  Guide** → `{name}_asset_guide.docx` via `export_asset_guide_docx()` in
  `lib/export.php` (title, field table, element list).

### Files changed
- `php-build/adam/lib/db.php` — CREATE TABLE + `$extraColumns` ALTER migration
  for both drivers, `app_meta`, backfill function.
- `php-build/adam/lib/helpers.php` — `safe_priority()`.
- `php-build/adam/lib/handlers.php` — blueprint priority, entries sort,
  `blueprint_id` pass-through, placeholder on template apply, asset-guide PUT/GET.
- `php-build/adam/lib/export.php` — `asset-guide` export.
- `php-build/adam/frontend/app.js` — priority select/badge, blueprints picker
  in Add Entry, Asset Guide panel + export button.

### Verification
- `php -l` clean on all changed PHP files; `node --check` clean on `app.js`.
- 22 end-to-end API assertions against a live `php -S` server on a real SQLite
  DB (isolated temp dir), all pass: placeholder entry creation, backfill
  one-time repair + `blueprint_id` stamping, priority ordering, Add-from-Blueprint,
  PUT/GET of all 7 asset-guide fields, asset-guide docx download (valid zip,
  `word/document.xml` contains the guide fields and element list).
- Harness lives in `%TEMP%\opencode\adamtest` (router.php, run_tests.ps1,
  php.ini for the winget PHP's sqlite3 ext) — reusable.
- **Not verified:** MySQL paths (no local MySQL). `/api/db-check` after upload
  will confirm columns/grants.

---

## 2026-10-13 — PHP app: type rename (Drawing→Art) + blueprint type

**Target: `php-build/adam/` (live app, deployed via FileZilla). Follow-up to the
2026-10-13 four-change round; ships with the same upload.**

### 5. Entry type renamed: "Drawing" is now "Art"
- `TYPES` in `app.js` is `['Animating','Art']` (Type dropdown, Add Entry form,
  type summary, filter). CSS class `.status-Art` replaces `.status-Drawing`.
- New idempotent migration in `bootstrap_db()`: `UPDATE entries SET
  phase='Art' WHERE phase='Drawing'` (runs every request; matches nothing after
  the first run).
- `safe_phase()` added in `helpers.php`: accepts `Art`/`Animating`, normalizes
  legacy `Drawing` → `Art`, everything else falls back to `Art`. Applied on
  `POST /entries` and `PUT /entries` (phase).

### 6. Blueprints carry a Type, entries inherit it
- New `blueprints.phase` column (default `'Art'`), auto-migrated via
  `$extraColumns` (`VARCHAR(50)`/`TEXT`).
- Blueprint form gains a Type select; the card shows the type; `GET/POST/PUT
  /blueprints` round-trip `phase` through `blueprint_out`.
- "Add Entry" → picking a Blueprint now prefills the entry's Type with the
  blueprint's type (`blueprintPicked`).
- Applying a template stamps `phase` from the blueprint onto both state rows and
  no-state placeholder rows. The one-time backfill (`backfill_elements_v1`)
  also writes the blueprint type into inserted placeholders.

### Files changed (this round)
- `php-build/adam/lib/db.php` — `blueprints.phase` (both drivers + ALTER),
  `Drawing→Art` data migration, backfill placeholder phase.
- `php-build/adam/lib/helpers.php` — `safe_phase()`.
- `php-build/adam/lib/handlers.php` — blueprint phase CRUD + `blueprint_out`,
  template-apply phase stamping, entries POST/PUT phase normalization.
- `php-build/adam/frontend/app.js` — `TYPES`, blueprint form select + card,
  `blueprintPicked` prefill.
- `php-build/adam/frontend/style.css` — `.status-Art`.

### Verification
- `php -l` clean on `db.php`, `handlers.php`, `helpers.php`; `node --check`
  clean on `app.js`.
- Harness extended to **28 assertions, all pass**: blueprint type default `Art`,
  explicit `Animating` round-trip, placeholder/state entries inherit blueprint
  type, legacy `Drawing` normalized to `Art` on POST and PUT, and the live
  `Drawing→Art` migration on a pre-fix entry during backfill.
- **Not verified:** MySQL paths (no local MySQL). `/api/db-check` after upload.

---

## 2026-10-13 — PHP app: owner-only permanent delete of archived items

**Target: `php-build/adam/` (live app, deployed via FileZilla). Ships with the
same upload as the two rounds above.**

### 7. Archived items can be permanently deleted — but only by the owner
- `DELETE /blueprints/{id}?permanent=1`, `DELETE /templates/{id}?permanent=1`,
  `DELETE /projects/{id}?permanent=1` all **require the owner account** and an
  **archived** item (living items must be archived first; tries on active items
  are rejected with 400).
- Owner identity = `OWNER_EMAIL` constant in `lib/auth.php`, default
  `adam@siamkoala.com`, overridable via `admin/pass.php` `owner_email` key.
  `require_owner()` returns 403 for everyone else.
- Cascades: blueprint → its `blueprint_states` and `template_blueprints` links
  (entries keep the stale `blueprint_id` and sort as Medium, so projects stay
  intact); template → its links; project → entries, entry images (files under
  `uploads/` are unlinked too), tags and comments.
- Needs the MySQL `DELETE` grant on the live host (archiving was the previous
  workaround because that grant was missing) — `/api/db-check` will confirm.

### Files changed (this round)
- `php-build/adam/lib/auth.php` — `OWNER_EMAIL` config + `require_owner()`.
- `php-build/adam/lib/handlers.php` — permanent-delete branches (blueprint,
  template, project) with cascade cleanup.
- `php-build/adam/frontend/app.js` — "Delete" (permanent) button on archived
  Blueprint/Template/Project cards, shown only when the signed-in email is the
  owner; hard confirm dialogs.

### Verification
- `php -l` clean on `auth.php`, `handlers.php`; `node --check` clean on
  `app.js`.
- Harness now **36 assertions, all pass**: 400 on permanent-delete of an active
  blueprint; owner deletes archived blueprint/template/project (project cascade
  verified — it 404s afterwards); a second non-owner user gets **403** and the
  archive survives.
- **Not verified:** MySQL paths (no local MySQL). `/api/db-check` after upload.

### Bugfix (same day): "project looks empty even though it has entries"
- `renderEntries()` referenced `elPrio` (priority badge in the element header)
  without ever defining it → every project detail threw
  `ReferenceError: elPrio is not defined` and the entries area never drew.
- Fixed in `frontend/app.js` by deriving the badge from the first entry's
  priority (`elPrio = (entries.find(e => e.priority) || {}).priority || 'Medium'`).
- Regression guard added to the harness: `rendercheck.js` loads the real app.js
  in a Node VM sandbox, calls `renderEntries()` with sample data and asserts the
  element sections actually render.

---

## 2026-07-27 — Phase 1-6 Complete

### Phase 1: Foundation
- Created `config.py` for environment variables (DATABASE_URL, SECRET_KEY, GOOGLE_CLIENT_ID, etc.)
- Created `.env` file with defaults
- Updated `database.py` to support both SQLite and PostgreSQL
- Added `Organization` model (multi-tenant foundation)
- Added `organization_id` FK to Blueprint, Template, Project
- Added `User` model with role, approved, google_sub
- Added `Comment` model with cross-linking
- Added `EntryImage` model for multiple images per entry
- Added `InviteLink` model for org onboarding
- Added new columns to `Project`: game_type, customer, deadline, summary, asset_link
- Added new columns to `Entry`: projected_hours, actual_hours, priority, alert_flag, alert_flag_reason
- Initialized Alembic with initial migration
- Created `seed.py` for default org

### Phase 2: Auth
- Added dev mode (auto-login when no Google OAuth configured)
- Added Google OAuth login/callback endpoints (stubs)
- Added `/api/me` endpoint
- Added user approval endpoints (admin only)
- Added invite link generation and validation

### Phase 3: Org-Filtered APIs
- All CRUD endpoints now filter by `organization_id`
- Blueprints, Templates, Projects, Entries, Comments all org-isolated

### Phase 4: Hours, Priority, Alerts
- Entry form now includes projected_hours, actual_hours, priority
- Auto alert flag when actual > projected * THRESHOLD (1.25)
- Manual flag toggle via `POST /api/entries/{id}/flag`
- Rollup shows projected vs actual totals

### Phase 5: Multi-Image + WebP
- `EntryImage` table replaces single image_path
- `POST /api/entries/{id}/images` with WebP conversion
- `DELETE /api/entries/{id}/images/{image_id}`
- `PUT /api/entries/{id}/images/reorder`

### Phase 6: Comments
- `POST /api/projects/{id}/comments`
- `GET /api/projects/{id}/comments`
- `DELETE /api/comments/{id}`

### Frontend Updates
- Project form: game_type, customer, deadline, asset_link fields
- Entry form: projected_hours, actual_hours, priority fields
- Entry table: shows projected/actual columns, priority, flagged rows
- Rollup: shows projected vs actual, flagged count
- CSS: flagged row highlighting, over-budget highlighting

---

## 2026-07-27 — Project Review

- Reviewed existing codebase
- Read expansion spec (original + updated)
- Key decision: Multi-tenant from day one (Organization table)
- Key decision: Invite link for org onboarding
- Created PROJECT_SUMMARY.md
- Created IMPLEMENTATION_PLAN.md
- Created UPDATE_LOG.md
- Created HANDOVER.md

---

## 2026-07-27 — UI Enhancements + Tags + Asset Link

### `phase` Column
- Added `phase` column to Entry model (Animating/Drawing work-type sub-entries)
- Default changed from "Drawing" to "Animating" in EntryCreate
- Fixed FastAPI 0.139.x serialization issue for `phase` — changed from `e.phase or None` pattern to explicit `EntryOut.model_validate()` in all endpoints
- Migration `1e0194921c9d` applied

### UI Improvements
- Removed empty `—` option from Type dropdown
- Elements are collapsible/expandable (click header to toggle)
- Type summary bar (`Drawing: Xh | Animating: Xh`) below each element header
- All entry changes update summaries and rollup in real time without page refresh
- Proj/Actual columns widened to 90px
- Single filter bar below search bar: State, Type, Artist, Priority, Flag, Status + Clear button
- Filter state preserved across re-renders; empty state shows filter bar + "No matches" message

### Project-Level Tags
- `Tag` model (id, project_id, name) — tags belong to a project
- CRUD endpoints: `GET/POST /api/projects/{id}/tags`, `DELETE /api/tags/{id}`
- Tag management in project detail view (add/delete tags with badges)
- Tag filter on project list screen — filter projects by tag name
- Migration `a8dd0fa6bfe7` applied (also cleans up leftover `entry_tags` table)

### Per-Entry Asset Link
- `asset_link` column on Entry (String, default `""`)
- Link icon (🔗) + edit pencil (✎) in state cell of entry table
- Click pencil to set/change URL, click 🔗 to open in new tab
- Auto-prepends `https://` if no protocol given
- Migration included in `a8dd0fa6bfe7`

### Bug Fixes
- Fixed `alert_flag_reason` missing from `EntryUpdate` schema
- Fixed `phase` not appearing in API responses (FastAPI 0.139.x compatibility)
- Fixed `asset_link: str` rejecting `NULL` from DB — changed to `Optional[str]`
- Fixed stray `}` causing JS SyntaxError
- Fixed `JSON.stringify` in onclick breaking HTML attributes

---

## 2026-09-28 — Live PHP app: delete animation, naming, checkbox, project save

**Target: `php-build/adam/` (the app deployed to the live host via FileZilla).**
An earlier pass in this session edited the FastAPI dev app on the wrong
assumption; those edits remain uncommitted in `frontend/` and are not deployed.

**Root cause behind "no permission for the database":** soft delete (`archived`)
was applied to `blueprints`, `templates`, `projects` and `blueprint_states`, but
**not to `entries`** — and entries are what an animation is made of. Deleting
one ran `DELETE FROM entries`, which needs a `DELETE` grant that shared hosting
often does not give. Archiving a project worked; deleting an animation inside
one did not.

### 1. Could not delete an animation in a project
- `lib/db.php`: added `archived` to the `entries` schema for both MySQL and
  SQLite, and added `entries` to the soft-delete migration list.
- `lib/handlers.php`: `DELETE /entries/{id}` now runs
  `UPDATE entries SET archived = 1` instead of `DELETE FROM entries`.
  Attached images and entry history are retained. The API reports a clear
  error if the `archived` column could not be created.
- Entry lists, rollups and exports filter on `archived = 0`.
- `frontend/app.js`: added `deleteState(element, animation)` plus a `×` button
  in the animation cell; it archives every entry in the group across all phases.

### 2. "+ State" renamed to "+ Animation"
- Element header button, entry table column header, Add Entry form label.
- Blueprint forms keep "state" wording — that is the blueprint-level term.

### 3. Checkbox no longer aligned with its label
- **Root cause:** `.form-group input { width:100%; padding:8px; border:1px solid #ddd }`
  also matched checkboxes, stretching them across the row.
- Added a checkbox reset and an inline-flex `.checkbox-label`; applied to every
  form checkbox (Add Entry Loop, blueprint state rows, template element picker).

### 4. Could not save the project
- **`type="url"` silently blocked submit.** HTML5 validation rejects schemeless
  values such as `drive.google.com/x`, so the browser refused to submit and only
  showed a tooltip — no error, no request. Now `type="text"` with a shared
  `normalizeUrl()` that prepends `https://`.
- **Emptying an inline field wrote `NULL` into a `NOT NULL` column.**
  `updateProjectField` sent `val || null`; the project PUT set
  `name = NULL`, which is a 500 on `projects.name`. Handlers now coerce nulls to
  `''` for NOT NULL columns (same bug existed on the entries PUT), and the
  frontend refuses to blank the project name.

### Also fixed
- **Archived rows blocked their own name.** Blueprint create matched names
  without `AND archived = 0`, so an archived blueprint made its name unusable.
- **The soft-delete migration could break every request.** It ran an
  `information_schema` query on both drivers (SQLite has no such table) and a
  failure 500'd the whole app. Now driver-aware and non-fatal.
- **Wrong Restore/Archive label.** The buttons keyed off the "Show archived"
  filter instead of the row's own state. The API now returns `archived` on
  projects, blueprints, templates and entries.
- `esc()` escapes quotes; asset-link `<a>` got `rel="noopener"`.

### Diagnostics
- `GET /api/db-check` now reports `grants`: raw `SHOW GRANTS`, booleans for
  SELECT/INSERT/UPDATE/DELETE/ALTER, and `soft_delete_ok` (whether `archived`
  exists on all five tables). Check this first if archiving still fails.

### Verification
- `php -l` clean on all 7 PHP files.
- 34 in-process API assertions on real SQLite — create/rename project,
  null-to-NOT NULL guards, animation-group delete, rows retained, restore,
  element delete, archived-name reuse, project archive/restore, rollup, entry
  updates. All pass.
- 26 frontend assertions over `esc()`, `normalizeUrl()`, the animation-group
  predicate, label changes and project save. All pass.
- **Not verified:** the MySQL path — no MySQL server available locally.

---

## Superseded — earlier 2026-09-28 pass against the FastAPI dev app
- The four fixes below were applied to `frontend/app.js` and
  `frontend/style.css` (the FastAPI dev app) before the live target was
  identified. Still uncommitted; kept for parity but not deployed.

### 1. Could not delete an animation (state) in a project
- **Root cause:** only per-entry (`deleteEntry`) and per-element (`deleteElement`) deletes existed — no way to remove a whole `animation_name` group.
- Added `deleteState(btn)` in `frontend/app.js` — collects every entry matching the element + animation and deletes them.
- Added a `×` button in the animation cell (`frontend/app.js`, state cell markup) styled as `.state-del`.
- Button carries `data-el` / `data-anim` and calls `deleteState(this)` so names containing quotes or apostrophes cannot break the inline handler.

### 2. "+ State" renamed to "+ Animation"
- Element header button: `+ State` → `+ Animation`
- Entry table column header: `State` → `Animation`
- Add Entry form label: `State (Animation)` → `Animation`
- Entry filter bar placeholder: `State` → `Animation`
- Template element picker: `(N states)` → `(N animations)`

### 3. Checkbox no longer aligned with its label
- **Root cause:** `style.css` rule `.form-group input { width:100%; padding:8px; border:1px solid #ddd }` also matched checkboxes, stretching the Add Entry `Loop` checkbox to full width. `width:auto` overrides existed for `.state-item` and `.entry-table` but not for `.form-group`.
- Added `.form-group input[type="checkbox"]` reset (`width:auto`, no padding/border).
- Added `label.checkbox-label` (`inline-flex`, `align-items:center`) and `.form-group .checkbox-slot`.
- Applied to Add Entry `Loop`, blueprint state rows, and added a `.check-list` block for the template element picker.

### 4. Could not save the project
Two independent causes, both fixed:
- **`type="url"` silently blocked submit.** HTML5 URL validation rejects schemeless values such as `drive.google.com/x`, so the browser refused to submit and only showed a native tooltip — no error, no request. Changed to `type="text"` and added a shared `normalizeUrl()` helper that prepends `https://` (matching the existing per-entry asset-link behaviour).
- **Clearing the inline project name returned HTTP 500.** `updateProjectField` sent `val || null`, so an emptied name wrote `NULL` into the `NOT NULL` `projects.name` column. Verified against the server log: `IntegrityError: NOT NULL constraint failed: projects.name`. Now only genuinely nullable columns (`deadline`, `asset_link`, `summary`) are cleared to `null`.
- `saveProject` wrapped in `try/catch` so a failure can no longer leave the modal in a half-saved state.

### Hardening
- `esc()` now escapes `"` and `'` (safe inside HTML attributes) and renders `0` as `"0"` instead of `""`.
- Added shared `normalizeUrl()`; removed the duplicated URL-prefix logic from `setAssetLink()` and the state cell.
- Project asset link `<a>` gained `rel="noopener"`.

---

## Pending

- [x] (2026-10-13) Blueprint→keep-element placeholder + `blueprint_id`, blueprint
      priority ordering, Add-from-Blueprint, per-project Asset Guide + docx,
      type rename Drawing→Art, blueprint type, owner-only permanent delete of
      archives — **still to upload to the live host via FileZilla** (7 files
      incl. `lib/auth.php` and `frontend/style.css`), then check
      `/adam/api/db-check`
- [ ] Upload the 2026-09-28 PHP fixes to the live host via FileZilla, then check
      `/adam/api/db-check` for `soft_delete_ok`
- [ ] Port the same fixes to the FastAPI dev app, or retire it in favour of
      the PHP build
- [ ] Phase 7: Exporter (Excel/CSV/PDF)
- [ ] Phase 8: Backups
- [ ] Phase 9: Deployment

---

## Decisions Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-09-28 | `php-build/adam/` is the app to fix and deploy | It is the PHP/MySQL app actually served on the live host; the FastAPI app is a parallel dev implementation |
| 2026-09-28 | Soft delete everywhere, never hard DELETE | Avoids needing a `DELETE` grant (often withheld on shared hosting) and keeps data restorable |
| 2026-09-28 | "Animation" is the project-view term, "state" stays on blueprints | Matches how users talk about the work |
| 2026-07-27 | Multi-tenant from start | Sellable later, no codebase cloning |
| 2026-07-27 | Invite link for onboarding | Self-service, standard SaaS pattern |
| 2026-07-27 | First user per org = admin | Logical default, no separate setup needed |
| 2026-07-27 | Dev mode auto-login | Allows frontend dev without Google OAuth |
| 2026-07-27 | Rename hours → projected/actual | Clearer tracking |
| 2026-07-27 | Project-level tags (not entry-level) | Tags filter project list, per user request |
| 2026-07-27 | Per-entry asset link in state cell | Link per animation state, not just project |
| 2026-07-27 | Default phase = "Animating" | Per user request (was "Drawing") |
| 2026-09-28 | "State" renamed to "Animation" in project view | Animation is the user-facing term; "state" reserved for blueprint definitions |
| 2026-10-13 | Blueprint priority orders element sections in a project | User's chosen meaning for "priority" |
| 2026-10-13 | Asset Guide fields are per project | Fields differ per project; docx export per project |
| 2026-10-13 | No-state blueprints appear as placeholder entries | Element must stay visible even with no animations yet |
| 2026-10-13 | One-time backfill is guarded by `app_meta`, runs in `bootstrap_db` | Idempotent repair; never repeats; auto-applies on deploy |
| 2026-10-13 | Entry type "Drawing" renamed to "Art" | User-facing naming; stored value migrated once, legacy term normalized in the API |
| 2026-10-13 | Blueprints define a Type; entries inherit it | Type is an element attribute, so blueprints hold it and Add-from-Blueprint prefills it |
| 2026-10-13 | Permanent delete of archived items is owner-only | One account (`OWNER_EMAIL`) may erase archives; everyone else 403 |
| 2026-10-13 | Permanent delete requires archiving first | No accidental erasure of live items via the destructive endpoint |

---

## Blockers

- Google OAuth needs real credentials configured in `.env`
- PostgreSQL not installed (using SQLite for dev)

---

## Notes

- Alert threshold: 1.25 (25% over) — configurable via ALERT_THRESHOLD env var
- Real-time alert check on entry update
- Backup: local first, S3/deployment decision later
- Server must be restarted to pick up backend Python changes (no `--reload` flag in run.bat)
