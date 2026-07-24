from sqlalchemy import Column, Integer, String, Float, Boolean, Text, DateTime, ForeignKey
from sqlalchemy.orm import relationship
from datetime import datetime, timezone

from database import Base


class Blueprint(Base):
    __tablename__ = "blueprints"
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String, unique=True, nullable=False)
    description = Column(Text, default="")
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
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
    name = Column(String, nullable=False)
    description = Column(Text, default="")
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
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
    name = Column(String, nullable=False)
    template_id = Column(Integer, ForeignKey("templates.id"), nullable=True)
    status = Column(String, default="active")
    created_at = Column(DateTime, default=lambda: datetime.now(timezone.utc))
    entries = relationship("Entry", back_populates="project", cascade="all, delete-orphan",
                           order_by="Entry.element_name, Entry.id")


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
    hours = Column(Float, default=0.0)
    image_path = Column(String, default="")
    status = Column(String, default="Not Started")
    project = relationship("Project", back_populates="entries")
