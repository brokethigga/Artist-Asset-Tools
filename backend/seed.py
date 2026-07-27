"""Seed the database with initial data."""
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))

from database import SessionLocal, engine, Base
from models import Organization, User

Base.metadata.create_all(bind=engine)
db = SessionLocal()

# Create default organization
org = db.query(Organization).filter(Organization.id == 1).first()
if not org:
    org = Organization(id=1, name="Internal", plan="internal")
    db.add(org)
    db.commit()
    print("Created default organization (id=1)")
else:
    print("Default organization already exists")

db.close()
