# Artist Asset Tool — Handover Document

For Claude or any developer picking up this project.

---

## What This Is

A **Choreography Management Tool** for animation studios. Tracks animation
elements (Wild, Scatter, Pot), animations (Idle, Win, Bonus), artists, hours,
and progress across projects.

**Live site:** the **PHP/MySQL build in `php-build/adam/`**, deployed to shared
hosting by uploading files via **FileZilla**. This is the app users actually
use.

**Dev source:** **FastAPI + SQLite + vanilla JS** in `backend/` + `frontend/`.
This is a parallel implementation used for local development. Changes made
here do **not** reach the live site unless the PHP build is changed and
uploaded.

> If you are fixing a bug users can see, fix it in `php-build/adam/`.

---

## Quick Start

```bash
# Run locally (dev mode, no Google OAuth needed)
run.bat

# Open browser
http://localhost:8000/app
```

### Deploying a change to the live site

1. Edit the files under `php-build/adam/`.
2. Upload them to the hosting account with FileZilla (same relative paths).
3. Hard-refresh the browser. No server restart is needed — PHP is interpreted
   per request, so there is nothing to cache.
4. If something writes to the database, check `https://<host>/adam/api/db-check`
   for connection and grant problems (see "Database Diagnostics").

---

## Codebase Structure

```
├── backend/              # FastAPI dev app (NOT the live site)
│   ├── main.py          # FastAPI routes (~810 lines)
│   ├── models.py        # SQLAlchemy models (~165 lines)
│   ├── schemas.py       # Pydantic schemas (~220 lines)
│   ├── database.py      # DB engine/session (25 lines)
│   ├── config.py        # Environment config (15 lines)
│   ├── seed.py          # Initial data seed
│   ├── alembic/         # Database migrations
│   └── requirements.txt
│
├── frontend/             # Vanilla JS for the FastAPI dev app
│   ├── index.html       # Entry point
│   ├── app.js           # SPA logic (~795 lines)
│   └── style.css        # Styles (~195 lines)
│
├── php-build/adam/       # THE LIVE APP — upload this via FileZilla
│   ├── index.php        # Front controller, routing, error handling
│   ├── .htaccess        # Apache rewrite to index.php
│   ├── lib/
│   │   ├── db.php       # MySQL + SQLite adapters, schema, soft-delete migration
│   │   ├── helpers.php  # Query wrappers, json_out, input parsing
│   │   ├── auth.php     # Google OAuth + email whitelist, signed-cookie sessions
│   │   ├── handlers.php # CRUD for blueprints/templates/projects/entries/tags
│   │   ├── export.php   # XLSX/CSV export
│   │   └── import_docx.php
│   ├── frontend/        # The UI that users see
│   ├── data/            # local SQLite only (production uses MySQL)
│   └── uploads/         # User-uploaded images
│
├── admin/pass.php       # MySQL credentials for the live site (NOT in git)
├── uploads/             # User-uploaded images (dev)
├── references/          # Source choreography docs
├── .env                 # Environment variables
├── run.bat              # Launch script
└── start-dev.bat        # Dev launcher
```

---

## Key Documents

| File | Purpose |
|------|---------|
| `PROJECT.md` | Project memory — tech stack, structure, launch |
| `PROJECT_SUMMARY.md` | Full summary — API endpoints, schema, features |
| `artist-asset-tool-expansion-spec.md` | Original expansion spec |
| `artist-asset-tool-expansion-spec-updated.md` | **Updated spec** — multi-tenant, invite system |
| `IMPLEMENTATION_PLAN.md` | Step-by-step build plan (9 phases) |
| `UPDATE_LOG.md` | Change log and decisions tracker |
| `HANDOVER.md` | This file |
| `Next.txt` | Original feature requests (raw notes) |

---

## Architecture

### Data Model (Current)

```
Organization (tenant)
├── User (Google OAuth, role, approved)
├── Blueprint (element + states)
│   └── BlueprintState
├── Template (group of blueprints)
│   └── TemplateBlueprint
├── InviteLink
└── Project (instance of a template)
    ├── Tag (project-level labels)
    ├── Entry (task with hours, priority, alerts, phase, asset_link)
    │   └── EntryImage (multiple images)
    └── Comment (with cross-linking)
```

Note: an earlier `EntryTag` table existed and was removed — tags are
project-level only.

### Multi-Tenancy

- Every top-level table has `organization_id` FK
- All queries filter by current user's org
- Dev mode: auto-creates "Internal" org (id=1)

### Auth Flow

- **Dev mode:** No Google OAuth configured → auto-login as Dev User
- **Production:** Google OAuth → verify token → create/approve User
- **Invite link:** Admin generates token → new user signs in → joins org

---

## API Endpoints (Current)

