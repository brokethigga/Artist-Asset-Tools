# Artist Asset Tool — Update Log

Track all changes, decisions, and progress here.

**Format:** `## [Date] — Phase/Feature` then bullet points.

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

## Pending

- [ ] Phase 7: Exporter (Excel/CSV/PDF)
- [ ] Phase 8: Backups
- [ ] Phase 9: Deployment

---

## Decisions Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-07-27 | Multi-tenant from start | Sellable later, no codebase cloning |
| 2026-07-27 | Invite link for onboarding | Self-service, standard SaaS pattern |
| 2026-07-27 | First user per org = admin | Logical default, no separate setup needed |
| 2026-07-27 | Dev mode auto-login | Allows frontend dev without Google OAuth |
| 2026-07-27 | Rename hours → projected/actual | Clearer tracking |
| 2026-07-27 | Project-level tags (not entry-level) | Tags filter project list, per user request |
| 2026-07-27 | Per-entry asset link in state cell | Link per animation state, not just project |
| 2026-07-27 | Default phase = "Animating" | Per user request (was "Drawing") |

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
