from __future__ import annotations

from typing import List

from fastapi import APIRouter, Depends, HTTPException, Response, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..dependencies import ensure_team_access, get_db, require_page_permission
from ..models import Person, User
from ..schemas import PersonCreate, PersonOut, PersonUpdate

router = APIRouter(prefix="/teams/{team_id}/people", tags=["people"])


@router.get("", response_model=List[PersonOut])
async def list_people(
    team_id: int,
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("people")),
):
    ensure_team_access(user, team_id, "read")
    stmt = (
        select(Person)
        .where(Person.team_id == team_id)
        .order_by(Person.sort_index, Person.name)
    )
    people = db.execute(stmt).scalars().all()
    return [PersonOut.from_orm(person) for person in people]


@router.post("", response_model=PersonOut, status_code=status.HTTP_201_CREATED)
async def create_person(
    team_id: int,
    payload: PersonCreate,
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("people", require_edit=True)),
):
    ensure_team_access(user, team_id, "write")
    exists = db.execute(
        select(Person).where(Person.team_id == team_id, Person.name == payload.name)
    ).scalar_one_or_none()
    if exists:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail={"error": "duplicate_person"})
    person = Person(
        team_id=team_id,
        name=payload.name,
        active=payload.active,
        show_in_schedule=payload.show_in_schedule,
        sort_index=payload.sort_index,
    )
    db.add(person)
    db.commit()
    db.refresh(person)
    return PersonOut.from_orm(person)


@router.put("/{person_id}", response_model=PersonOut)
async def update_person(
    team_id: int,
    person_id: int,
    payload: PersonUpdate,
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("people", require_edit=True)),
):
    ensure_team_access(user, team_id, "write")
    person = db.get(Person, person_id)
    if not person or person.team_id != team_id:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail={"error": "not_found"})
    for field, value in payload.dict(exclude_unset=True).items():
        setattr(person, field, value)
    db.add(person)
    db.commit()
    db.refresh(person)
    return PersonOut.from_orm(person)


@router.delete("/{person_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_person(
    team_id: int,
    person_id: int,
    db: Session = Depends(get_db),
    user: User = Depends(require_page_permission("people", require_edit=True)),
):
    ensure_team_access(user, team_id, "write")
    person = db.get(Person, person_id)
    if not person or person.team_id != team_id:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail={"error": "not_found"})
    db.delete(person)
    db.commit()
    return Response(status_code=status.HTTP_204_NO_CONTENT)
