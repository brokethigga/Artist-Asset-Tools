import os, io, re
from datetime import datetime, timezone
from fastapi import FastAPI, Depends, HTTPException, UploadFile, File
from fastapi.staticfiles import StaticFiles
from fastapi.middleware.cors import CORSMiddleware
from sqlalchemy.orm import Session
from typing import List

from database import engine, Base, get_db
from models import Blueprint, BlueprintState, Template, TemplateBlueprint, Project, Entry
from schemas import (
    BlueprintCreate, BlueprintOut, BlueprintStateCreate, BlueprintStateOut,
    TemplateCreate, TemplateOut,
    ProjectCreate, ProjectOut,
    EntryCreate, EntryUpdate, EntryOut,
)

Base.metadata.create_all(bind=engine)

BASE_DIR = os.path.dirname(__file__)
UPLOAD_DIR = os.path.join(BASE_DIR, "..", "uploads")
FRONTEND_DIR = os.path.join(BASE_DIR, "..", "frontend")
REEL_SPEC_PATH = os.path.join(BASE_DIR, "..", "reel-spec.html")
os.makedirs(UPLOAD_DIR, exist_ok=True)

app = FastAPI(title="Choreography Manager")
app.add_middleware(CORSMiddleware, allow_origins=["*"], allow_methods=["*"], allow_headers=["*"])
app.mount("/uploads", StaticFiles(directory=UPLOAD_DIR), name="uploads")
app.mount("/app", StaticFiles(directory=FRONTEND_DIR, html=True), name="frontend")


@app.get("/reel-spec")
def serve_reel_spec():
    from fastapi.responses import FileResponse
    return FileResponse(REEL_SPEC_PATH)


@app.get("/api/health")
def health():
    return {"ok": True}


# ── BLUEPRINTS ──

@app.post("/api/blueprints", response_model=BlueprintOut)
def create_blueprint(bp: BlueprintCreate, db: Session = Depends(get_db)):
    existing = db.query(Blueprint).filter(Blueprint.name == bp.name).first()
    if existing:
        raise HTTPException(400, "Blueprint name already exists")
    obj = Blueprint(name=bp.name, description=bp.description)
    for s in bp.states:
        obj.states.append(BlueprintState(**s.model_dump()))
    db.add(obj)
    db.commit()
    db.refresh(obj)
    return obj


@app.get("/api/blueprints", response_model=List[BlueprintOut])
def list_blueprints(db: Session = Depends(get_db)):
    return db.query(Blueprint).order_by(Blueprint.name).all()


@app.get("/api/blueprints/{bp_id}", response_model=BlueprintOut)
def get_blueprint(bp_id: int, db: Session = Depends(get_db)):
    obj = db.query(Blueprint).filter(Blueprint.id == bp_id).first()
    if not obj:
        raise HTTPException(404)
    return obj


@app.put("/api/blueprints/{bp_id}", response_model=BlueprintOut)
def update_blueprint(bp_id: int, bp: BlueprintCreate, db: Session = Depends(get_db)):
    obj = db.query(Blueprint).filter(Blueprint.id == bp_id).first()
    if not obj:
        raise HTTPException(404)
    obj.name = bp.name
    obj.description = bp.description
    db.query(BlueprintState).filter(BlueprintState.blueprint_id == bp_id).delete()
    for s in bp.states:
        db.add(BlueprintState(blueprint_id=bp_id, **s.model_dump()))
    db.commit()
    db.refresh(obj)
    return obj


@app.delete("/api/blueprints/{bp_id}")
def delete_blueprint(bp_id: int, db: Session = Depends(get_db)):
    obj = db.query(Blueprint).filter(Blueprint.id == bp_id).first()
    if not obj:
        raise HTTPException(404)
    db.delete(obj)
    db.commit()
    return {"ok": True}


# ── TEMPLATES ──

@app.post("/api/templates", response_model=TemplateOut)
def create_template(t: TemplateCreate, db: Session = Depends(get_db)):
    obj = Template(name=t.name, description=t.description)
    for i, bp_id in enumerate(t.blueprint_ids):
        obj.blueprints.append(TemplateBlueprint(blueprint_id=bp_id, sort_order=i))
    db.add(obj)
    db.commit()
    db.refresh(obj)
    return obj


@app.get("/api/templates", response_model=List[TemplateOut])
def list_templates(db: Session = Depends(get_db)):
    return db.query(Template).order_by(Template.name).all()


@app.get("/api/templates/{t_id}", response_model=TemplateOut)
def get_template(t_id: int, db: Session = Depends(get_db)):
    obj = db.query(Template).filter(Template.id == t_id).first()
    if not obj:
        raise HTTPException(404)
    return obj