### Auth
- `GET /auth/login` — Google OAuth redirect
- `GET /auth/callback` — OAuth callback
- `GET /api/me` — Current user
- `POST /auth/logout` — Clear session

### Users (admin)
- `GET /api/users/pending` — Unapproved users
- `POST /api/users/{id}/approve` — Approve
- `POST /api/users/{id}/reject` — Reject

### Invites
- `POST /api/organizations/{id}/invites` — Generate invite
- `GET /api/invites/{token}` — Validate invite

### Blueprints
- CRUD at `/api/blueprints`

### Templates
- CRUD at `/api/templates`

### Projects
- CRUD at `/api/projects`
- `GET /api/projects/{id}/rollup` — Hours summary
- `GET /api/projects/{id}/tags` — List project tags
- `POST /api/projects/{id}/tags` — Create tag
- `DELETE /api/tags/{id}` — Delete tag

### Entries
- CRUD at `/api/entries`
- `POST /api/entries/{id}/flag` — Toggle alert flag
- `POST /api/entries/{id}/images` — Upload image (WebP)
- `DELETE /api/entries/{id}/images/{image_id}` — Remove image
- `PUT /api/entries/{id}/images/reorder` — Reorder images

### Comments
- `POST /api/projects/{id}/comments` — Add comment
- `GET /api/projects/{id}/comments` — List comments
- `DELETE /api/comments/{id}` — Delete comment

---

## Expansion Plan (Summary)

### Phase 1-6: COMPLETE
- Organization table + multi-tenancy
- PostgreSQL + Alembic
- Auth (Google + approval gate + invite system)
- Org-filtered APIs
- Hours/priority/alerts
- Multi-image + WebP
- Comments

### Phase 7-9: PENDING
- Exporter (Excel/CSV/PDF)
- Backups
- Deployment

### Additional: COMPLETE (per-user requests)
- `phase` column on Entry (Animating/Drawing work-type sub-entries)
- Collapsible element sections
- Type summary bar per element
- Real-time updates without page refresh
- Entry filter bar (animation, type, artist, priority, flag, status)
- Project-level tags with filter on project list
- Per-entry asset link (icon in animation cell)
- Default entry type changed to "Animating"
- `phase` serialization fix (FastAPI 0.139.x)
- Delete an entire animation group (× in the animation cell)
- "State" → "Animation" wording throughout the project view

---

## Dependencies (Current)

```
fastapi, uvicorn, sqlalchemy, python-docx
alembic, psycopg2-binary, python-dotenv
authlib, httpx, python-jose[cryptography], itsdangerous
pillow, openpyxl
```

---

## Key Decisions

| Decision | Choice | Why |
|----------|--------|-----|
| Multi-tenant | Organization table from day one | Sellable later, no codebase cloning |
| Onboarding | Invite link | Self-service, standard SaaS pattern |
| Auth | Google OAuth (any account) | Simple, no company domain restriction |
| Approval | Manual gate | Security since auth is open |
| DB | PostgreSQL + Alembic | Production-ready, migration tracking |
| Dev mode | Auto-login when no OAuth | Allows frontend dev without setup |
| Images | WebP compression | Smaller file sizes |
| Hours | projected + actual | Clear tracking, alert triggers |
| Tags | Project-level (Tag table) | Filter projects by tag on list view |
| Phase | Added to Entry for work-type sub-entries | Distinguish Animating vs Drawing within same element |
| asset_link | Per-entry (in state cell) | Link to asset per animation state |

---

## Open Questions

