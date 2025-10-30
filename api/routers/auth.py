from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Response, status
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from ..config import load_config
from ..dependencies import get_current_user, get_db
from ..models import User, UserTeamPermission
from ..schemas import (
    FirstLoginRequest,
    LoginRequest,
    LoginResponse,
    PagePermission,
    TeamPermission,
    UserInfo,
)
from ..security import create_session_token, hash_password, verify_password

router = APIRouter(prefix="/auth", tags=["auth"])
_config = load_config()


def serialize_user(user: User) -> UserInfo:
    pages = [
        PagePermission(page=perm.page, can_view=perm.can_view, can_edit=perm.can_edit)
        for perm in sorted(user.page_permissions, key=lambda p: p.page)
    ]
    teams = [
        TeamPermission(team_id=perm.team_id, team_name=perm.team.name, access_level=perm.access_level)
        for perm in sorted(user.team_permissions, key=lambda p: p.team.name)
    ]
    return UserInfo(
        id=user.id,
        username=user.username,
        display_name=user.display_name,
        must_change_password=user.must_change_password,
        pages=pages,
        teams=teams,
    )


@router.post("/login", response_model=LoginResponse)
async def login(request: LoginRequest, response: Response, db: Session = Depends(get_db)):
    stmt = (
        select(User)
        .options(
            selectinload(User.page_permissions),
            selectinload(User.team_permissions).selectinload(UserTeamPermission.team),
        )
        .where(User.username == request.username)
    )
    user = db.execute(stmt).scalar_one_or_none()
    if not user or not user.is_active:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail={"error": "unauthenticated"})
    if not verify_password(request.password, user.password_hash):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail={"error": "unauthenticated"})
    if user.must_change_password:
        return LoginResponse(must_change_password=True)
    token = create_session_token(user.id, user.token_version)
    response.set_cookie(
        "session_token",
        token,
        httponly=True,
        secure=False,
        max_age=_config.session_max_age,
        samesite="lax",
    )
    return LoginResponse(must_change_password=False, user=serialize_user(user))


@router.post("/first-login", response_model=LoginResponse)
async def first_login(payload: FirstLoginRequest, response: Response, db: Session = Depends(get_db)):
    stmt = (
        select(User)
        .options(
            selectinload(User.page_permissions),
            selectinload(User.team_permissions).selectinload(UserTeamPermission.team),
        )
        .where(User.username == payload.username)
    )
    user = db.execute(stmt).scalar_one_or_none()
    if not user or not user.is_active:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail={"error": "unauthenticated"})
    if not user.must_change_password:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail={"error": "invalid_state"})
    if not verify_password(payload.current_password, user.password_hash):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail={"error": "unauthenticated"})
    user.password_hash = hash_password(payload.new_password)
    user.must_change_password = False
    user.token_version += 1
    db.add(user)
    db.commit()
    db.refresh(user)
    token = create_session_token(user.id, user.token_version)
    response.set_cookie(
        "session_token",
        token,
        httponly=True,
        secure=False,
        max_age=_config.session_max_age,
        samesite="lax",
    )
    return LoginResponse(user=serialize_user(user))


@router.post("/logout")
async def logout(response: Response):
    response.delete_cookie("session_token")
    return {"success": True}


@router.get("/me", response_model=UserInfo)
async def read_me(user: User = Depends(get_current_user)):
    return serialize_user(user)
