from __future__ import annotations

import argparse
from datetime import date, timedelta

from sqlalchemy import select

from .config import load_config
from .database import Base, engine, session_scope
from .models import (
    Person,
    ScheduleEntry,
    ShiftDefinition,
    Team,
    User,
    UserPagePermission,
    UserTeamPermission,
)
from .security import hash_password


def init_database() -> None:
    config = load_config()
    db_path = config.database_path
    if db_path.exists():
        print(f"Using database at {db_path}")
    else:
        if db_path.parent and not db_path.parent.exists():
            db_path.parent.mkdir(parents=True, exist_ok=True)
        print(f"Creating database at {db_path}")
    Base.metadata.create_all(bind=engine)

    with session_scope() as session:
        existing_users = session.execute(select(User).limit(1)).scalar_one_or_none()
        if existing_users:
            print("Database already initialized; skipping demo data.")
            return

        team_ops = Team(name="运营一组", code="ops", description="主要负责日常运营")
        team_support = Team(name="客服组", code="support", description="客户支持团队")
        session.add_all([team_ops, team_support])
        session.flush()

        shifts_ops = [
            ("DAY", "白班", "#facc15", "#1f2937", 1),
            ("SWING", "中班", "#60a5fa", "#0f172a", 2),
            ("NIGHT", "夜班", "#818cf8", "#111827", 3),
            ("OFF", "休息", "#d1d5db", "#374151", 4),
        ]
        shifts_support = [
            ("MORNING", "早班", "#34d399", "#064e3b", 1),
            ("EVENING", "晚班", "#f472b6", "#831843", 2),
            ("OFF", "休息", "#d1d5db", "#374151", 3),
        ]
        for code, name, bg, text, order in shifts_ops:
            session.add(
                ShiftDefinition(
                    team_id=team_ops.id,
                    code=code,
                    display_name=name,
                    bg_color=bg,
                    text_color=text,
                    sort_order=order,
                )
            )
        for code, name, bg, text, order in shifts_support:
            session.add(
                ShiftDefinition(
                    team_id=team_support.id,
                    code=code,
                    display_name=name,
                    bg_color=bg,
                    text_color=text,
                    sort_order=order,
                )
            )
        session.flush()

        people_ops = [
            ("张三", 1),
            ("李四", 2),
            ("王五", 3),
        ]
        people_support = [
            ("Alice", 1),
            ("Bob", 2),
            ("Carol", 3),
        ]
        ops_people_models = []
        for name, idx in people_ops:
            person = Person(team_id=team_ops.id, name=name, sort_index=idx)
            session.add(person)
            session.flush()
            ops_people_models.append(person)
        support_people_models = []
        for name, idx in people_support:
            person = Person(team_id=team_support.id, name=name, sort_index=idx)
            session.add(person)
            session.flush()
            support_people_models.append(person)

        admin = User(
            username="admin",
            display_name="超级管理员",
            password_hash=hash_password("admin"),
            must_change_password=True,
        )
        planner = User(
            username="planner",
            display_name="排班专员",
            password_hash=hash_password("planner123"),
            must_change_password=False,
        )
        viewer = User(
            username="viewer",
            display_name="排班观察员",
            password_hash=hash_password("viewer123"),
            must_change_password=False,
        )
        session.add_all([admin, planner, viewer])
        session.flush()

        valid_pages = ["schedule", "settings", "permissions", "people"]

        for page in valid_pages:
            session.add(UserPagePermission(user_id=admin.id, page=page, can_view=True, can_edit=True))

        session.add_all(
            [
                UserTeamPermission(user_id=admin.id, team_id=team_ops.id, access_level="write"),
                UserTeamPermission(user_id=admin.id, team_id=team_support.id, access_level="write"),
            ]
        )

        session.add(UserPagePermission(user_id=planner.id, page="schedule", can_view=True, can_edit=True))
        session.add(UserPagePermission(user_id=planner.id, page="people", can_view=True, can_edit=True))
        session.add(UserPagePermission(user_id=planner.id, page="settings", can_view=True, can_edit=True))
        session.add(UserTeamPermission(user_id=planner.id, team_id=team_ops.id, access_level="write"))

        session.add(UserPagePermission(user_id=viewer.id, page="schedule", can_view=True, can_edit=False))
        session.add(UserTeamPermission(user_id=viewer.id, team_id=team_support.id, access_level="read"))

        session.flush()

        today = date.today()
        month_start = today.replace(day=1)
        for idx, person in enumerate(ops_people_models):
            session.add(
                ScheduleEntry(
                    team_id=team_ops.id,
                    person_id=person.id,
                    day=month_start + timedelta(days=idx),
                    shift_code="DAY" if idx % 2 == 0 else "SWING",
                    updated_by=admin.id,
                )
            )
        for idx, person in enumerate(support_people_models):
            session.add(
                ScheduleEntry(
                    team_id=team_support.id,
                    person_id=person.id,
                    day=month_start + timedelta(days=idx),
                    shift_code="MORNING" if idx % 2 == 0 else "EVENING",
                    updated_by=planner.id,
                )
            )
        print("Database initialized with demo data.")


def main():
    parser = argparse.ArgumentParser(description="Scheduling platform CLI")
    subparsers = parser.add_subparsers(dest="command")

    subparsers.add_parser("init-db", help="Initialize the SQLite database with demo data")

    args = parser.parse_args()
    if args.command == "init-db":
        init_database()
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