@app.put("/api/templates/{t_id}", response_model=TemplateOut)
def update_template(t_id: int, t: TemplateCreate, db: Session = Depends(get_db)):
    obj = db.query(Template).filter(Template.id == t_id).first()
    if not obj:
        raise HTTPException(404)
    obj.name = t.name
    obj.description = t.description
    db.query(TemplateBlueprint).filter(TemplateBlueprint.template_id == t_id).delete()
    for i, bp_id in enumerate(t.blueprint_ids):
        db.add(TemplateBlueprint(template_id=t_id, blueprint_id=bp_id, sort_order=i))
    db.commit()
    db.refresh(obj)
    return obj


@app.delete("/api/templates/{t_id}")
def delete_template(t_id: int, db: Session = Depends(get_db)):
    obj = db.query(Template).filter(Template.id == t_id).first()
    if not obj:
        raise HTTPException(404)
    db.delete(obj)
    db.commit()
    return {"ok": True}


# ── PROJECTS ──

@app.post("/api/projects", response_model=ProjectOut)
def create_project(p: ProjectCreate, db: Session = Depends(get_db)):
    obj = Project(name=p.name, template_id=p.template_id)
    db.add(obj)
    db.flush()

    if p.template_id:
        template = db.query(Template).filter(Template.id == p.template_id).first()
        if template:
            for tb in template.blueprints:
                bp = tb.blueprint
                if not bp:
                    continue
                for state in bp.states:
                    db.add(Entry(
                        project_id=obj.id,
                        element_name=bp.name,
                        animation_name=state.name,
                        looping=state.default_looping,
                        duration=state.default_duration,
                        description=state.default_description,
                        hours=0.0,
                    ))
    db.commit()
    db.refresh(obj)
    return obj


@app.get("/api/projects", response_model=List[ProjectOut])
def list_projects(db: Session = Depends(get_db)):
    return db.query(Project).order_by(Project.created_at.desc()).all()


@app.get("/api/projects/{p_id}", response_model=ProjectOut)
def get_project(p_id: int, db: Session = Depends(get_db)):
    obj = db.query(Project).filter(Project.id == p_id).first()
    if not obj:
        raise HTTPException(404)
    return obj


@app.put("/api/projects/{p_id}")
def update_project(p_id: int, data: dict, db: Session = Depends(get_db)):
    obj = db.query(Project).filter(Project.id == p_id).first()
    if not obj:
        raise HTTPException(404)
    if "name" in data:
        obj.name = data["name"]
    if "status" in data:
        obj.status = data["status"]
    db.commit()
    return {"ok": True}


@app.delete("/api/projects/{p_id}")
def delete_project(p_id: int, db: Session = Depends(get_db)):
    obj = db.query(Project).filter(Project.id == p_id).first()
    if not obj:
        raise HTTPException(404)
    db.delete(obj)
    db.commit()
    return {"ok": True}


# ── ENTRIES ──

@app.get("/api/projects/{p_id}/entries", response_model=List[EntryOut])
def list_entries(p_id: int, db: Session = Depends(get_db)):
    return db.query(Entry).filter(Entry.project_id == p_id).order_by(Entry.element_name, Entry.id).all()


@app.post("/api/entries", response_model=EntryOut)
def create_entry(e: EntryCreate, db: Session = Depends(get_db)):
    obj = Entry(**e.model_dump())
    db.add(obj)
    db.commit()
    db.refresh(obj)
    return obj


@app.put("/api/entries/{e_id}", response_model=EntryOut)
def update_entry(e_id: int, e: EntryUpdate, db: Session = Depends(get_db)):
    obj = db.query(Entry).filter(Entry.id == e_id).first()
    if not obj:
        raise HTTPException(404)
    for k, v in e.model_dump(exclude_unset=True).items():
        setattr(obj, k, v)
    db.commit()
    db.refresh(obj)
    return obj


@app.delete("/api/entries/{e_id}")
def delete_entry(e_id: int, db: Session = Depends(get_db)):
    obj = db.query(Entry).filter(Entry.id == e_id).first()
    if not obj:
        raise HTTPException(404)
    db.delete(obj)
    db.commit()
    return {"ok": True}


# ── IMAGE UPLOAD ──

@app.post("/api/entries/{e_id}/image")
def upload_entry_image(e_id: int, file: UploadFile = File(...), db: Session = Depends(get_db)):
    obj = db.query(Entry).filter(Entry.id == e_id).first()
    if not obj:
        raise HTTPException(404)

    ext = os.path.splitext(file.filename)[1] if file.filename else ".png"
    filename = f"entry_{e_id}_{int(datetime.now(timezone.utc).timestamp())}{ext}"
    path = os.path.join(UPLOAD_DIR, filename)

    with open(path, "wb") as f:
        f.write(file.file.read())

    obj.image_path = filename
    db.commit()
    return {"image_path": filename}


