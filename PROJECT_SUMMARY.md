# Artist Asset Tool - Project Summary

## Purpose

A web-based choreography management tool for animation studios. Artists and producers track animation elements, states, assignments, hours, and progress across projects.

## Tech Stack

- **Backend**: Python FastAPI + SQLAlchemy ORM + SQLite
- **Frontend**: Vanilla HTML/CSS/JavaScript (no build tools)
- **Server**: Uvicorn on port 8000
- **Launch**: `run.bat`

## Core Concepts

| Concept | Description |
|---------|-------------|
| **Blueprint** | Reusable definition of an animation element (e.g., "Wild Symbol") with multiple states (Idle, Win, BigWin, etc.) |
| **Template** | A collection of blueprints bundled together for common project types |
| **Project** | An active work instance, optionally created from a template |
| **Entry** | A single animation task within a project (element + state + artist + hours + status) |

## Data Flow

```
Blueprint (element + states)
    ↓
Template (group of blueprints)
    ↓
Project (instance of a template)
    ↓
Entries (individual tasks with tracking)
```

## API Endpoints

### Blueprints
- `POST /api/blueprints` — Create blueprint with states
- `GET /api/blueprints` — List all
- `GET /api/blueprints/{id}` — Get one
- `PUT /api/blueprints/{id}` — Update
- `DELETE /api/blueprints/{id}` — Delete

### Templates
- `POST /api/templates` — Create template with blueprint IDs
- `GET /api/templates` — List all
- `GET /api/templates/{id}` — Get one
- `PUT /api/templates/{id}` — Update
- `DELETE /api/templates/{id}` — Delete

### Projects
- `POST /api/projects` — Create (optionally from template)
- `GET /api/projects` — List all
- `GET /api/projects/{id}` — Get one
- `PUT /api/projects/{id}` — Update name/status
- `DELETE /api/projects/{id}` — Delete

### Entries
- `GET /api/projects/{id}/entries` — List entries for project
- `POST /api/entries` — Create entry
- `PUT /api/entries/{id}` — Update entry fields
- `DELETE /api/entries/{id}` — Delete entry
- `POST /api/entries/{id}/image` — Upload reference image
- `DELETE /api/entries/{id}/image` — Remove image

### Utilities
- `GET /api/projects/{id}/rollup` — Hours summary by element/artist
- `POST /api/import-docx` — Parse choreography .docx file
- `POST /api/reel/upload` — Upload reel spec image
- `GET /api/health` — Health check

## Database Schema

### Blueprint
| Column | Type |
|--------|------|
| id | Integer PK |
| name | String (unique) |
| description | Text |
| created_at | DateTime |

### BlueprintState
| Column | Type |
|--------|------|
| id | Integer PK |
| blueprint_id | FK → blueprints.id |
| name | String |
| default_looping | Boolean |
| default_duration | String |
| default_description | Text |

### Template
| Column | Type |
|--------|------|
| id | Integer PK |
| name | String |
| description | Text |
| created_at | DateTime |

### TemplateBlueprint
| Column | Type |
|--------|------|
| id | Integer PK |
| template_id | FK → templates.id |
| blueprint_id | FK → blueprints.id |
| sort_order | Integer |

### Project
| Column | Type |
|--------|------|
| id | Integer PK |
| name | String |
| template_id | FK → templates.id (nullable) |
| status | String (default: "active") |
| created_at | DateTime |

### Entry
| Column | Type |
|--------|------|
| id | Integer PK |
| project_id | FK → projects.id |
| element_name | String (indexed) |
| animation_name | String |
| looping | Boolean |
| duration | String |
| description | Text |
| artist | String |
| hours | Float |
| image_path | String |
| status | String (default: "Not Started") |

## Frontend Structure

Single-page app with three tabs:
1. **Projects** — Card grid with progress bars, detail view with entry table
2. **Templates** — Card grid, create/edit with blueprint checkboxes
3. **Blueprints** — Card grid, create/edit with dynamic state rows

### Entry Status Flow
```
Not Started → Drawing → Animating → Review → Done
```

## Key Features

- Inline editing of all entry fields
- Image upload/reference per entry
- Progress tracking (entries marked "Done")
- Hours rollup by element and artist
- Search/filter across all views
- Import from .docx choreography documents
- Modal forms for CRUD operations

## File Structure

```
├── backend/
│   ├── main.py          # FastAPI routes (395 lines)
│   ├── models.py        # SQLAlchemy models (73 lines)
│   ├── schemas.py       # Pydantic schemas (102 lines)
│   ├── database.py      # SQLite engine/session (19 lines)
│   └── requirements.txt
├── frontend/
│   ├── index.html       # Entry point (25 lines)
│   ├── app.js           # SPA logic (468 lines)
│   └── style.css        # Styles (104 lines)
├── uploads/             # User-uploaded images
├── references/          # Source choreography docs
├── run.bat              # Launch script
├── start-dev.bat        # Development launcher
├── PROJECT.md           # Project memory
└── PROJECT_SUMMARY.md   # This file
```
