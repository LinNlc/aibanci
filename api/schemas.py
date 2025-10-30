from __future__ import annotations

from datetime import date, datetime
from typing import List, Optional

from pydantic import BaseModel, Field


class PagePermission(BaseModel):
    page: str
    can_view: bool = False
    can_edit: bool = False


class TeamPermission(BaseModel):
    team_id: int
    team_name: str
    access_level: str


class UserInfo(BaseModel):
    id: int
    username: str
    display_name: str
    must_change_password: bool = False
    pages: List[PagePermission]
    teams: List[TeamPermission]

    class Config:
        orm_mode = True


class LoginRequest(BaseModel):
    username: str
    password: str


class LoginResponse(BaseModel):
    must_change_password: bool = False
    user: Optional[UserInfo] = None


class FirstLoginRequest(BaseModel):
    username: str
    current_password: str
    new_password: str = Field(min_length=8)


class ChangePasswordRequest(BaseModel):
    new_password: str = Field(min_length=8)


class TeamOut(BaseModel):
    id: int
    name: str
    code: str
    description: Optional[str]
    access_level: Optional[str] = None

    class Config:
        orm_mode = True


class ShiftDefinitionOut(BaseModel):
    id: int
    code: str
    display_name: str
    bg_color: str
    text_color: str
    sort_order: int
    is_active: bool

    class Config:
        orm_mode = True


class ShiftDefinitionCreate(BaseModel):
    code: str
    display_name: str
    bg_color: str
    text_color: str
    sort_order: int = 0
    is_active: bool = True


class ShiftDefinitionUpdate(BaseModel):
    display_name: Optional[str]
    bg_color: Optional[str]
    text_color: Optional[str]
    sort_order: Optional[int]
    is_active: Optional[bool]


class PersonOut(BaseModel):
    id: int
    name: str
    active: bool
    show_in_schedule: bool
    sort_index: int

    class Config:
        orm_mode = True


class PersonCreate(BaseModel):
    name: str
    active: bool = True
    show_in_schedule: bool = True
    sort_index: int = 0


class PersonUpdate(BaseModel):
    name: Optional[str]
    active: Optional[bool]
    show_in_schedule: Optional[bool]
    sort_index: Optional[int]


class ScheduleCell(BaseModel):
    person_id: int
    shift_code: Optional[str]


class ScheduleDay(BaseModel):
    date: date
    weekday: str
    assignments: List[ScheduleCell]


class ScheduleResponse(BaseModel):
    team: TeamOut
    start: date
    end: date
    days: List[ScheduleDay]
    people: List[PersonOut]
    shifts: List[ShiftDefinitionOut]
    read_only: bool


class ScheduleUpdateRequest(BaseModel):
    team_id: int
    person_id: int
    day: date
    shift_code: Optional[str] = None


class ScheduleUpdateResponse(BaseModel):
    person_id: int
    day: date
    shift_code: Optional[str]
    updated_at: datetime
    updated_by: int


class PermissionPageInput(BaseModel):
    page: str
    can_view: bool
    can_edit: bool


class PermissionTeamInput(BaseModel):
    team_id: int
    access_level: Optional[str]


class UserPermissionUpdate(BaseModel):
    display_name: Optional[str]
    pages: List[PermissionPageInput]
    teams: List[PermissionTeamInput]
    new_password: Optional[str] = Field(default=None, min_length=8)


class UserCreateRequest(BaseModel):
    username: str
    display_name: str
    password: str = Field(min_length=8)
    must_change_password: bool = True
    pages: List[PermissionPageInput] = Field(default_factory=list)
    teams: List[PermissionTeamInput] = Field(default_factory=list)


class UserWithPermissions(UserInfo):
    is_active: bool


class PermissionOverview(BaseModel):
    users: List[UserWithPermissions]
    teams: List[TeamOut]


class ApiError(BaseModel):
    error: str
    message: Optional[str] = None