@app.delete("/api/entries/{e_id}/image")
def delete_entry_image(e_id: int, db: Session = Depends(get_db)):
    obj = db.query(Entry).filter(Entry.id == e_id).first()
    if not obj or not obj.image_path:
        raise HTTPException(404)
    path = os.path.join(UPLOAD_DIR, obj.image_path)
    if os.path.exists(path):
        os.remove(path)
    obj.image_path = ""
    db.commit()
    return {"ok": True}


# ── ROLLUP ──

@app.get("/api/projects/{p_id}/rollup")
def project_rollup(p_id: int, db: Session = Depends(get_db)):
    entries = db.query(Entry).filter(Entry.project_id == p_id).all()
    total_hours = sum(e.hours or 0 for e in entries)
    by_element = {}
    by_artist = {}
    for e in entries:
        h = e.hours or 0
        by_element.setdefault(e.element_name, {"element": e.element_name, "hours": 0, "count": 0})
        by_element[e.element_name]["hours"] += h
        by_element[e.element_name]["count"] += 1
        if e.artist:
            by_artist.setdefault(e.artist, {"artist": e.artist, "hours": 0, "count": 0})
            by_artist[e.artist]["hours"] += h
            by_artist[e.artist]["count"] += 1
    return {
        "total_hours": total_hours,
        "total_entries": len(entries),
        "by_element": sorted(by_element.values(), key=lambda x: -x["hours"]),
        "by_artist": sorted(by_artist.values(), key=lambda x: -x["hours"]),
    }


# ── REEL SPEC IMAGE UPLOAD ──

@app.post("/api/reel/upload")
def upload_reel_image(file: UploadFile = File(...)):
    ext = os.path.splitext(file.filename)[1] if file.filename else ".png"
    filename = f"reel_{int(datetime.now(timezone.utc).timestamp())}_{os.urandom(4).hex()}{ext}"
    path = os.path.join(UPLOAD_DIR, filename)
    with open(path, "wb") as f:
        f.write(file.file.read())
    return {"image_path": filename}


@app.delete("/api/reel/upload")
def delete_reel_image(data: dict):
    filename = data.get("image_path", "")
    if not filename:
        raise HTTPException(400, "No image_path provided")
    path = os.path.join(UPLOAD_DIR, filename)
    if os.path.exists(path):
        os.remove(path)
    return {"ok": True}


# ── IMPORT DOCX ──

@app.post("/api/import-docx")
def import_docx(file: UploadFile = File(...)):
    from docx import Document
    data = file.file.read()
    doc = Document(io.BytesIO(data))
    entries = []
    current_element = None

    for p in doc.paragraphs:
        text = p.text.strip()
        if not text or len(text) < 1:
            continue
        lower = text.lower()
        if lower.startswith("summary") or lower.startswith("design") or lower.startswith("animation total"):
            continue
        if len(text) < 30 and not re.search(r'\bhr\b', lower) and not re.search(r'\d', text):
            current_element = text
        hr_match = re.findall(r'(\d+[\d.]*)\s*hr', lower)
        if hr_match and current_element:
            total_h = sum(float(h) for h in hr_match)
            anim_name = re.sub(r'\s*:?\s*\d+[\d.]*\s*hr.*$', '', text).strip()
            if anim_name and len(anim_name) > 1 and not anim_name.startswith(" ") and current_element:
                entries.append({
                    "element_name": current_element, "animation_name": anim_name,
                    "looping": "loop" in lower or "idle" in lower,
                    "duration": "", "description": "", "artist": "", "hours": total_h,
                })

    for table in doc.tables:
        rows = table.rows
        if len(rows) < 4:
            continue
        label0 = rows[0].cells[0].text.strip().lower()
        if "symbol" not in label0 and "name" not in label0:
            continue
        looping, duration, description, artist, hours = "", "", "", "", 0.0
        for row in rows:
            cells = [c.text.strip() for c in row.cells]
            label = cells[0].lower() if cells else ""
            val = cells[1] if len(cells) > 1 else ""
            meta = cells[-1] if len(cells) > 2 else ""
            if "loop" in label:
                looping = val
            elif "dur" in label:
                duration = val
            elif "desc" in label:
                description = val
            elif "symbol" in label or "name" in label:
                artist = meta or val
        hr_match = re.findall(r'(\d+[\d.]*)\s*hr', (artist + " " + description).lower())
        if hr_match:
            hours = sum(float(h) for h in hr_match)
        if description or duration:
            entries.append({
                "element_name": current_element or "", "animation_name": f"{current_element or ''}",
                "looping": looping.lower() in ("yes", "y", "true"),
                "duration": duration, "description": description,
                "artist": re.sub(r'\s*\d+[\d.]*\s*hr.*$', '', artist).strip() if artist else "",
                "hours": hours,
            })
    return entries
