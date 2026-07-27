import os
from pathlib import Path
from dotenv import load_dotenv

load_dotenv(Path(__file__).parent.parent / ".env")

DATABASE_URL = os.getenv("DATABASE_URL", "sqlite:///./choreo.db")
SECRET_KEY = os.getenv("SECRET_KEY", "dev-secret-change-in-production")
GOOGLE_CLIENT_ID = os.getenv("GOOGLE_CLIENT_ID", "")
GOOGLE_CLIENT_SECRET = os.getenv("GOOGLE_CLIENT_SECRET", "")
ALERT_THRESHOLD = float(os.getenv("ALERT_THRESHOLD", "1.25"))
