# Artist Asset Tool — Handover Document

For Claude or any developer picking up this project.

---

## What This Is

A **Choreography Management Tool** for animation studios. Tracks animation
elements (Wild, Scatter, Pot), states (Idle, Win, Bonus), artists, hours,
and progress across projects.

**Currently:** Multi-tenant web app (FastAPI + PostgreSQL/SQLite + vanilla JS)
**Expanding to:** Hosted SaaS with export, backups, deployment

---

## Quick Start

```bash
# Run locally (dev mode, no Google OAuth needed)
run.bat

# Open browser
http://localhost:8000/app
```

---

## Codebase Structure

```
├── backend/
│   ├── main.py          # FastAPI routes (~810 lines)
│   ├── models.py        # SQLAlchemy models (~165 lines)
│   ├── schemas.py       # Pydantic schemas (~220 lines)
│   ├── database.py      # DB engine/session (25 lines)
│   ├── config.py        # Environment config (15 lines)
│   ├── seed.py          # Initial data seed
│   ├── alembic/         # Database migrations
│   └── requirements.txt
│
├── frontend/
│   ├── index.html       # Entry point
│   ├── app.js           # SPA logic (~795 lines)
│   └── style.css        # Styles (~195 lines)
│
├── uploads/             # User-uploaded images
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
├── Template (group of blueprints)
├── Tag (project-level labels, optional)
└── Project (instance of a template)
    ├── Entry (task with hours, priority, alerts, phase, asset_link)
    │   ├── EntryImage (multiple images)
    │   └── EntryTag (assigned tags — removed, tags are project-level only)
    └── Comment (with cross-linking)
```

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
- Entry filter bar (state, type, artist, priority, flag, status)
- Project-level tags with filter on project list
- Per-entry asset link (icon in state cell)
- Default entry type changed to "Animating"
- `phase` serialization fix (FastAPI 0.139.x)

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

## Notes for Claude

- Follow Ponytail Standards (`PONYTAIL_STANDARDS.md`) — lazy senior dev mode
- No unnecessary abstractions
- Deletion over addition
- Validate inputs at trust boundaries
- Run `rtk` prefix for commands to save context

---

## Status

**Current:** Phases 1-6 complete + additional enhancements, app functional
**Next:** Phase 7 — Exporter (Excel/CSV/PDF)
