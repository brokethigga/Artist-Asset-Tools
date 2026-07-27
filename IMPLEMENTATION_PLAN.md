# Artist Asset Tool — Implementation Plan

## Overview

Step-by-step plan to expand the Choreography Management Tool from single-user
local app to multi-tenant hosted web application.

**Spec:** `artist-asset-tool-expansion-spec-updated.md`
**Started:** 2026-07-27

---

## Phase 1: Foundation — Organization + Schema Migration

**Goal:** Establish multi-tenancy and migrate to PostgreSQL.

### Step 1.1: Alembic Setup
- Install dependencies: `alembic`, `psycopg2-binary`, `python-dotenv`
- Run `alembic init backend/alembic`
- Configure `alembic.ini` and `env.py` for PostgreSQL connection
- Set `sqlalchemy.url` from environment variable

### Step 1.2: Organization Table
- Create `organizations` table (id, name, plan, created_at)
- Seed with default org (id=1, name="Internal", plan="internal")

### Step 1.3: Add organization_id to All Top-Level Tables
- `projects.organization_id` FK → organizations.id
- `blueprints.organization_id` FK → organizations.id
- `templates.organization_id` FK → organizations.id
- All queries must filter by `organization_id`

### Step 1.4: Remaining Schema Changes
- **Project:** add game_type, customer, deadline, summary, asset_link
- **Entry:** add projected_hours, actual_hours, priority, alert_flag, alert_flag_reason
- **EntryImage:** new table (id, entry_id, image_path, sort_order, uploaded_at)
- **Comment:** new table (id, project_id, entry_id, author_id, body, linked_comment_id, created_at)
- **User:** new table (id, organization_id, email, name, google_sub, role, approved, created_at, last_login)
- **InviteLink:** new table (id, organization_id, token, email_optional, expires_at, used_at, created_at)
- Migrate Entry.image_path → backfill to EntryImage rows

### Step 1.5: Database Config
- Create `.env` file with `DATABASE_URL`, `GOOGLE_CLIENT_ID`, `SECRET_KEY`
- Update `database.py` to read from environment
- Remove hardcoded SQLite connection

**Deliverable:** PostgreSQL database with full schema, Alembic tracking all migrations.

---

## Phase 2: Authentication + Approval Gate + Invite System

**Goal:** Google login, user approval, multi-tenant onboarding.

### Step 2.1: Auth Dependencies
- Install: `authlib`, `httpx`, `python-jose` (or `PyJWT`)
- Add `itsdangerous` for signed session cookies

### Step 2.2: User Model + Session
- Create User model with org_id, google_sub, role, approved
- Session middleware: signed cookie with user_id
- Helper: `get_current_user(request)` → User or None
- Helper: `require_user(request)` → User or raises 401

### Step 2.3: Google OAuth Flow
- `GET /auth/login` — redirect to Google OAuth consent
- `GET /auth/callback` — handle Google response, verify token
- Look up or create User by google_sub
- If new user: approved=False, org_id=1 (internal)
- Set session cookie, redirect to app

### Step 2.4: Approval Gate
- Unapproved users see `/pending-approval` page
- `GET /api/users/pending` — list unapproved users (admin only)
- `POST /api/users/{id}/approve` — approve user (admin only)
- `POST /api/users/{id}/reject` — reject user (admin only)

### Step 2.5: Invite Link System
- `POST /api/organizations/{id}/invites` — generate invite token (admin only)
  - Returns `{ url: "https://app.example.com/invite/{token}" }`
- `GET /api/invites/{token}` — validate token (check expiry, not used)
- `POST /api/invites/{token}/accept` — after Google login, associate user with org
  - If first user for org → role=admin
  - Mark token as used

### Step 2.6: Org Assignment on Signup
- New internal users → org_id=1
- Invite flow → org_id from token
- Hardcoded for now; later: public signup with org selection

**Deliverable:** Full auth flow with Google login, approval gate, invite system.

---

## Phase 3: Org-Filtered API Endpoints

**Goal:** All existing endpoints enforce multi-tenant isolation.

### Step 3.1: Update All CRUD Endpoints
- Blueprints: filter by `Blueprint.organization_id == user.org_id`
- Templates: filter by `Template.organization_id == user.org_id`
- Projects: filter by `Project.organization_id == user.org_id`
- Entries: filter via parent Project's org_id
- Comments: filter via parent Project's org_id
- EntryImages: filter via parent Entry → Project's org_id

### Step 3.2: Update Schemas
- Add `organization_id` to Create/Out schemas where needed
- Auto-set `organization_id` from current user on create

### Step 3.3: Seed Data Migration
- Existing data gets `organization_id=1`
- Verify no data loss during migration

**Deliverable:** All API endpoints org-aware, no cross-tenant data leakage.

---

## Phase 4: Entry Features — Hours, Priority, Alerts

**Goal:** Projected vs actual hours, priority flags, alert system.

### Step 4.1: Hours Fields
- Rename `Entry.hours` → `Entry.actual_hours` (or keep both, per open question)
- Add `Entry.projected_hours`
- Update all frontend forms and display

