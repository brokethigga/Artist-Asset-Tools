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
    bp = Blueprint(name="Wild", description="Wild substitute symbol", organization_id=1)
    bp.states.append(BlueprintState(name="Idle", default_looping=True, default_duration="5 sec", default_description="Looping idle animation"))
    bp.states.append(BlueprintState(name="Win", default_duration="3 sec", default_description="Win celebration animation"))
    bp.states.append(BlueprintState(name="SRS", default_duration="4 sec", default_description="Super Re-Spin trigger animation"))
    db.add(bp)

    bp2 = Blueprint(name="Scatter", description="Scatter bonus trigger symbol", organization_id=1)
    bp2.states.append(BlueprintState(name="Idle", default_looping=True, default_duration="5 sec", default_description="Looping idle animation"))
    bp2.states.append(BlueprintState(name="Win", default_duration="3 sec", default_description="Scatter win / bonus trigger"))
    db2_states = BlueprintState(name="SRS", default_duration="4 sec", default_description="Super Re-Spin scatter animation")
    bp2.states.append(db2_states)
    db.add(bp2)

    bp3 = Blueprint(name="Pot", description="Prize pot symbol (multi-skin, multi-level)", organization_id=1)
    bp3.states.append(BlueprintState(name="Idle", default_looping=True, default_duration="5 sec", default_description="Looping idle animation"))
    bp3.states.append(BlueprintState(name="Hit", default_duration="2 sec", default_description="Pot hit / land animation"))
    bp3.states.append(BlueprintState(name="Level up", default_duration="3 sec", default_description="Pot level up transition"))
    bp3.states.append(BlueprintState(name="Trigger", default_duration="4 sec", default_description="Pot bonus trigger animation"))
    db.add(bp3)

    bp4 = Blueprint(name="Winbox", description="Win presentation box", organization_id=1)
    bp4.states.append(BlueprintState(name="Idle", default_looping=True, default_duration="5 sec", default_description="Looping idle animation"))
    bp4.states.append(BlueprintState(name="Win", default_duration="4 sec", default_description="Win celebration / big win"))
    db.add(bp4)

    bp5 = Blueprint(name="Coin", description="Coin collect symbol", organization_id=1)
    bp5.states.append(BlueprintState(name="Idle", default_looping=True, default_duration="5 sec", default_description="Looping idle animation"))
    bp5.states.append(BlueprintState(name="Win", default_duration="3 sec", default_description="Coin collect / win animation"))
    db.add(bp5)

    bp6 = Blueprint(name="Collect", description="Collect / aggregator symbol", organization_id=1)
    bp6.states.append(BlueprintState(name="Idle", default_looping=True, default_duration="5 sec", default_description="Looping idle animation"))
    bp6.states.append(BlueprintState(name="Win", default_duration="3 sec", default_description="Collect animation"))
    db.add(bp6)

    db.commit()

    tpl = Template(name="3-Pot Hold & Win", description="Standard 3-pot bonus game with wild, scatter, and collect", organization_id=1)
    tpl.blueprints.append(TemplateBlueprint(blueprint_id=bp.id, sort_order=0))
    tpl.blueprints.append(TemplateBlueprint(blueprint_id=bp2.id, sort_order=1))
    tpl.blueprints.append(TemplateBlueprint(blueprint_id=bp3.id, sort_order=2))
    tpl.blueprints.append(TemplateBlueprint(blueprint_id=bp4.id, sort_order=3))
    tpl.blueprints.append(TemplateBlueprint(blueprint_id=bp5.id, sort_order=4))
    tpl.blueprints.append(TemplateBlueprint(blueprint_id=bp6.id, sort_order=5))
    db.add(tpl)
    db.commit()
    print("Seeded: 6 blueprints, 1 template")
else:
    print("Database already has data")

db.close()