from __future__ import annotations

import csv
import io
from datetime import date, datetime, timedelta
from typing import List

from fastapi import APIRouter, Depends, HTTPException, Query, Response, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..dependencies import ensure_team_access, get_db, require_page_permission
from ..models import Person, ScheduleEntry, ShiftDefinition, User
from ..schemas import (
    ScheduleResponse,
    ScheduleUpdateRequest,
    ScheduleUpdateResponse,
    ScheduleDay,
    ScheduleCell,
    ShiftDefinitionOut,
    PersonOut,
    TeamOut,
)
from ..utils import weekday_name

router = APIRouter(prefix="/schedule", tags=["schedule"])


def _collect_people(db: Session, team_id: int) -> List[Person]:
    stmt = (
        select(Person)
        .where(
            Person.team_id == team_id,
            Person.active.is_(True),
            Person.show_in_schedule.is_(True),
        )
        .order_by(Person.sort_index, Person.name)
    )
    return db.execute(stmt).scalars().all()


def _collect_shifts(db: Session, team_id: int) -> List[ShiftDefinition]:
    stmt = (
        select(ShiftDefinition)
        .where(ShiftDefinition.team_id == team_id)
        .order_by(ShiftDefinition.sort_order, ShiftDefinition.id)
    )
    return db.execute(stmt).scalars().all()


@router.get("", response_model=ScheduleResponse)
async def read_schedule(
    team_id: int = Query(..., ge=1),
    start: date = Query(...),
    end: date = Query(...),
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("schedule")),
):
    level = ensure_team_access(user, team_id, "read")
    if start > end:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail={"error": "invalid_range"})
    people = _collect_people(db, team_id)
    shifts = _collect_shifts(db, team_id)
    if people:
        entries_stmt = (
            select(ScheduleEntry)
            .where(
                ScheduleEntry.team_id == team_id,
                ScheduleEntry.day >= start,
                ScheduleEntry.day <= end,
                ScheduleEntry.person_id.in_([p.id for p in people]),
            )
        )
        entries = db.execute(entries_stmt).scalars().all()
    else:
        entries = []
    entries_lookup = {(entry.person_id, entry.day): entry for entry in entries}

    days: List[ScheduleDay] = []
    total_days = (end - start).days + 1
    person_out = [PersonOut.from_orm(p) for p in people]
    shift_out = [ShiftDefinitionOut.from_orm(s) for s in shifts]

    perm = next((perm for perm in user.page_permissions if perm.page == "schedule"), None)
    can_edit = bool(perm and perm.can_edit)
    read_only = level != "write" or not can_edit

    for offset in range(total_days):
        current = start + timedelta(days=offset)
        assignments = []
        for person in people:
            entry = entries_lookup.get((person.id, current))
            assignments.append(
                ScheduleCell(person_id=person.id, shift_code=entry.shift_code if entry else None)
            )
        days.append(ScheduleDay(date=current, weekday=weekday_name(current), assignments=assignments))

    team_info = next((perm.team for perm in user.team_permissions if perm.team_id == team_id), None)
    if not team_info:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail={"error": "not_found"})

    return ScheduleResponse(
        team=TeamOut(
            id=team_info.id,
            name=team_info.name,
            code=team_info.code,
            description=team_info.description,
            access_level=level,
        ),
        start=start,
        end=end,
        days=days,
        people=person_out,
        shifts=shift_out,
        read_only=read_only,
    )


@router.put("/cell", response_model=ScheduleUpdateResponse)
async def update_cell(
    payload: ScheduleUpdateRequest,
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("schedule", require_edit=True)),
):
    ensure_team_access(user, payload.team_id, "write")
    person = db.get(Person, payload.person_id)
    if not person or person.team_id != payload.team_id:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail={"error": "not_found"})
    shift_code = payload.shift_code or None
    if shift_code:
        shift_exists = db.execute(
            select(ShiftDefinition).where(
                ShiftDefinition.team_id == payload.team_id,
                ShiftDefinition.code == shift_code,
                ShiftDefinition.is_active.is_(True),
            )
        ).scalar_one_or_none()
        if not shift_exists:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail={"error": "invalid_shift"})
    entry = db.execute(
        select(ScheduleEntry).where(
            ScheduleEntry.team_id == payload.team_id,
            ScheduleEntry.person_id == payload.person_id,
            ScheduleEntry.day == payload.day,
        )
    ).scalar_one_or_none()
    if not shift_code and entry:
        db.delete(entry)
        db.commit()
        updated_at = datetime.utcnow()
        return ScheduleUpdateResponse(
            person_id=payload.person_id,
            day=payload.day,
            shift_code=None,
            updated_at=updated_at,
            updated_by=user.id,
        )
    if not entry:
        entry = ScheduleEntry(
            team_id=payload.team_id,
            person_id=payload.person_id,
            day=payload.day,
            updated_by=user.id,
        )
    entry.shift_code = shift_code
    entry.updated_by = user.id
    db.add(entry)
    db.commit()
    db.refresh(entry)
    return ScheduleUpdateResponse(
        person_id=entry.person_id,
        day=entry.day,
        shift_code=entry.shift_code,
        updated_at=entry.updated_at,
        updated_by=entry.updated_by,
    )


@router.get("/export")
async def export_schedule(
    team_id: int = Query(..., ge=1),
    start: date = Query(...),
    end: date = Query(...),
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("schedule")),
):
    ensure_team_access(user, team_id, "read")
    if start > end:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail={"error": "invalid_range"})
    people = _collect_people(db, team_id)
    shifts = {shift.code: shift for shift in _collect_shifts(db, team_id)}
    if people:
        entries_stmt = (
            select(ScheduleEntry)
            .where(
                ScheduleEntry.team_id == team_id,
                ScheduleEntry.day >= start,
                ScheduleEntry.day <= end,
                ScheduleEntry.person_id.in_([p.id for p in people]),
            )
        )
        entries = db.execute(entries_stmt).scalars().all()
        lookup = {(entry.person_id, entry.day): entry for entry in entries}
    else:
        lookup = {}

    output = io.StringIO()
    writer = csv.writer(output)
    header = ["日期", "星期"] + [person.name for person in people]
    writer.writerow(header)
    total_days = (end - start).days + 1
    for offset in range(total_days):
        current = start + timedelta(days=offset)
        row = [current.isoformat(), weekday_name(current)]
        for person in people:
            entry = lookup.get((person.id, current))
            if entry and entry.shift_code:
                shift = shifts.get(entry.shift_code)
                row.append(shift.display_name if shift else entry.shift_code)
            else:
                row.append("")
        writer.writerow(row)
    csv_content = output.getvalue()
    filename = f"schedule_{team_id}_{start}_{end}.csv"
    return Response(
        content=csv_content,
        media_type="text/csv; charset=utf-8",
        headers={"Content-Disposition": f"attachment; filename={filename}"},
    )
