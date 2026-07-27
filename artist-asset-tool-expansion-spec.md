# Artist Asset Tool — Expansion Spec

## 1. Overview

This document specifies the expansion of an existing choreography/animation
asset-tracking tool from a single-user local app into a hosted, multi-user
web application for an animation studio.

**Current state (already built):**
- Backend: Python, FastAPI, SQLAlchemy ORM, SQLite
- Frontend: Vanilla HTML/CSS/JavaScript (no build tools), single-page app
- Server: Uvicorn on port 8000, launched via `run.bat`
- Core concepts: `Blueprint` (reusable animation element + states) →
  `Template` (group of blueprints) → `Project` (instance of a template) →
  `Entry` (individual tracked task: element, state, artist, hours, status)

**Target state (this spec):** the same tool, hosted on the studio's website,
multi-user, with Google login, PostgreSQL, richer project/entry metadata,
reporting, and export.

---

## 2. Decisions already made

| Decision | Choice |
|---|---|
| Authentication | Google Sign-In (OAuth 2.0) |
| Access restriction | Open to **any** Google account (no company-domain restriction) — see §6 for a required safeguard on this |
| Database | Migrate from SQLite to **PostgreSQL** |
| Alert flag trigger | **Both** automatic (threshold-based) and manual (user-toggled) |
| Export formats | **Multiple**: Excel (.xlsx), CSV, and PDF |
| Image handling on upload | Convert to **WebP** to reduce file size |

---

## 3. New / changed data model

### 3.1 `Project` — add columns

| Column | Type | Notes |
|---|---|---|
| `game_type` | String | Tag/category, e.g. "Slots", "Table Game" |
| `customer` | String | Tag/category — client name |
| `deadline` | Date | Project deadline |
| `summary` | Text, nullable | Free-text note added at project close |
| `asset_link` | String (URL), nullable | Hotlink to where the project's assets live (shared drive, DAM, etc.) |

### 3.2 `Entry` — add columns

| Column | Type | Notes |
|---|---|---|
| `projected_hours` | Float | Estimated hours |
| `actual_hours` | Float | Replaces/supplements existing `hours` field — decide whether to rename `hours` → `actual_hours` or keep both; recommend renaming for clarity |
| `priority` | String / Enum | e.g. `Low`, `Medium`, `High` |
| `alert_flag` | Boolean, default `false` | True when entry is flagged (auto or manual — see §4.4) |
| `alert_flag_reason` | String, nullable | e.g. `"auto: over threshold"` or `"manual: <user note>"` |

### 3.3 New table: `EntryImage`

Replaces the current single `image_path` column on `Entry` with a proper
one-to-many relationship for multiple images per entry.

| Column | Type | Notes |
|---|---|---|
| `id` | Integer PK | |
| `entry_id` | FK → `entries.id` | |
| `image_path` | String | Path to stored WebP file |
| `sort_order` | Integer | Display order |
| `uploaded_at` | DateTime | |

Migration note: existing `Entry.image_path` values should be backfilled into
this table as the first `EntryImage` row per entry, then the old column
dropped (or kept temporarily and deprecated).

### 3.4 New table: `Comment`

| Column | Type | Notes |
|---|---|---|
| `id` | Integer PK | |
| `project_id` | FK → `projects.id` | |
| `entry_id` | FK → `entries.id`, nullable | Set if the comment is about a specific entry rather than the project generally |
| `author_id` | FK → `users.id` | |
| `body` | Text | |
| `linked_comment_id` | FK → `comments.id`, nullable, self-referencing | Lets a comment reference/link back to a related comment/issue elsewhere in the project |
| `created_at` | DateTime | |

### 3.5 New table: `User`

Backs Google OAuth login.

| Column | Type | Notes |
|---|---|---|
| `id` | Integer PK | |
| `email` | String, unique | From Google profile |
| `name` | String | From Google profile |
| `google_sub` | String, unique | Google's stable subject ID — use this, not email, as the durable identity key |
| `approved` | Boolean, default `false` | See §6 — required safeguard since sign-in is open to any Google account |
| `created_at` | DateTime | |
| `last_login` | DateTime, nullable | |

---

## 4. Feature specifications

### 4.1 Tags (game type, customer)
Simple string columns on `Project` (§3.1). No separate tag table needed
unless multi-select tagging is wanted later — start simple.

### 4.2 Deadline
`Project.deadline` (§3.1). Surface in project card/list view; consider
sorting/filtering projects by deadline proximity.

### 4.3 Project summary note
`Project.summary` (§3.1), a free-text field filled in when a project is
marked complete/closed. Not required at creation time.

### 4.4 Projected vs. actual hours + alert flag
- Add `projected_hours` and `actual_hours` to `Entry` (§3.2).
- **Automatic trigger:** a background job or computed check sets
  `alert_flag = true` when `actual_hours > projected_hours * THRESHOLD`
  (suggest THRESHOLD as a configurable constant, e.g. 1.25 = 25% over).
  Needs a decision on whether this runs on every entry update (real-time)
  or on a periodic sweep.
- **Manual trigger:** `POST /api/entries/{id}/flag` lets any user toggle
  `alert_flag` and set `alert_flag_reason` regardless of the hours math —
  e.g. flagging a blocked or at-risk task before it's actually over budget.
- Both paths write to the same `alert_flag` / `alert_flag_reason` fields;
  `alert_flag_reason` should indicate which path set it.