1. Backup destination (local first, S3 later)
2. Deployment stack (Gunicorn + Nginx + Let's Encrypt)

---

## Common Tasks

### Add a new endpoint
1. Add route in `backend/main.py`
2. Add schema in `backend/schemas.py` if needed
3. Update `frontend/app.js` to call it

### Add a new model
1. Add class in `backend/models.py`
2. Add schema in `backend/schemas.py`
3. Create migration: `cd backend && alembic revision --autogenerate -m "description"`
4. Apply: `alembic upgrade head`
5. Update `frontend/app.js` for display/edit

### Modify the frontend
- All SPA logic in `frontend/app.js`
- Styles in `frontend/style.css`
- HTML structure in `frontend/index.html`

---

## Running Migrations

```bash
cd backend
alembic revision --autogenerate -m "description"
alembic upgrade head
```

---

## Notes for AI Agents

- Follow Ponytail Standards (`PONYTAIL_STANDARDS.md`) — lazy senior dev mode
- No unnecessary abstractions
- Deletion over addition
- Validate inputs at trust boundaries
- Run `rtk` prefix for commands to save context
- Dev mode auto-login is active (GOOGLE_CLIENT_ID is empty in .env) — expected behaviour

---

## Current Status — Updated 2026-10-13

### Phase Progress
| Phase | Feature | Status |
|-------|---------|--------|
| 1–6 | Foundation, Auth, APIs, Hours, Images, Comments | ✅ Complete |
| 7 | Exporter (Excel/CSV/PDF) | 🔄 XLSX/CSV done; Asset Guide docx added 2026-10-13 |
| 8 | Database backups | ⏳ Not started |
| 9 | Deployment | 🔄 In planning — see below |

### What Happened This Session (2026-10-13)

Four approved changes to the live app (`php-build/adam/`), all local + verified,
**not yet uploaded**:

1. **No-state blueprints now appear in projects.** Applying a template whose
   blueprint has zero animation states inserts a placeholder row
   (`animation_name = ''`) instead of silently dropping the element. A one-time
   backfill (`app_meta.backfill_elements_v1`) repairs existing template-created
   projects: it stamps `blueprint_id` on matching entries and adds the missing
   placeholder rows. Runs automatically inside `bootstrap_db()`.
2. **Add Entry can pull from Blueprints.** The Add Entry modal has a Blueprint
   select + animation picker that prefills element/animation/looping/duration/
   description; `POST /entries` stores `blueprint_id`.
3. **Blueprint priority orders element sections.** New `blueprints.priority`
   (High/Medium/Low, default Medium). The project view sorts elements by
   priority rank → name. A badge shows each element's priority.
4. **Asset Guide per project, with Word export.** New project fields
   (`exported_location/format`, `source_location/format`, `skin_list`,
   `resize_from_spine`, `preview_link`), an Asset Guide panel in the project
   detail view, and **Export Asset Guide** producing `{name}_asset_guide.docx`.
5. **Entry type renamed "Drawing" → "Art".** The Type dropdown/summary now says
   `Animating`/`Art`; stored `phase='Drawing'` is migrated once to `'Art'`, and
   `safe_phase()` normalizes any legacy value at the API boundary.
6. **Blueprints define a Type.** New `blueprints.phase` (default `Art`), set in
   the blueprint form and shown on its card. Add-from-Blueprint prefills the
   entry's Type from the blueprint; template apply and the backfill stamp the
   blueprint's type onto created entries/placeholders.

**Deploy:** upload `lib/db.php`, `lib/helpers.php`, `lib/handlers.php`,
`lib/export.php`, `frontend/app.js` **and `frontend/style.css`** via FileZilla,
then hard-refresh. Schema changes auto-apply on first request; confirm with
`/adam/api/db-check`.

**Verification:** `php -l` clean, `node --check` clean, and a 28-assertion
end-to-end HTTP suite against a live `php -S` server on isolated temp SQLite
(placeholder creation, backfill idempotency + stamping, priority ordering,
Add-from-Blueprint, all 7 asset-guide fields, real `.docx` output, blueprint
type inheritance, `Drawing→Art` normalization). MySQL paths still unverified
locally — `/adam/api/db-check` on the live host is the check.

### Phase Progress
| Phase | Feature | Status |
|-------|---------|--------|
| 1–6 | Foundation, Auth, APIs, Hours, Images, Comments | ✅ Complete |
| 7 | Exporter (Excel/CSV/PDF) | ⏳ Not started — **next priority** |
| 8 | Database backups | ⏳ Not started |
| 9 | Deployment | 🔄 In planning — see below |

### What Happened This Session (2026-09-28)

The four reported bugs were real bugs in the **PHP/MySQL app that runs on the
live site** (`php-build/adam/`), not in the FastAPI dev app. An earlier pass in
this session had edited the FastAPI frontend on the wrong assumption; those
edits are still uncommitted in `frontend/` and are not what ships.

**Root cause of "I don't have permission for the database":** the app already
soft-deleted (`archived`) blueprints, templates, projects and blueprint states
— but **not entries**. Entry deletion was a hard `DELETE FROM entries`, and
`DELETE` is one of the grants shared hosting commonly withholds. So archiving a
project worked, but deleting an animation inside a project did not. Entries now
use the same soft-delete pattern.

1. **Could not delete an animation.** `entries` never got an `archived` column,
   so `DELETE /entries/{id}` issued a real `DELETE`. Added `archived` to the
   entries schema (both MySQL and SQLite) and to the soft-delete migration;
   `DELETE` is now `UPDATE entries SET archived = 1`. Entry lists, rollups and
   exports filter on `archived = 0`; images and history are retained. Added
   `deleteState(element, animation)` and a `×` button in the animation cell.
2. **"+ State" → "+ Animation."** Element header button, table column header
   and Add Entry label renamed. Blueprint forms keep "state" wording — that is
   the blueprint-level term.
3. **Checkbox misaligned with its label.** `style.css` had
   `.form-group input { width:100%; padding:8px; border:1px solid #ddd }`, which
   also matched checkboxes and stretched the Add Entry `Loop` checkbox across
   the row. Added a checkbox reset plus an inline-flex `.checkbox-label`, applied
   to every form checkbox in the app.
4. **Could not save the project.** Two causes:
   - `<input type="url">` triggered HTML5 native validation, which silently
     blocked submit for schemeless values like `drive.google.com/x` (browser
     tooltip only, no request sent). Now `type="text"` with a shared
     `normalizeUrl()` that prepends `https://`.
   - `updateProjectField` sent `val || null`, so an emptied inline field wrote
     `NULL` into `NOT NULL` columns (`projects.name`, and the same class of bug
     existed for entries). The handlers now coerce nulls to `''` for
     NOT NULL columns, and the frontend refuses to blank the project name.

Also fixed along the way:

- **Archived rows blocked their own name.** Blueprint create checked for a name
  match without `AND archived = 0`, so an archived blueprint made its own name
  unusable. Now only active rows conflict.
- **The soft-delete migration could take down every request.** It ran an
  `information_schema` query unconditionally, so it also ran on SQLite (where
  that query does not exist) and a host without `ALTER` would 500 on *every*
  request. It is now driver-aware and non-fatal.
- **Restore/Archive buttons showed the wrong label.** They keyed off the
  "Show archived" filter toggle instead of the row's own `archived` value. The
  API now returns `archived` on projects, blueprints, templates and entries, and
  archived cards are marked.

### Database Diagnostics

`GET /api/db-check` is public and safe (no data, no secrets). It reports the
driver, which candidate database name connected, the MySQL version, and — new —
`grants`: the raw `SHOW GRANTS` output plus booleans for
SELECT/INSERT/UPDATE/DELETE/ALTER, and whether the `archived` column exists on
each table that needs it (`soft_delete_ok`).

Check this first if archiving still fails. If `soft_delete_ok` is `false`, the
MySQL user needs the `ALTER` privilege (or an admin must add the column once);
the app cannot archive entries without it and will say so explicitly rather
than failing obscurely.

### Verification performed

- `php -l` clean on all 7 PHP files.
- 34 in-process API assertions against a real SQLite database, covering:
  project create/rename, null-to-NOT NULL guards, animation-group delete,
  rows retained after delete, restore, element delete, archived-name reuse,
  project archive/restore, rollup and entry updates. All pass.
- 26 frontend assertions over `esc()`, `normalizeUrl()`, the animation-group
  matching predicate, the label changes, and the project-save changes. All pass.
- **Not verified:** the MySQL code path, since there is no MySQL server
  available locally. The MySQL branch reuses the original, already-in-production
  `information_schema` query, and the grants report is new code.

### Earlier This Cycle (2026-08-16)

**Problem:** App was moved to a new computer and couldn't run.

**Fixed:**
1. **Migration bug** — `alembic/versions/a8dd0fa6bfe7_add_asset_link_to_entries.py` crashed on fresh DBs because it tried to `drop_index('ix_entry_tags_id')` which only existed on the old machine. Fixed with an `inspector.get_table_names()` guard — now safe on both fresh and existing databases.
2. **OAuth redirect URI** — `backend/main.py` line ~104 had hardcoded `http://localhost:8000/auth/callback`. Changed to read from `OAUTH_REDIRECT_URI` env var (falls back to localhost for dev). Required for production deployment.

**App is now running** on this machine at `http://localhost:8000/app`.

### Deployment Plan
See `DEPLOYMENT_PLAN.md` (to be created) — full plan written but not yet executed.

**Decision needed:** User is checking if their existing shared hosting (FTP/cPanel) supports Python App / Passenger. Two paths:
- ✅ If cPanel has "Setup Python App" → deploy there at no extra cost
- ❌ If not → use Railway.app (free tier) or a $5/mo VPS (DigitalOcean/Hetzner)

**Deployment blockers before going live:**
- [ ] Confirm hosting supports Python (user is checking)
- [ ] Get Google OAuth credentials (Client ID + Secret from console.cloud.google.com)
- [ ] Add `OAUTH_REDIRECT_URI=https://yourdomain.com/auth/callback` to production `.env`
- [ ] Switch `DATABASE_URL` to PostgreSQL in production `.env`
- [ ] Run `alembic upgrade head` on production DB

### Immediate Next Task for AI
**Phase 7 — Exporter.** When user is ready:
- `GET /api/projects/{id}/export?format=xlsx` — openpyxl (already installed)
- `GET /api/projects/{id}/export?format=csv` — stdlib csv (no install needed)
- `GET /api/projects/{id}/export?format=pdf` — reportlab (needs `pip install reportlab`)
- Add export button + format dropdown to frontend project detail view
- Shared data assembly function: `get_project_export_data(project_id, db)` used by all three renderers
- See `IMPLEMENTATION_PLAN.md` Phase 7 for full spec
