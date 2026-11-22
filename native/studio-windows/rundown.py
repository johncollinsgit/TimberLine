"""Import approved sponsorship obligations without changing payment records."""
from uuid import UUID

from media import number


def parse_rundown(p, raw):
    if raw.get("schema_version") != 1 or UUID(str(raw.get("episode_id"))) != UUID(p["episode_id"]):
        raise ValueError("This sponsor rundown belongs to a different episode.")
    existing={(r["booking_id"],r["kind"]):r for r in p.get("sponsor_rundown",[])}
    for item in raw["deliverables"]:
        kind=item["kind"]
        booking=item["booking_id"]
        if type(booking)!=int or booking<1 or kind not in {"host_read","social_clip","production"}:
            raise ValueError("Invalid sponsor deliverable.")
        duration=item.get("seconds")
        if duration is not None:
            duration=number(duration,1,86400,"promised duration")
        previous=existing.get((booking,kind),{})
        existing[(booking,kind)]={"booking_id":booking,"kind":kind,"sponsor":str(item["sponsor"]),
                                  "brief":str(item["brief"]),"seconds":duration,"placement":previous.get("placement"),
                                  "editor_notes":previous.get("editor_notes",str(item["brief"]))}
    return list(existing.values())
