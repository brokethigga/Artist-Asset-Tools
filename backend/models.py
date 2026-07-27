from sqlalchemy import Column, Integer, String, Float, Boolean, Text, DateTime, Date, ForeignKey
from sqlalchemy.orm import relationship
from datetime import datetime, timezone

from database import Base


class Organization(Base):
    __tablename__ = "organizations"
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String, nullable=False)
    plan = Column(String, default="internal")
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
    users = relationship("User", back_populates="organization")
    blueprints = relationship("Blueprint", back_populates="organization")
    templates = relationship("Template", back_populates="organization")
    projects = relationship("Project", back_populates="organization")


class User(Base):
    __tablename__ = "users"
    id = Column(Integer, primary_key=True, index=True)
    organization_id = Column(Integer, ForeignKey("organizations.id"), nullable=False)
    email = Column(String, nullable=False)
    name = Column(String, default="")
    google_sub = Column(String, unique=True, nullable=False)
    role = Column(String, default="artist")
    approved = Column(Boolean, default=False)
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
    last_login = Column(DateTime, nullable=True)
    organization = relationship("Organization", back_populates="users")
    comments = relationship("Comment", back_populates="author")


class Blueprint(Base):
    __tablename__ = "blueprints"
    id = Column(Integer, primary_key=True, index=True)
    organization_id = Column(Integer, ForeignKey("organizations.id"), nullable=False)
    name = Column(String, nullable=False)
    description = Column(Text, default="")
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
    organization = relationship("Organization", back_populates="blueprints")
    states = relationship("BlueprintState", back_populates="blueprint", cascade="all, delete-orphan",
                          order_by="BlueprintState.id")


class BlueprintState(Base):
    __tablename__ = "blueprint_states"
    id = Column(Integer, primary_key=True, index=True)
    blueprint_id = Column(Integer, ForeignKey("blueprints.id"), nullable=False)
    name = Column(String, nullable=False)
    default_looping = Column(Boolean, default=False)
    default_duration = Column(String, default="")
    default_description = Column(Text, default="")
    blueprint = relationship("Blueprint", back_populates="states")


class Template(Base):
    __tablename__ = "templates"
    id = Column(Integer, primary_key=True, index=True)
    organization_id = Column(Integer, ForeignKey("organizations.id"), nullable=False)
    name = Column(String, nullable=False)
    description = Column(Text, default="")
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
    organization = relationship("Organization", back_populates="templates")
    blueprints = relationship("TemplateBlueprint", back_populates="template", cascade="all, delete-orphan",
                              order_by="TemplateBlueprint.sort_order")


class TemplateBlueprint(Base):
    __tablename__ = "template_blueprints"
    id = Column(Integer, primary_key=True, index=True)
    template_id = Column(Integer, ForeignKey("templates.id"), nullable=False)
    blueprint_id = Column(Integer, ForeignKey("blueprints.id"), nullable=False)
    sort_order = Column(Integer, default=0)
    template = relationship("Template", back_populates="blueprints")
    blueprint = relationship("Blueprint")


class Project(Base):
    __tablename__ = "projects"
    id = Column(Integer, primary_key=True, index=True)
    organization_id = Column(Integer, ForeignKey("organizations.id"), nullable=False)
    name = Column(String, nullable=False)
    template_id = Column(Integer, ForeignKey("templates.id"), nullable=True)
    status = Column(String, default="active")
    game_type = Column(String, default="")
    customer = Column(String, default="")
    deadline = Column(Date, nullable=True)
    summary = Column(Text, nullable=True)
    asset_link = Column(String, nullable=True)
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
    organization = relationship("Organization", back_populates="projects")
    entries = relationship("Entry", back_populates="project", cascade="all, delete-orphan",
                           order_by="Entry.element_name, Entry.id")
    comments = relationship("Comment", back_populates="project", cascade="all, delete-orphan")


class Entry(Base):
    __tablename__ = "entries"
    id = Column(Integer, primary_key=True, index=True)
    project_id = Column(Integer, ForeignKey("projects.id"), nullable=False)
    element_name = Column(String, nullable=False, index=True)
    animation_name = Column(String, default="")
    looping = Column(Boolean, default=False)
    duration = Column(String, default="")
    description = Column(Text, default="")
    artist = Column(String, default="")
    projected_hours = Column(Float, default=0.0)
    actual_hours = Column(Float, default=0.0)
    priority = Column(String, default="Medium")
    phase = Column(String, nullable=True)
    alert_flag = Column(Boolean, default=False)
    alert_flag_reason = Column(String, nullable=True)
    image_path = Column(String, default="")
    status = Column(String, default="Not Started")
    project = relationship("Project", back_populates="entries")
    images = relationship("EntryImage", back_populates="entry", cascade="all, delete-orphan",
                          order_by="EntryImage.sort_order")


class EntryImage(Base):
    __tablename__ = "entry_images"
    id = Column(Integer, primary_key=True, index=True)
    entry_id = Column(Integer, ForeignKey("entries.id"), nullable=False)
    image_path = Column(String, nullable=False)
    sort_order = Column(Integer, default=0)
    uploaded_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
    entry = relationship("Entry", back_populates="images")


class Tag(Base):
    __tablename__ = "tags"
    id = Column(Integer, primary_key=True, index=True)
    project_id = Column(Integer, ForeignKey("projects.id"), nullable=False)
    name = Column(String, nullable=False)


class Comment(Base):
    __tablename__ = "comments"
    id = Column(Integer, primary_key=True, index=True)
    project_id = Column(Integer, ForeignKey("projects.id"), nullable=False)
    entry_id = Column(Integer, ForeignKey("entries.id"), nullable=True)
    author_id = Column(Integer, ForeignKey("users.id"), nullable=False)
    body = Column(Text, nullable=False)
    linked_comment_id = Column(Integer, ForeignKey("comments.id"), nullable=True)
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
    project = relationship("Project", back_populates="comments")
    author = relationship("User", back_populates="comments")
    linked_comment = relationship("Comment", remote_side="Comment.id", backref="linked_from")


class InviteLink(Base):
    __tablename__ = "invite_links"
    id = Column(Integer, primary_key=True, index=True)
    organization_id = Column(Integer, ForeignKey("organizations.id"), nullable=False)
    token = Column(String, unique=True, nullable=False)
    email_optional = Column(String, nullable=True)
    expires_at = Column(DateTime, nullable=False)
    used_at = Column(DateTime, nullable=True)
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
    organization = relationship("Organization")
