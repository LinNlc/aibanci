from __future__ import annotations

from datetime import datetime, date

from sqlalchemy import Boolean, Date, DateTime, ForeignKey, Integer, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column, relationship

from .database import Base


class TimestampMixin:
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow, nullable=False)
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow, nullable=False)


class User(Base, TimestampMixin):
    __tablename__ = "users"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    username: Mapped[str] = mapped_column(String(64), unique=True, nullable=False)
    display_name: Mapped[str] = mapped_column(String(128), nullable=False)
    password_hash: Mapped[str] = mapped_column(String(255), nullable=False)
    must_change_password: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    token_version: Mapped[int] = mapped_column(Integer, default=1, nullable=False)

    page_permissions: Mapped[list[UserPagePermission]] = relationship("UserPagePermission", back_populates="user", cascade="all, delete-orphan")
    team_permissions: Mapped[list[UserTeamPermission]] = relationship("UserTeamPermission", back_populates="user", cascade="all, delete-orphan")


class Team(Base, TimestampMixin):
    __tablename__ = "teams"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(128), unique=True, nullable=False)
    code: Mapped[str] = mapped_column(String(64), unique=True, nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)

    people: Mapped[list[Person]] = relationship("Person", back_populates="team", cascade="all, delete-orphan")
    shifts: Mapped[list[ShiftDefinition]] = relationship("ShiftDefinition", back_populates="team", cascade="all, delete-orphan")


class UserPagePermission(Base):
    __tablename__ = "user_page_permissions"
    __table_args__ = (UniqueConstraint("user_id", "page", name="uq_user_page"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    page: Mapped[str] = mapped_column(String(32), nullable=False)
    can_view: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    can_edit: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)

    user: Mapped[User] = relationship("User", back_populates="page_permissions")


class UserTeamPermission(Base):
    __tablename__ = "user_team_permissions"
    __table_args__ = (UniqueConstraint("user_id", "team_id", name="uq_user_team"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    team_id: Mapped[int] = mapped_column(ForeignKey("teams.id", ondelete="CASCADE"), nullable=False)
    access_level: Mapped[str] = mapped_column(String(16), nullable=False)  # read / write

    user: Mapped[User] = relationship("User", back_populates="team_permissions")
    team: Mapped[Team] = relationship("Team")


class ShiftDefinition(Base, TimestampMixin):
    __tablename__ = "shift_definitions"
    __table_args__ = (UniqueConstraint("team_id", "code", name="uq_shift_code"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    team_id: Mapped[int] = mapped_column(ForeignKey("teams.id", ondelete="CASCADE"), nullable=False)
    code: Mapped[str] = mapped_column(String(32), nullable=False)
    display_name: Mapped[str] = mapped_column(String(64), nullable=False)
    bg_color: Mapped[str] = mapped_column(String(16), nullable=False)
    text_color: Mapped[str] = mapped_column(String(16), nullable=False)
    sort_order: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    team: Mapped[Team] = relationship("Team", back_populates="shifts")


class Person(Base, TimestampMixin):
    __tablename__ = "people"
    __table_args__ = (UniqueConstraint("team_id", "name", name="uq_person_name"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    team_id: Mapped[int] = mapped_column(ForeignKey("teams.id", ondelete="CASCADE"), nullable=False)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    show_in_schedule: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    sort_index: Mapped[int] = mapped_column(Integer, default=0, nullable=False)

    team: Mapped[Team] = relationship("Team", back_populates="people")
    schedule_entries: Mapped[list[ScheduleEntry]] = relationship("ScheduleEntry", back_populates="person", cascade="all, delete-orphan")


class ScheduleEntry(Base):
    __tablename__ = "schedule_entries"
    __table_args__ = (UniqueConstraint("team_id", "person_id", "day", name="uq_schedule_cell"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    team_id: Mapped[int] = mapped_column(ForeignKey("teams.id", ondelete="CASCADE"), nullable=False)
    person_id: Mapped[int] = mapped_column(ForeignKey("people.id", ondelete="CASCADE"), nullable=False)
    day: Mapped[date] = mapped_column(Date, nullable=False)
    shift_code: Mapped[str | None] = mapped_column(String(32), nullable=True)
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow, nullable=False)
    updated_by: Mapped[int] = mapped_column(ForeignKey("users.id"), nullable=False)

    person: Mapped[Person] = relationship("Person", back_populates="schedule_entries")
    team: Mapped[Team] = relationship("Team")
    updated_by_user: Mapped[User] = relationship("User")
