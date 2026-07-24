from pydantic import BaseModel
from typing import Optional
from datetime import datetime


class BlueprintStateCreate(BaseModel):
    name: str
    default_looping: bool = False
    default_duration: str = ""
    default_description: str = ""


class BlueprintStateOut(BlueprintStateCreate):
    id: int
    model_config = {"from_attributes": True}


class BlueprintCreate(BaseModel):
    name: str
    description: str = ""
    states: list[BlueprintStateCreate] = []


class BlueprintOut(BaseModel):
    id: int
    name: str
    description: str
    created_at: datetime
    states: list[BlueprintStateOut] = []
    model_config = {"from_attributes": True}


class TemplateBlueprintCreate(BaseModel):
    blueprint_id: int
    sort_order: int = 0


class TemplateCreate(BaseModel):
    name: str
    description: str = ""
    blueprint_ids: list[int] = []


class TemplateBlueprintOut(BaseModel):
    id: int
    blueprint_id: int
    sort_order: int
    blueprint: BlueprintOut | None = None
    model_config = {"from_attributes": True}


class TemplateOut(BaseModel):
    id: int
    name: str
    description: str
    created_at: datetime
    blueprints: list[TemplateBlueprintOut] = []
    model_config = {"from_attributes": True}


class ProjectCreate(BaseModel):
    name: str
    template_id: Optional[int] = None


class ProjectOut(BaseModel):
    id: int
    name: str
    template_id: int | None
    status: str
    created_at: datetime
    model_config = {"from_attributes": True}


class EntryCreate(BaseModel):
    project_id: int
    element_name: str
    animation_name: str = ""
    looping: bool = False
    duration: str = ""
    description: str = ""
    artist: str = ""
    hours: float = 0.0
    status: str = "Not Started"


class EntryUpdate(BaseModel):
    element_name: Optional[str] = None
    animation_name: Optional[str] = None
    looping: Optional[bool] = None
    duration: Optional[str] = None
    description: Optional[str] = None
    artist: Optional[str] = None
    hours: Optional[float] = None
    image_path: Optional[str] = None
    status: Optional[str] = None


class EntryOut(EntryCreate):
    id: int
    image_path: str = ""
    model_config = {"from_attributes": True}
