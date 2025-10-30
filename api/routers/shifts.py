from __future__ import annotations

from typing import List

from fastapi import APIRouter, Depends, HTTPException, Response, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..dependencies import ensure_team_access, get_db, require_page_permission
from ..models import ShiftDefinition, User
from ..schemas import ShiftDefinitionCreate, ShiftDefinitionOut, ShiftDefinitionUpdate

router = APIRouter(prefix="/teams/{team_id}/shifts", tags=["shifts"])


@router.get("", response_model=List[ShiftDefinitionOut])
async def list_shifts(
    team_id: int,
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("settings")),
):
    ensure_team_access(user, team_id, "read")
    stmt = (
        select(ShiftDefinition)
        .where(ShiftDefinition.team_id == team_id)
        .order_by(ShiftDefinition.sort_order, ShiftDefinition.id)
    )
    shifts = db.execute(stmt).scalars().all()
    return [
        ShiftDefinitionOut(
            id=shift.id,
            code=shift.code,
            display_name=shift.display_name,
            bg_color=shift.bg_color,
            text_color=shift.text_color,
            sort_order=shift.sort_order,
            is_active=shift.is_active,
        )
        for shift in shifts
    ]


@router.post("", response_model=ShiftDefinitionOut, status_code=status.HTTP_201_CREATED)
async def create_shift(
    team_id: int,
    payload: ShiftDefinitionCreate,
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("settings", require_edit=True)),
):
    ensure_team_access(user, team_id, "write")
    exists = db.execute(
        select(ShiftDefinition).where(
            ShiftDefinition.team_id == team_id, ShiftDefinition.code == payload.code
        )
    ).scalar_one_or_none()
    if exists:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail={"error": "duplicate_shift_code"})
    shift = ShiftDefinition(
        team_id=team_id,
        code=payload.code,
        display_name=payload.display_name,
        bg_color=payload.bg_color,
        text_color=payload.text_color,
        sort_order=payload.sort_order,
        is_active=payload.is_active,
    )
    db.add(shift)
    db.commit()
    db.refresh(shift)
    return ShiftDefinitionOut.from_orm(shift)


@router.put("/{shift_id}", response_model=ShiftDefinitionOut)
async def update_shift(
    team_id: int,
    shift_id: int,
    payload: ShiftDefinitionUpdate,
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("settings", require_edit=True)),
):
    ensure_team_access(user, team_id, "write")
    shift = db.get(ShiftDefinition, shift_id)
    if not shift or shift.team_id != team_id:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail={"error": "not_found"})
    for field, value in payload.dict(exclude_unset=True).items():
        setattr(shift, field, value)
    db.add(shift)
    db.commit()
    db.refresh(shift)
    return ShiftDefinitionOut.from_orm(shift)


@router.delete("/{shift_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_shift(
    team_id: int,
    shift_id: int,
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("settings", require_edit=True)),
):
    ensure_team_access(user, team_id, "write")
    shift = db.get(ShiftDefinition, shift_id)
    if not shift or shift.team_id != team_id:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail={"error": "not_found"})
    db.delete(shift)
    db.commit()
    return Response(status_code=status.HTTP_204_NO_CONTENT)
