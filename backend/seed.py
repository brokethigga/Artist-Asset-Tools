"""Seed the database with sample data."""
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))

from database import SessionLocal, engine, Base
from models import Organization, User, Blueprint, BlueprintState, Template, TemplateBlueprint

Base.metadata.create_all(bind=engine)
db = SessionLocal()

if not db.query(Organization).filter(Organization.id == 1).first():
    db.add(Organization(id=1, name="Internal", plan="internal"))
    db.commit()

if db.query(Blueprint).count() == 0:
    bp = Blueprint(name="Wild", description="Wild symbol", organization_id=1)
    bp.states.append(BlueprintState(name="Idle", default_looping=True, default_duration="5 sec", default_description="Looping idle"))
    bp.states.append(BlueprintState(name="Win", default_duration="3 sec", default_description="Win celebration"))
    bp.states.append(BlueprintState(name="Anticipation", default_duration="2 sec", default_description="Pre-win anticipation"))
    db.add(bp)

    bp2 = Blueprint(name="Scatter", description="Scatter symbol", organization_id=1)
    bp2.states.append(BlueprintState(name="Idle", default_looping=True, default_duration="5 sec", default_description="Looping idle"))
    bp2.states.append(BlueprintState(name="Win", default_duration="3 sec", default_description="Win celebration"))
    db.add(bp2)

    bp3 = Blueprint(name="Pot", description="Prize pot symbol", organization_id=1)
    bp3.states.append(BlueprintState(name="Idle", default_looping=True, default_duration="5 sec", default_description="Looping idle"))
    bp3.states.append(BlueprintState(name="Win", default_duration="4 sec", default_description="Pot win"))
    db.add(bp3)

    db.commit()

    tpl = Template(name="3-Pot Hold & Win", description="Standard 3-pot bonus game", organization_id=1)
    tpl.blueprints.append(TemplateBlueprint(blueprint_id=bp.id, sort_order=0))
    tpl.blueprints.append(TemplateBlueprint(blueprint_id=bp2.id, sort_order=1))
    tpl.blueprints.append(TemplateBlueprint(blueprint_id=bp3.id, sort_order=2))
    db.add(tpl)
    db.commit()
    print("Seeded: 3 blueprints, 1 template")
else:
    print("Database already has data")

db.close()