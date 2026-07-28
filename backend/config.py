import os
from pathlib import Path
from dotenv import load_dotenv

_backend_dir = Path(__file__).resolve().parent
load_dotenv(_backend_dir.parent / ".env")

DATABASE_URL = os.getenv("DATABASE_URL", f"sqlite:///{_backend_dir / 'choreo.db'}")
if DATABASE_URL.startswith("sqlite:///") and not DATABASE_URL.startswith("sqlite:////"):
    rel = DATABASE_URL[len("sqlite:///"):]
    DATABASE_URL = f"sqlite:///{(_backend_dir / rel).resolve()}"
SECRET_KEY = os.getenv("SECRET_KEY", "dev-secret-change-in-production")
GOOGLE_CLIENT_ID = os.getenv("GOOGLE_CLIENT_ID", "")
GOOGLE_CLIENT_SECRET = os.getenv("GOOGLE_CLIENT_SECRET", "")
ALERT_THRESHOLD = float(os.getenv("ALERT_THRESHOLD", "1.25"))
