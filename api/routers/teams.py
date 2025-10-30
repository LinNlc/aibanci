from __future__ import annotations

from typing import List

from fastapi import APIRouter, Depends

from ..dependencies import get_current_user
from ..models import User
from ..schemas import TeamOut

router = APIRouter(prefix="/teams", tags=["teams"])


@router.get("", response_model=List[TeamOut])
async def list_accessible_teams(user: User = Depends(get_current_user)):
    teams = []
    for perm in sorted(user.team_permissions, key=lambda p: p.team.name):
        teams.append(
            TeamOut(
                id=perm.team.id,
                name=perm.team.name,
                code=perm.team.code,
                description=perm.team.description,
                access_level=perm.access_level,
            )
        )
    return teams
