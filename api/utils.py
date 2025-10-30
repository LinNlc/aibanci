from __future__ import annotations

from datetime import date

WEEKDAY_NAMES = ["周一", "周二", "周三", "周四", "周五", "周六", "周日"]


def weekday_name(day: date) -> str:
    return WEEKDAY_NAMES[day.weekday()]