### Step 4.2: Priority Field
- Add `Entry.priority` enum (Low, Medium, High)
- Inline editing in entry table
- Sortable/filterable column

### Step 4.3: Alert Flag System
- Auto-trigger: when `actual_hours > projected_hours * THRESHOLD`
  - Configurable constant (default 1.25 = 25% over)
  - Real-time check on entry update
- Manual trigger: `POST /api/entries/{id}/flag`
  - Toggle alert_flag + set alert_flag_reason
- Alert indicator in UI (badge, row highlight)

### Step 4.4: Project Rollup Enhancement
- Extend `GET /api/projects/{id}/rollup` with:
  - Projected vs actual totals
  - Per-artist and per-element breakdowns
  - Date range filtering

**Deliverable:** Full hours tracking, priority, alert system.

---

## Phase 5: Multi-Image Upload + WebP

**Goal:** Multiple images per entry with compression.

### Step 5.1: Image Upload Endpoints
- `POST /api/entries/{id}/images` — upload one or more
- `DELETE /api/entries/{id}/images/{image_id}` — remove one
- `PUT /api/entries/{id}/images/reorder` — update sort_order

### Step 5.2: WebP Conversion
- Install Pillow
- On upload: `Image.save(path, "WEBP", quality=80)`
- Store only WebP, discard original

### Step 5.3: Frontend Image Gallery
- Replace single image cell with gallery view
- Drag-to-reorder (optional, nice-to-have)
- Lightbox for full-size view

**Deliverable:** Multi-image support with WebP compression.

---

## Phase 6: Comments with Cross-Linking

**Goal:** Project/entry comments with linking.

### Step 6.1: Comment Endpoints
- `POST /api/projects/{id}/comments` — add comment
  - Optional: entry_id, linked_comment_id
- `GET /api/projects/{id}/comments` — list with author info
- `DELETE /api/comments/{id}` — delete (author or admin only)

### Step 6.2: Frontend Comment Thread
- Comment section in project detail view
- Optional: comment on specific entries
- Show linked_comment_id as jump-link

**Deliverable:** Comment system with cross-linking.

---

## Phase 7: Exporter (Excel, CSV, PDF)

**Goal:** Export project data in multiple formats.

### Step 7.1: Data Assembly Layer
- Shared function: `get_project_export_data(project_id, filters)`
- Returns structured data (entries, totals, breakdowns)

### Step 7.2: Export Endpoints
- `GET /api/projects/{id}/export?format=xlsx`
- `GET /api/projects/{id}/export?format=csv`
- `GET /api/projects/{id}/export?format=pdf`

### Step 7.3: Renderers
- Excel: openpyxl or xlsxwriter
- CSV: stdlib csv module
- PDF: reportlab or weasyprint

### Step 7.4: Frontend Export Button
- Export button in project detail view
- Format selection dropdown

**Deliverable:** Full export functionality.

---

## Phase 8: Database Backup System

**Goal:** Automated backups with rotation.

### Step 8.1: Backup Script
- `pg_dump` to file with timestamp
- Compress with gzip

### Step 8.2: Scheduling
- Cron job or systemd timer for nightly runs
- Configurable retention (7-14 days)

### Step 8.3: Off-site Storage (optional)
- S3 or remote server upload
- Can be deferred until deployment decision

**Deliverable:** Automated backup system.

---

## Phase 9: Deployment

**Goal:** Production-ready deployment.

### Step 9.1: Server Setup
- Gunicorn + Uvicorn workers
- Nginx reverse proxy
- Let's Encrypt HTTPS

### Step 9.2: Process Management
- systemd service for backend
- Replace run.bat with proper startup

### Step 9.3: Environment Config
- All secrets in environment variables
- Production .env or vault

### Step 9.4: Static File Serving
- Nginx serves frontend + uploads
- Backend only handles API

**Deliverable:** Deployed, production-ready application.

---

## Open Questions (from spec)

| Question | Recommendation |
|----------|----------------|
| Rename `hours` → `actual_hours` or keep both? | Rename for clarity |
| Alert threshold value? | 1.25 (25% over) — configurable |
| Real-time or periodic alert check? | Real-time on entry update |
| Backup destination? | Local first, S3 later |
| Who holds admin role? | First user per org auto-becomes admin |
| How second org onboarded? | Invite link (this plan) |

---

## Dependencies to Add

```
alembic
psycopg2-binary
python-dotenv
authlib
httpx
python-jose[cryptography]
itsdangerous
pillow
openpyxl
reportlab
```

---

## File Structure After Implementation

```
backend/
├── alembic/              # Migration scripts
├── alembic.ini           # Alembic config
├── main.py               # FastAPI app (updated)
├── models.py             # SQLAlchemy models (updated)
├── schemas.py            # Pydantic schemas (updated)
├── database.py           # DB engine/session (updated)
├── auth.py               # Auth helpers (new)
├── deps.py               # Dependency injection (new)
├── config.py             # Settings from env (new)
├── exporters/            # Export logic (new)
│   ├── __init__.py
│   ├── excel.py
│   ├── csv.py
│   └── pdf.py
└── requirements.txt      # Updated
```
