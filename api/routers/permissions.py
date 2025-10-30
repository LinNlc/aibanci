from __future__ import annotations

from typing import Dict

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from ..dependencies import get_db, require_page_permission
from ..models import Team, User, UserPagePermission, UserTeamPermission
from ..schemas import (
    PagePermission,
    PermissionOverview,
    PermissionPageInput,
    PermissionTeamInput,
    TeamOut,
    TeamPermission,
    UserCreateRequest,
    UserPermissionUpdate,
    UserWithPermissions,
)
from ..security import hash_password

router = APIRouter(prefix="/permissions", tags=["permissions"])

VALID_PAGES = {"schedule", "settings", "permissions", "people"}
VALID_LEVELS = {"read", "write"}


def _serialize_user(user: User) -> UserWithPermissions:
    pages = [
        PagePermission(page=perm.page, can_view=perm.can_view, can_edit=perm.can_edit)
        for perm in sorted(user.page_permissions, key=lambda p: p.page)
    ]
    teams = [
        TeamPermission(team_id=perm.team_id, team_name=perm.team.name, access_level=perm.access_level)
        for perm in sorted(user.team_permissions, key=lambda p: p.team.name)
    ]
    return UserWithPermissions(
        id=user.id,
        username=user.username,
        display_name=user.display_name,
        must_change_password=user.must_change_password,
        pages=pages,
        teams=teams,
        is_active=user.is_active,
    )


@router.get("/overview", response_model=PermissionOverview)
async def permission_overview(
    db: Session = Depends(get_db),
    _: User = Depends(require_page_permission("permissions")),
):
    users = (
        db.execute(
            select(User)
            .options(
                selectinload(User.page_permissions),
                selectinload(User.team_permissions).selectinload(UserTeamPermission.team),
            )
            .order_by(User.username)
        )
        .scalars()
        .all()
    )
    teams = db.execute(select(Team).order_by(Team.name)).scalars().all()
    return PermissionOverview(
        users=[_serialize_user(user) for user in users],
        teams=[
            TeamOut(id=team.id, name=team.name, code=team.code, description=team.description, access_level=None)
            for team in teams
        ],
    )


@router.post("/users", response_model=UserWithPermissions, status_code=status.HTTP_201_CREATED)
async def create_user(
    payload: UserCreateRequest,
    db: Session = Depends(get_db),
    _: User = Depends(require_page_permission("permissions", require_edit=True)),
):
    existing = db.execute(select(User).where(User.username == payload.username)).scalar_one_or_none()
    if existing:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail={"error": "duplicate_username"})
    user = User(
        username=payload.username,
        display_name=payload.display_name,
        password_hash=hash_password(payload.password),
        must_change_password=payload.must_change_password,
    )
    db.add(user)
    db.commit()
    db.refresh(user)
    if payload.pages or payload.teams:
        update_payload = UserPermissionUpdate(
            display_name=user.display_name,
            pages=payload.pages,
            teams=payload.teams,
        )
        user = _apply_permission_update(db, user.id, update_payload)
    return _serialize_user(user)


@router.put("/users/{user_id}", response_model=UserWithPermissions)
async def update_user(
    user_id: int,
    payload: UserPermissionUpdate,
    db: Session = Depends(get_db),
    _: User = Depends(require_page_permission("permissions", require_edit=True)),
):
    user = _apply_permission_update(db, user_id, payload)
    return _serialize_user(user)


def _apply_permission_update(db: Session, user_id: int, payload: UserPermissionUpdate) -> User:
    user = db.execute(
        select(User)
        .options(
            selectinload(User.page_permissions),
            selectinload(User.team_permissions).selectinload(UserTeamPermission.team),
        )
        .where(User.id == user_id)
    ).scalar_one_or_none()
    if not user:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail={"error": "not_found"})

    if payload.display_name is not None:
        user.display_name = payload.display_name

    if payload.new_password:
        user.password_hash = hash_password(payload.new_password)
        user.must_change_password = False
        user.token_version += 1

    pages_by_key: Dict[str, UserPagePermission] = {perm.page: perm for perm in user.page_permissions}
    incoming_pages = {item.page: item for item in payload.pages}
    for page_key, perm in list(pages_by_key.items()):
        if page_key not in incoming_pages:
            user.page_permissions.remove(perm)
            db.delete(perm)
    for item in payload.pages:
        if item.page not in VALID_PAGES:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail={"error": "invalid_page"})
        can_view = item.can_view or item.can_edit
        can_edit = item.can_edit and can_view
        existing = pages_by_key.get(item.page)
        if existing:
            existing.can_view = can_view
            existing.can_edit = can_edit
        else:
            user.page_permissions.append(
                UserPagePermission(page=item.page, can_view=can_view, can_edit=can_edit)
            )

    team_lookup: Dict[int, UserTeamPermission] = {perm.team_id: perm for perm in user.team_permissions}
    incoming_teams = {item.team_id: item for item in payload.teams}
    for team_id, perm in list(team_lookup.items()):
        if team_id not in incoming_teams or incoming_teams[team_id].access_level is None:
            user.team_permissions.remove(perm)
            db.delete(perm)
    for item in payload.teams:
        if item.access_level is None:
            continue
        if item.access_level not in VALID_LEVELS:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail={"error": "invalid_access_level"})
        team = db.get(Team, item.team_id)
        if not team:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail={"error": "team_not_found"})
        existing = team_lookup.get(item.team_id)
        if existing:
            existing.access_level = item.access_level
        else:
            user.team_permissions.append(
                UserTeamPermission(team_id=item.team_id, access_level=item.access_level)
            )

    db.add(user)
    db.commit()
    db.refresh(user)
    return user
