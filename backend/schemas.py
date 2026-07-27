from pydantic import BaseModel
from typing import Optional
from datetime import datetime, date


class OrganizationCreate(BaseModel):
    name: str


class OrganizationOut(BaseModel):
    id: int
    name: str
    plan: str
    created_at: datetime
    model_config = {"from_attributes": True}


class UserOut(BaseModel):
    id: int
    organization_id: int
    email: str
    name: str
    role: str
    approved: bool
    created_at: datetime
    last_login: Optional[datetime] = None
    model_config = {"from_attributes": True}


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
    organization_id: int
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
    organization_id: int
    name: str
    description: str
    created_at: datetime
    blueprints: list[TemplateBlueprintOut] = []
    model_config = {"from_attributes": True}


class ProjectCreate(BaseModel):
    name: str
    template_id: Optional[int] = None
    game_type: str = ""
    customer: str = ""
    deadline: Optional[date] = None
    asset_link: Optional[str] = None


class ProjectOut(BaseModel):
    id: int
    organization_id: int
    name: str
    template_id: int | None
    status: str
    game_type: str
    customer: str
    deadline: Optional[date]
    summary: Optional[str]
    asset_link: Optional[str]
    created_at: datetime
    model_config = {"from_attributes": True}


class ProjectUpdate(BaseModel):
    name: Optional[str] = None
    status: Optional[str] = None
    game_type: Optional[str] = None
    customer: Optional[str] = None
    deadline: Optional[date] = None
    summary: Optional[str] = None
    asset_link: Optional[str] = None


class TagOut(BaseModel):
    id: int
    project_id: int
    name: str
    model_config = {"from_attributes": True}


class TagCreate(BaseModel):
    name: str


class EntryCreate(BaseModel):
    project_id: int
    element_name: str
    animation_name: str = ""
    looping: bool = False
    duration: str = ""
    description: str = ""
    artist: str = ""
    projected_hours: float = 0.0
    actual_hours: float = 0.0
    priority: str = "Medium"
    phase: str = "Animating"
    status: str = "Not Started"
    asset_link: str = ""


class EntryUpdate(BaseModel):
    element_name: Optional[str] = None
    animation_name: Optional[str] = None
    looping: Optional[bool] = None
    duration: Optional[str] = None
    description: Optional[str] = None
    artist: Optional[str] = None
    projected_hours: Optional[float] = None
    actual_hours: Optional[float] = None
    priority: Optional[str] = None
    phase: Optional[str] = None
    alert_flag: Optional[bool] = None
    alert_flag_reason: Optional[str] = None
    image_path: Optional[str] = None
    status: Optional[str] = None
    asset_link: Optional[str] = None


class EntryOut(BaseModel):
    id: int
    project_id: int
    element_name: str
    animation_name: str
    looping: bool
    duration: str
    description: str
    artist: str
    projected_hours: float
    actual_hours: float
    priority: str
    phase: Optional[str] = None
    alert_flag: bool
    alert_flag_reason: Optional[str]
    image_path: str
    status: str
    asset_link: Optional[str] = None
    model_config = {"from_attributes": True}


class EntryImageOut(BaseModel):
    id: int
    entry_id: int
    image_path: str
    sort_order: int
    uploaded_at: datetime
    model_config = {"from_attributes": True}


class CommentCreate(BaseModel):
    body: str
    entry_id: Optional[int] = None
    linked_comment_id: Optional[int] = None


class CommentOut(BaseModel):
    id: int
    project_id: int
    entry_id: Optional[int]
    author_id: int
    body: str
    linked_comment_id: Optional[int]
    created_at: datetime
    author: Optional[UserOut] = None
    model_config = {"from_attributes": True}


class InviteLinkCreate(BaseModel):
    email_optional: Optional[str] = None


class InviteLinkOut(BaseModel):
    id: int
    organization_id: int
    token: str
    email_optional: Optional[str]
    expires_at: datetime
    used_at: Optional[datetime]
    created_at: datetime
    model_config = {"from_attributes": True}