### 4.5 Multiple images per entry
Move from `Entry.image_path` (single string) to the `EntryImage` table
(§3.3). Endpoints:
- `POST /api/entries/{id}/images` — upload one or more images
- `DELETE /api/entries/{id}/images/{image_id}` — remove one
- `PUT /api/entries/{id}/images/reorder` — update `sort_order`

### 4.6 Image compression to WebP
On upload (in `POST /api/entries/{id}/images`), convert incoming images to
WebP before saving, using **Pillow** (`Image.save(path, "WEBP", quality=80)`).
Pillow has built-in WebP support via libwebp — no separate library needed.
Store only the converted WebP file; discard the original upload after
conversion (or keep temporarily if a "revert to original" feature is
wanted — not currently in scope).

### 4.7 Hour filtering / project-health summary
Extend the existing `GET /api/projects/{id}/rollup` endpoint (and/or add a
new one) to support:
- Filter by date range, artist, entry status
- Return projected-vs-actual totals (overall and per-artist/per-element)
- This is the primary "how's the project going" view — a producer should
  be able to see burn rate at a glance.

### 4.8 Priority flag
`Entry.priority` (§3.2). Simple enum field, editable inline like other
entry fields. Surface as a sortable/filterable column in the entry table.

### 4.9 Alert flag
See §4.4 — covered together with projected/actual hours since the two are
linked.

### 4.10 Asset location hotlink
`Project.asset_link` (§3.1) — a URL field shown prominently in the project
detail view, linking out to wherever the project's source assets live
(shared drive, DAM, etc.). Simple field + link render, no new logic needed.

### 4.11 Comments with cross-linking
`Comment` table (§3.4). Endpoints:
- `POST /api/projects/{id}/comments` — add a comment (optionally scoped
  to an `entry_id`, optionally referencing `linked_comment_id`)
- `GET /api/projects/{id}/comments` — list comments for a project
- `DELETE /api/comments/{id}` — remove a comment

When rendering, a comment with a `linked_comment_id` should show a visible
reference/jump-link to the comment it points to, so related issues across
a project are easy to trace.

### 4.12 Exporter (Excel, CSV, PDF)
Build one shared data-assembly layer (reuses the rollup/filter logic from
§4.7), with three output renderers:
- **Excel (.xlsx)** — via `openpyxl` or `xlsxwriter`
- **CSV** — trivial once the same tabular data is assembled
- **PDF** — a formatted summary report (e.g. via `reportlab` or
  `weasyprint` from an HTML template) — better suited to client-facing
  project summaries than raw data dumps
- Suggested endpoint shape: `GET /api/projects/{id}/export?format=xlsx|csv|pdf`

### 4.13 Database backup system
- Nightly scheduled `pg_dump` (cron or systemd timer)
- Rotate backups, keep a sensible retention window (e.g. 7–14 days)
- Ship backups off the host (not just local disk) — exact destination
  (S3, another server, etc.) depends on studio infrastructure and isn't
  decided yet

---

## 5. Authentication (Google Sign-In)

- Use **Authlib** or **google-auth** (Python) — both are well-maintained,
  standard libraries for verifying Google OAuth tokens server-side. Avoid
  hand-rolling token verification.
- Flow: frontend gets a Google ID token → backend verifies it against
  Google's public keys → look up or create a `User` row by `google_sub` →
  issue a session (signed cookie or JWT) for subsequent requests.
- Sign-in is open to any Google account (decision in §2) — **but see §6,
  this requires an approval gate.**

---

## 6. Required safeguard: open sign-in + approval gate

Because Google Sign-In is open to any Google account rather than
restricted to a company domain, an unapproved user could otherwise reach
the app simply by having a Google account. To prevent this:

- `User.approved` defaults to `false` on first login.
- Unapproved users see a "pending approval" screen instead of the app.
- An existing approved user (or a designated admin role) can approve new
  users — via an admin endpoint/screen, e.g. `POST /api/users/{id}/approve`.
- This is a small addition but should be built **at the same time** as the
  login flow, not bolted on afterward, since the login flow is otherwise
  functionally open to the public once deployed.

---

## 7. Migration & deployment notes

- **SQLite → PostgreSQL:** introduce **Alembic** for schema migrations
  going forward (rather than hand-editing tables) — start the migration
  history from the current schema, then layer in all changes from §3 as
  tracked migrations.
- **Deployment:** Gunicorn + Uvicorn workers behind Nginx as a reverse
  proxy, HTTPS via Let's Encrypt. Replace `run.bat` with a proper process
  manager (systemd service or equivalent) for the live environment.
- **Config:** Google OAuth client ID/secret and the PostgreSQL connection
  string must be environment-based (e.g. `.env` + `python-dotenv`, or
  actual environment variables in production) — never hardcoded.

---

## 8. Build phase suggestion (not required, just a sensible order)

1. Schema migration to PostgreSQL + Alembic setup + all new columns/tables
2. Auth (Google Sign-In + approval gate)
3. Hours, priority, alert flag logic
4. Multi-image upload + WebP conversion
5. Comments + cross-linking
6. Exporter (Excel/CSV/PDF)
7. Backup system
8. Deployment (Nginx, HTTPS, process manager)

---

## 9. Open items for the implementing AI/developer to flag back

- Whether `Entry.hours` should be renamed to `actual_hours` or kept
  alongside a new `actual_hours` column (see §3.2)
- The exact numeric threshold for automatic alert-flagging (§4.4)
- Whether the auto alert check runs in real-time on update or as a
  periodic sweep (§4.4)
- Backup destination (S3, another server, etc.) (§4.13)
- Who holds the "admin" role for approving new users (§6)
