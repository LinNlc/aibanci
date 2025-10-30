from __future__ import annotations

from fastapi import Depends, HTTPException, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session, joinedload, selectinload

from .database import SessionLocal
from .models import User, UserPagePermission, UserTeamPermission
from .security import decode_session_token


async def get_db() -> Session:
    session = SessionLocal()
    try:
        yield session
    finally:
        session.close()


async def get_current_user(request: Request, db: Session = Depends(get_db)) -> User:
    token = request.cookies.get("session_token")
    if not token:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail={"error": "unauthenticated"})
    payload = decode_session_token(token)
    if not payload:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail={"error": "unauthenticated"})
    stmt = (
        select(User)
        .options(
            selectinload(User.page_permissions),
            selectinload(User.team_permissions).joinedload(UserTeamPermission.team),
        )
        .where(User.id == payload["user_id"])
    )
    user = db.execute(stmt).scalar_one_or_none()
    if not user or not user.is_active or user.token_version != payload.get("token_version"):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail={"error": "unauthenticated"})
    return user


def _page_permission_lookup(user: User) -> dict[str, UserPagePermission]:
    return {perm.page: perm for perm in user.page_permissions}


def require_page_permission(page: str, require_edit: bool = False):
    def dependency(user: User = Depends(get_current_user)) -> User:
        lookup = _page_permission_lookup(user)
        perm = lookup.get(page)
        if not perm or not perm.can_view:
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail={"error": "forbidden"})
        if require_edit and not perm.can_edit:
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail={"error": "forbidden"})
        return user

    return dependency


def ensure_team_access(user: User, team_id: int, min_level: str) -> str:
    levels = {perm.team_id: perm.access_level for perm in user.team_permissions}
    level = levels.get(team_id)
    allowed = False
    if min_level == "read" and level in {"read", "write"}:
        allowed = True
    elif min_level == "write" and level == "write":
        allowed = True
    if not allowed:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail={"error": "forbidden"})
    return level  # type: ignore
