#!/usr/bin/env python3
"""
Restructure master-data.json into the shape of the CRM's database tables.

Reads  master-data/master-data.json   (never modified)
Writes master-data/db/*.json          (JSON only; nothing is inserted anywhere)

Three rules, enforced below and re-checked at the end:
  1. Never change existing data   - every value is copied as written.
  2. Never change a date          - dates pass through as "Y-m-d H:i:s", midnight kept.
  3. Never remove a row           - one lead out per object in.

Anything supplied because the sheet was blank is listed in the row's `_filled`;
anything wrong is listed in `_flags`. The full original object is in `_legacy`.

Standard library only. Re-run with:  python3 -B master-data/restructure_to_db.py
"""

import collections
import datetime
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SOURCE = os.path.join(HERE, "master-data.json")
OUT = os.path.join(HERE, "db")

TODAY = datetime.date.today().isoformat()
DATETIME = re.compile(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$")

# ---------------------------------------------------------------------------
# Vocabulary the application already has (config/crm.php, read 2026-09-13).
# Used only to mark proposals as existing or new - never to force a value.
# ---------------------------------------------------------------------------
CONFIG_STAGES = [
    "fresh", "connected", "not_connected", "details_shared", "site_visit_scheduled",
    "site_visit_done", "in_discussion", "booking_done", "lost",
]
CONFIG_SOURCES = [
    "walk_in", "incoming_call", "referral", "facebook", "instagram", "whatsapp",
    "broker", "hoarding",
]
CONFIG_LOST_REASONS = ["budget", "location", "competitor", "not_serious", "no_response"]
CONFIG_TODO_TYPES = ["call", "whatsapp", "meeting", "site_visit"]

# The mapping table from the task, verbatim.
STAGE_MAP = {
    "New": ("fresh", None),
    "Ringing": ("not_connected", None),
    "Followup": ("connected", None),
    "Prospect": ("in_discussion", None),
    "SV Scheduled": ("site_visit_scheduled", None),
    "SV Done": ("site_visit_done", None),
    "Lost-Not Interested": ("lost", "not_interested"),
    "Lost-Call Unanswered": ("lost", "call_unanswered"),
    "Lost-Other": ("lost", "other"),
    "Lost-Location Issue": ("lost", "location_issue"),
    "Lost-Budget Issue": ("lost", "budget"),
    "Lost-Duplicate": ("lost", "duplicate"),
    "Lost-Configuration Issue": ("lost", "configuration_issue"),
    "Lost-Booked In other": ("lost", "booked_elsewhere"),
    "Lost-Choice Not Available": ("lost", "choice_unavailable"),
    "Lost-Switch Off": ("lost", "switched_off"),
    "Lost-General Enquiry": ("lost", "general_enquiry"),
    "Lost-Misclick": ("lost", "misclick"),
    "Lost-Ready to move": ("lost", "ready_to_move"),
    "Lost-Ready To Move In": ("lost", "ready_to_move"),
}

# Proposed source keys. `existing` = already a key in config('crm.sources').
SOURCE_MAP = {
    "Facebook": ("facebook", "existing", None),
    "Direct Walk-Inn": ("walk_in", "existing", None),
    "Instagram": ("instagram", "existing", None),
    "Housing Com": ("housing_com", "new", "Housing.com portal; no existing key fits"),
    "Broker": ("broker", "existing", None),
    "Hoarding": ("hoarding", "existing", None),
    "Leaflet": ("leaflet", "new", "No existing key fits"),
    "Partner Ref.": ("partner_referral", "new",
                     "Kept apart from `referral` and `broker`; 12 of 16 rows name a broker. Merge if you prefer"),
    "Member Ref.": ("member_referral", "new", "Kept apart from `referral`. Merge if you prefer"),
    "Whatsapp": ("whatsapp", "existing", None),
    "Website": ("website", "new", "No existing key fits"),
}
MISSING_SOURCE_KEY = "other"

MISSING_STAGE = "fresh"

VISIT_COLUMNS = [
    ("first_visit", "first_visit_note"),
    ("second_visit", "second_visit_note"),
    ("third_visit", "third_visit_note"),
]
FOLLOW_UP_COLUMNS = [(f"follow_up_{n}", f"follow_up_{n}_remark") for n in range(1, 6)]

# Follow-up remarks that read as an unanswered call ("CNR", "Call cut", "Switch off",
# "Call bz"...). Only raises a flag: outcome_stage stays `connected` as briefed.
NOT_CONNECTED_REMARK = re.compile(
    r"\bcnr\b|not\s*connect|not\s*(respond|receiv|pick|answer|reach|lift)|no\s*response"
    r"|switch(ed)?\s*off|ringing|\bbusy\b|\bbz\b|call\s*cut|incoming\s*call\s*stop",
    re.I,
)

LEGACY_ONLY_COLUMNS = [
    "address", "professional", "lead_quality", "send_message", "next_follow_up",
    "first_visit_month", "second_visit_month", "third_visit_month", "months",
]

# Columns in `leads` with no source column at all: the same value on every row.
SYSTEM_DEFAULTS = {
    "external_id": None,
    "not_connected_count": 0,
    "assigned_role": None,
    "created_by": None,
    "booked_unit": None,
    "booking_date": None,
    "deleted_at": None,
}


def blank_to_none(value):
    return value if value not in ("", None) else None


def slug(value):
    return re.sub(r"[^a-z0-9]+", "_", value.lower()).strip("_")


def name_key(name):
    """Python port of App\\Models\\ChannelPartner::nameKey()."""
    key = re.sub(r"[^a-z0-9]+", " ", (name or "").strip().lower())
    return re.sub(r"\s+", " ", key).strip()


def split_numbers(raw):
    """
    Split a phone cell into digit-only numbers, in the order written.

    '/' and ',' always separate numbers. Whitespace separates numbers only when
    every digit run either side is a full number (10+ digits) - so
    "9722228222 9429087845" is two numbers but "95372 03092" is one.
    """
    numbers = []
    for part in re.split(r"[/,]", raw or ""):
        runs = re.findall(r"\d+", part)
        if not runs:
            continue
        if len(runs) > 1 and all(len(run) >= 10 for run in runs):
            numbers.extend(runs)
        else:
            numbers.append("".join(runs))
    return numbers


def length_flags(number, prefix):
    flags = []
    if len(number) < 10:
        flags.append(f"{prefix}_too_short")
    elif len(number) > 10:
        flags.append(f"{prefix}_too_long")
        if len(number) == 12 and number.startswith("91"):
            flags.append(f"{prefix}_may_include_country_code_91")
    elif number[0] not in "6789":
        flags.append(f"{prefix}_does_not_start_6_to_9")
    return flags


def parse_mobile(raw):
    """Return (mobile_number, mobile_alt, extra_numbers, flags)."""
    raw = raw or ""
    if not raw.strip():
        return None, None, [], ["no_mobile"]

    numbers = split_numbers(raw)
    if not numbers:
        return None, None, [], ["no_mobile", "mobile_cell_held_text"]

    flags = []
    if len(numbers) == 2:
        flags.append("mobile_had_two_numbers")
    elif len(numbers) > 2:
        flags.append("mobile_had_more_than_two_numbers")
    if re.search(r"[^\d]", raw) and "mobile_had_two_numbers" not in flags \
            and "mobile_had_more_than_two_numbers" not in flags:
        flags.append("mobile_had_non_digit_characters")

    flags += length_flags(numbers[0], "mobile")
    alt = numbers[1] if len(numbers) > 1 else None
    if alt:
        flags += length_flags(alt, "mobile_alt")

    return numbers[0], alt, numbers[2:], flags


def is_datetime(value):
    return bool(value) and bool(DATETIME.match(value))


def compact_json(value, indent=0):
    """JSON with nested objects indented but lists of scalars kept on one line."""
    pad = "  " * indent
    inner = "  " * (indent + 1)
    if isinstance(value, dict):
        if not value:
            return "{}"
        items = [f"{inner}{json.dumps(k if isinstance(k, str) else str(k).replace('None', 'null'), ensure_ascii=False)}: "
                 f"{compact_json(v, indent + 1)}"
                 for k, v in value.items()]
        return "{\n" + ",\n".join(items) + "\n" + pad + "}"
    if isinstance(value, list):
        if not value:
            return "[]"
        inline = "[" + ", ".join(json.dumps(v, ensure_ascii=False) for v in value) + "]"
        if all(isinstance(v, (int, float)) or v is None for v in value):
            return inline
        if all(not isinstance(v, (dict, list)) for v in value) and len(inline) <= 120:
            return inline
        items = [f"{inner}{compact_json(v, indent + 1)}" for v in value]
        return "[\n" + ",\n".join(items) + "\n" + pad + "]"
    return json.dumps(value, ensure_ascii=False)


def write(name, data, compact=False):
    path = os.path.join(OUT, name)
    with open(path, "w", encoding="utf-8") as handle:
        if compact:
            handle.write(compact_json(data) + "\n")
        else:
            json.dump(data, handle, indent=2, ensure_ascii=False)
            handle.write("\n")


def main():
    with open(SOURCE, encoding="utf-8") as handle:
        rows = json.load(handle)

    os.makedirs(OUT, exist_ok=True)

    leads = []
    todos = []
    guesses = []

    # -----------------------------------------------------------------------
    # Lookups built from the data itself
    # -----------------------------------------------------------------------
    project_names = []
    for row in rows:
        if row["project"] and row["project"] not in project_names:
            project_names.append(row["project"])
    project_keys = {}
    for name in project_names:
        key = slug(name)
        while key in project_keys.values():
            key += "_2"
        project_keys[name] = key

    partner_pairs = []
    for row in rows:
        pair = (row["broker_name"], row["broker_number"])
        if (pair[0] or pair[1]) and pair not in partner_pairs:
            partner_pairs.append(pair)
    partner_keys = {pair: f"cp_{i:03d}" for i, pair in enumerate(partner_pairs, start=1)}

    # exact full-row duplicates (every column identical except the row number)
    signature_rows = collections.defaultdict(list)
    for row in rows:
        signature = json.dumps({k: v for k, v in row.items() if k != "excel_row_number"},
                               sort_keys=True, ensure_ascii=False)
        signature_rows[signature].append(row["excel_row_number"])
    exact_twins = {}
    for numbers in signature_rows.values():
        if len(numbers) > 1:
            for n in numbers:
                exact_twins[n] = [m for m in numbers if m != n]

    # previous row's create_date, for the one row that has none
    previous_create_date = {}
    last_seen = None
    for row in rows:
        previous_create_date[row["excel_row_number"]] = last_seen
        if is_datetime(row["create_date"]):
            last_seen = row["create_date"]

    # -----------------------------------------------------------------------
    # Leads and todos
    # -----------------------------------------------------------------------
    for row in rows:
        n = row["excel_row_number"]
        filled = []
        flags = []

        # --- name: the split already in master-data.json, as written
        first_name = row["first_name"]
        last_name = row["last_name"]
        if not row["raw_name"]:
            flags.append("no_name")
        if first_name == "":
            filled.append("first_name")
        if last_name == "":
            filled.append("last_name")
        if row["raw_name"].replace(" ", "").isdigit():
            flags.append("name_is_a_number")
        elif re.search(r"\d", row["raw_name"]):
            flags.append("name_contains_digits")

        # --- mobile
        mobile, mobile_alt, mobile_extra, mobile_flags = parse_mobile(row["mobile_no"])
        flags += mobile_flags

        # --- email: no column in the sheet
        filled.append("email")

        # --- project
        project_key = project_keys.get(row["project"])
        if project_key is None:
            filled.append("project_key")
            flags.append("no_project")
        elif "/" in row["project"]:
            flags.append("project_value_names_two_projects")

        # --- source
        if row["source"]:
            source = SOURCE_MAP[row["source"]][0]
        else:
            source = MISSING_SOURCE_KEY
            filled.append("source")
            flags.append("no_source")

        # --- broker / channel partner
        broker_name = blank_to_none(row["broker_name"])
        pair = (row["broker_name"], row["broker_number"])
        channel_partner_key = partner_keys.get(pair)
        if source == "broker" and not broker_name:
            flags.append("broker_source_without_broker_name")
        if broker_name and source != "broker":
            flags.append("broker_named_on_non_broker_source")
        if broker_name and not row["broker_number"]:
            flags.append("broker_without_number")

        # --- stage and lost reason
        stage_text = row["secondary_stages"]
        reason = None
        if not stage_text:
            stage = MISSING_STAGE
            filled.append("stage")
            flags.append("no_stage")
        elif stage_text in STAGE_MAP:
            stage, reason = STAGE_MAP[stage_text]
        else:
            # never reached with the current file; kept so a new value is never forced
            stage = None
            flags.append("stage_not_in_mapping_table")

        # --- assigned user
        assigned_to_name = blank_to_none(row["lead_assign_user"])
        if assigned_to_name is None:
            filled.append("assigned_to_name")
            flags.append("no_assigned_user")

        # --- created_at: create_date exactly as written
        if is_datetime(row["create_date"]):
            created_at = row["create_date"]
        else:
            created_at = previous_create_date[n]
            filled.append("created_at")
            flags.append("no_created_at")
            guesses.append({
                "what": "created_at",
                "row": n,
                "value_used": created_at,
                "why": "Create Date and Months are both blank. The sheet is in date order, so the "
                       "previous row's Create Date is used. Never today.",
            })

        # --- text sitting in a date column
        for column in ["months", "create_date"] + [c for c, _ in VISIT_COLUMNS] + \
                [f"{c}_month" for c, _ in VISIT_COLUMNS] + [c for c, _ in FOLLOW_UP_COLUMNS] + \
                ["next_follow_up"]:
            if row[column] and not is_datetime(row[column]):
                flags.append(f"text_in_date_column:{column}")

        if n in exact_twins:
            flags.append("exact_duplicate_of_rows:" + ",".join(str(m) for m in exact_twins[n]))

        # --- todos
        lead_todo_dates = []
        for kind, columns in (("visit", VISIT_COLUMNS), ("follow_up", FOLLOW_UP_COLUMNS)):
            for date_column, remark_column in columns:
                date_value = row[date_column]
                remark = blank_to_none(row[remark_column])
                if not date_value and remark is None:
                    continue

                todo_flags = []
                scheduled_at = date_value if is_datetime(date_value) else None
                todo_filled = ["assigned_to_name", "completed_by_name"]
                if scheduled_at:
                    todo_filled += ["created_at", "updated_at"]
                if scheduled_at is None:
                    todo_flags.append("no_date")
                    if date_value:
                        todo_flags.append("date_cell_held_text")
                else:
                    lead_todo_dates.append(scheduled_at)
                    if scheduled_at[:10] > TODAY:
                        todo_flags.append("date_in_future")
                    if created_at and scheduled_at < created_at:
                        todo_flags.append("date_before_lead_created_at")
                if remark is None:
                    todo_flags.append("no_remark")
                elif kind == "follow_up" and NOT_CONNECTED_REMARK.search(remark):
                    todo_flags.append("remark_suggests_not_connected")
                if assigned_to_name is None:
                    todo_flags.append("no_assigned_user")

                todo = {
                    "lead_row_number": n,
                    "assigned_to_name": assigned_to_name,
                    "created_by": None,
                    "scheduled_at": scheduled_at,
                    "type": "site_visit" if kind == "visit" else "call",
                    "status": "completed",
                    "remarks": remark,
                    "outcome_stage": "site_visit_done" if kind == "visit" else "connected",
                    "completed_at": scheduled_at,
                    "completed_by_name": assigned_to_name,
                    "rescheduled_from_id": None,
                    "created_at": scheduled_at,
                    "updated_at": scheduled_at,
                    "_source_date_column": date_column,
                    "_source_remark_column": remark_column,
                    "_filled": todo_filled,
                    "_flags": todo_flags,
                }
                if date_value and scheduled_at is None:
                    todo["_source_date_value"] = date_value
                todos.append(todo)

        if any(d[:10] > TODAY for d in lead_todo_dates):
            flags.append("has_todo_dated_in_future")
        if any(created_at and d < created_at for d in lead_todo_dates):
            flags.append("has_todo_dated_before_created_at")

        past_dates = [d for d in lead_todo_dates if d[:10] <= TODAY]
        last_activity_at = max(past_dates) if past_dates else None
        if last_activity_at:
            filled.append("last_activity_at")
        updated_at = max(filter(None, [created_at, last_activity_at]), default=None)
        filled.append("updated_at")

        lead = {
            "_row_number": n,
            "first_name": first_name,
            "middle_name": blank_to_none(row["middle_name"]),
            "last_name": last_name,
            "mobile_number": mobile,
            "mobile_alt": mobile_alt,
            "email": None,
            "project_key": project_key,
            "source": source,
            "external_id": SYSTEM_DEFAULTS["external_id"],
            "broker_name": broker_name,
            "channel_partner_key": channel_partner_key,
            "stage": stage,
            "stage_changed_at": None,
            "not_connected_count": SYSTEM_DEFAULTS["not_connected_count"],
            "assigned_to_name": assigned_to_name,
            "assigned_role": SYSTEM_DEFAULTS["assigned_role"],
            "created_by": SYSTEM_DEFAULTS["created_by"],
            "requirement": blank_to_none(row["configuration"]),
            "reason": reason,
            "booked_unit": SYSTEM_DEFAULTS["booked_unit"],
            "booking_date": SYSTEM_DEFAULTS["booking_date"],
            "last_activity_at": last_activity_at,
            "created_at": created_at,
            "updated_at": updated_at,
            "deleted_at": SYSTEM_DEFAULTS["deleted_at"],
            "_filled": filled,
            "_flags": flags,
            "_duplicate_group": None,
            "_duplicate_count": None,
            "_duplicate_rank": None,
            "_legacy": row,
        }
        if mobile_extra:
            lead["mobile_extra"] = mobile_extra
        leads.append(lead)

    # -----------------------------------------------------------------------
    # Duplicate groups: rows sharing a cleaned mobile_number
    # -----------------------------------------------------------------------
    by_mobile = collections.defaultdict(list)
    for lead in leads:
        if lead["mobile_number"]:
            by_mobile[lead["mobile_number"]].append(lead)

    duplicate_groups = []
    for mobile, members in by_mobile.items():
        if len(members) < 2:
            continue
        # newest first; same day -> later sheet row counts as newer
        members.sort(key=lambda l: (l["created_at"] or "", l["_row_number"]), reverse=True)
        for rank, lead in enumerate(members, start=1):
            lead["_duplicate_group"] = mobile
            lead["_duplicate_count"] = len(members)
            lead["_duplicate_rank"] = rank

        per_project = collections.Counter(l["project_key"] for l in members)
        clashing_projects = [p for p, c in per_project.items() if c > 1 and p is not None]
        for lead in members:
            if lead["project_key"] in clashing_projects:
                lead["_flags"].append("breaks_unique_mobile_number_project_id")

        duplicate_groups.append({
            "mobile_number": mobile,
            "count": len(members),
            "row_numbers": [l["_row_number"] for l in members],
            "projects": sorted({l["_legacy"]["project"] for l in members}),
            "stages": sorted({l["_legacy"]["secondary_stages"] for l in members}),
            "same_project_more_than_once": sorted(clashing_projects),
            "rows": [{
                "rank": l["_duplicate_rank"],
                "row_number": l["_row_number"],
                "created_at": l["created_at"],
                "name": l["_legacy"]["raw_name"],
                "project": l["_legacy"]["project"],
                "stage": l["_legacy"]["secondary_stages"],
                "source": l["_legacy"]["source"],
                "assigned_to": l["_legacy"]["lead_assign_user"],
            } for l in members],
        })
    duplicate_groups.sort(key=lambda g: (-g["count"], g["mobile_number"]))

    # a second number on one row that is the main number on another row
    alt_matches = []
    for lead in leads:
        if lead["mobile_alt"] and lead["mobile_alt"] in by_mobile:
            others = [l["_row_number"] for l in by_mobile[lead["mobile_alt"]] if l is not lead]
            if others:
                lead["_flags"].append("mobile_alt_is_main_number_of_rows:" + ",".join(map(str, others)))
                alt_matches.append({"row_number": lead["_row_number"], "mobile_alt": lead["mobile_alt"],
                                    "main_number_on_rows": others})

    # -----------------------------------------------------------------------
    # Lookup files
    # -----------------------------------------------------------------------
    lead_rows = {l["_row_number"]: l for l in leads}

    projects = [{
        "key": project_keys[name],
        "name": name,
        "lead_count": sum(1 for r in rows if r["project"] == name),
        "_flags": ["value_names_two_projects"] if "/" in name else [],
        "_note": "projects has no key column; the seeder resolves project_key to an id by name",
    } for name in project_names]

    user_names = []
    for row in rows:
        if row["lead_assign_user"] and row["lead_assign_user"] not in user_names:
            user_names.append(row["lead_assign_user"])
    users = []
    for name in user_names:
        parts = name.split(" ")
        users.append({
            "name": name,
            "proposed_first_name": parts[0],
            "proposed_last_name": " ".join(parts[1:]),
            "role": None,
            "lead_count": sum(1 for r in rows if r["lead_assign_user"] == name),
            "todo_count": sum(1 for t in todos if t["assigned_to_name"] == name),
            "_note": "role, email and mobile_number are not in the sheet",
        })

    source_counts = collections.Counter(r["source"] for r in rows)
    sources = []
    for value, count in source_counts.most_common():
        if value:
            key, status, note = SOURCE_MAP[value]
        else:
            key, status, note = MISSING_SOURCE_KEY, "placeholder", "Source cell blank; rows flagged no_source"
        sources.append({
            "source_value": value,
            "count": count,
            "proposed_key": key,
            "key_in_config_crm_sources": key in CONFIG_SOURCES,
            "status": status,
            "note": note,
        })

    stage_counts = collections.Counter(r["secondary_stages"] for r in rows)
    stages = []
    for value, count in stage_counts.most_common():
        if value in STAGE_MAP:
            stage, reason = STAGE_MAP[value]
            status = "from_mapping_table"
        elif value == "":
            stage, reason, status = MISSING_STAGE, None, "placeholder"
        else:
            stage, reason, status = None, None, "new_unmapped"
        stages.append({
            "source_value": value,
            "count": count,
            "stage": stage,
            "lost_reason": reason,
            "status": status,
            "stage_in_config_crm_stages": stage in CONFIG_STAGES,
            "lost_reason_in_config_crm_lost_reasons": (reason in CONFIG_LOST_REASONS) if reason else None,
        })

    reason_counts = collections.Counter(l["reason"] for l in leads if l["reason"])
    lost_reasons = [{
        "key": key,
        "count": count,
        "source_values": sorted(v for v, (_, r) in STAGE_MAP.items() if r == key and v in stage_counts),
        "in_config_crm_lost_reasons": key in CONFIG_LOST_REASONS,
    } for key, count in reason_counts.most_common()]

    channel_partners = []
    for pair, key in partner_keys.items():
        name, number_raw = pair
        numbers = split_numbers(number_raw)
        cp_flags = []
        if not numbers:
            cp_flags.append("no_phone")
        if len(numbers) > 1:
            cp_flags.append("phone_had_two_numbers")
        if numbers:
            cp_flags += length_flags(numbers[0], "phone")
        member_rows = [r["excel_row_number"] for r in rows
                       if (r["broker_name"], r["broker_number"]) == pair]
        channel_partners.append({
            "key": key,
            "name": name,
            "name_key": name_key(name),
            "type": "broker",
            "phone": numbers[0] if numbers else None,
            "alt_phone": numbers[1] if len(numbers) > 1 else None,
            "_phone_raw": number_raw,
            "lead_count": len(member_rows),
            "lead_row_numbers": member_rows,
            "possibly_same_as": [],
            "_flags": cp_flags,
        })
    for cp in channel_partners:
        mine = {cp["phone"], cp["alt_phone"]} - {None}
        for other in channel_partners:
            if other is cp:
                continue
            reasons = []
            if other["name_key"] == cp["name_key"]:
                reasons.append("same_name_key")
            if mine & ({other["phone"], other["alt_phone"]} - {None}):
                reasons.append("shares_phone")
            if reasons:
                cp["possibly_same_as"].append({"key": other["key"], "name": other["name"],
                                               "phone": other["_phone_raw"], "why": reasons})
        if any("same_name_key" in p["why"] for p in cp["possibly_same_as"]):
            cp["_flags"].append("breaks_unique_name_key_type")

    # -----------------------------------------------------------------------
    # Report
    # -----------------------------------------------------------------------
    def count_list(values):
        return dict(collections.Counter(values).most_common())

    filled_counts = collections.Counter(f for l in leads for f in l["_filled"])
    flag_counts = collections.Counter(f.split(":")[0] for l in leads for f in l["_flags"])
    todo_flag_counts = collections.Counter(f for t in todos for f in t["_flags"])

    created = sorted(l["created_at"] for l in leads if l["created_at"])

    # every date written anywhere, for the "never today / format" checks
    written_dates = []
    for l in leads:
        for f in ("created_at", "updated_at", "last_activity_at", "stage_changed_at"):
            if l[f]:
                written_dates.append((f, l[f]))
    for t in todos:
        for f in ("scheduled_at", "completed_at", "created_at", "updated_at"):
            if t[f]:
                written_dates.append((f, t[f]))
    bad_format = [d for d in written_dates if not DATETIME.match(d[1])]
    on_today = [d for d in written_dates if d[1][:10] == TODAY]

    # did every date survive unchanged?
    date_mismatch = []
    for l in leads:
        legacy = l["_legacy"]
        if "created_at" not in l["_filled"] and l["created_at"] != legacy["create_date"]:
            date_mismatch.append(l["_row_number"])
    for t in todos:
        legacy_value = lead_rows[t["lead_row_number"]]["_legacy"][t["_source_date_column"]]
        if t["scheduled_at"] is not None and t["scheduled_at"] != legacy_value:
            date_mismatch.append(t["lead_row_number"])

    def rows_with(flag):
        return [l["_row_number"] for l in leads if any(f.split(":")[0] == flag for f in l["_flags"])]

    todo_by_column = collections.Counter(t["_source_date_column"] for t in todos)
    todo_source_order = [c for c, _ in VISIT_COLUMNS + FOLLOW_UP_COLUMNS]

    guesses += [
        {"what": "source for blank Source cells", "rows": rows_with("no_source"),
         "value_used": MISSING_SOURCE_KEY,
         "why": "Your suggested placeholder. `other` is not a key in config('crm.sources') yet"},
        {"what": "new source keys", "value_used": {s["source_value"]: s["proposed_key"]
                                                   for s in sources if s["status"] == "new"},
         "why": "No existing key fits; see sources.json"},
        {"what": "project key for 'Felicity/SkyDeck'", "rows": rows_with("project_value_names_two_projects"),
         "value_used": "felicity_skydeck",
         "why": "A lead holds one project_id. Not forced into either project; kept as its own value "
                "for you to decide"},
        {"what": "name split", "value_used": "first_name/middle_name/last_name as already in master-data.json",
         "why": "Not re-split. 1 word -> first only; 2 -> first+last; 3 -> first+middle+last; 4+ -> all but the "
                "last two words in first_name. last_name is '' for single-word names"},
        {"what": "mobile numbers separated only by a space",
         "value_used": "two numbers when both runs are 10+ digits, otherwise one number with the space removed",
         "why": "'9722228222 9429087845' is two numbers; '95372 03092' is one"},
        {"what": "last_activity_at", "value_used": "latest visit/follow-up date on the row, ignoring future dates",
         "why": "Not in the sheet. Null when the row has no dated visit or follow-up"},
        {"what": "updated_at", "value_used": "last_activity_at, else created_at",
         "why": "Not in the sheet; set so a seeder cannot stamp today's date"},
        {"what": "todo type and outcome_stage",
         "value_used": {"visits": {"type": "site_visit", "outcome_stage": "site_visit_done"},
                        "follow_ups": {"type": "call", "outcome_stage": "connected"}},
         "why": "site_visit_done/connected are stage keys, so they are read as outcome_stage; type uses "
                "config('crm.todo_types')"},
        {"what": "todo assigned_to_name / completed_by_name", "value_used": "the lead's assigned user",
         "why": "The sheet does not say who made the call or visit"},
        {"what": "todo created_at / updated_at", "value_used": "the todo's own date",
         "why": "Not in the sheet; set so a seeder cannot stamp today's date"},
        {"what": "users.json first/last name", "value_used": "first word / the rest",
         "why": "users has first_name + last_name, the sheet has one name"},
    ]

    warnings = [
        "leads.mobile_number is NOT NULL: %d leads have no mobile (left null, not invented)."
        % len(rows_with("no_mobile")),
        "leads.project_id is NOT NULL: %d leads have no project." % len(rows_with("no_project")),
        "unique(mobile_number, project_id): %d leads share a mobile AND a project with another lead "
        "(flag breaks_unique_mobile_number_project_id). The seeder cannot insert them all as-is."
        % len(rows_with("breaks_unique_mobile_number_project_id")),
        "leads has no column for mobile_alt (%d leads) or mobile_extra (%d leads)."
        % (sum(1 for l in leads if l["mobile_alt"]), sum(1 for l in leads if "mobile_extra" in l)),
        "Lost reasons: only `budget` is a key in config('crm.lost_reasons'). LeadRequest and "
        "CompleteTodoRequest validate `reason` against that list, so the other %d keys must be added "
        "there before these leads can be edited." % (len(lost_reasons) - 1),
        "Sources: %d proposed keys are not in config('crm.sources'): %s."
        % (len({s["proposed_key"] for s in sources if not s["key_in_config_crm_sources"]}),
           ", ".join(sorted({s["proposed_key"] for s in sources if not s["key_in_config_crm_sources"]}))),
        "todos.scheduled_at is NOT NULL: %d todos have no date (a remark with no date)."
        % todo_flag_counts["no_date"],
        "todos.assigned_to is NOT NULL: %d todos belong to leads with no assigned user."
        % todo_flag_counts["no_assigned_user"],
        "%d of %d follow-up todos have a remark that reads as an unanswered call (CNR, Call cut, Switch off, "
        "Call bz...) but get outcome_stage `connected` per the brief (flag remark_suggests_not_connected)."
        % (todo_flag_counts["remark_suggests_not_connected"], sum(1 for t in todos if t["type"] == "call")),
        "%d completed todos are dated after today (%s), kept as written."
        % (todo_flag_counts["date_in_future"], TODAY),
        "%d todos are dated before their lead's created_at, kept as written."
        % todo_flag_counts["date_before_lead_created_at"],
        "channel_partners.phone is NOT NULL: %d partners have no number."
        % sum(1 for c in channel_partners if "no_phone" in c["_flags"]),
        "unique(name_key, type) on channel_partners: %d partner rows share a name_key with another."
        % sum(1 for c in channel_partners if "breaks_unique_name_key_type" in c["_flags"]),
        "73 dates were typed as text (M/D/YYYY) in the sheet. Checked against master-sheet.xls: none is "
        "ambiguous (the second part is always > 12), so they are month/day.",
        "The sheet has no times: every date is midnight and stays midnight.",
        "next_follow_up (%d rows) is not turned into todos, per the brief; it stays in _legacy."
        % sum(1 for r in rows if r["next_follow_up"]),
        "stage_changed_at is left null. The app reads COALESCE(stage_changed_at, created_at) "
        "(Lead.php, RunAutomation, ConditionMatcher), so null means 'since created_at'.",
        "assigned_role is left null; run the reconcile step (users.role) after users exist.",
    ]

    report = {
        "generated_on": TODAY,
        "source_file": "master-data/master-data.json (read only, not modified)",
        "counts": {
            "input_rows": len(rows),
            "output_leads": len(leads),
            "match": len(rows) == len(leads),
            "output_row_numbers_identical_to_input": [l["_row_number"] for l in leads]
                                                       == [r["excel_row_number"] for r in rows],
            "todos": len(todos),
            "projects": len(projects),
            "users": len(users),
            "sources": len(sources),
            "stages": len(stages),
            "lost_reasons": len(lost_reasons),
            "channel_partners": len(channel_partners),
        },
        "checks": {
            "dates_written": len(written_dates),
            "dates_not_in_Y-m-d_H:i:s": len(bad_format),
            "dates_equal_to_today": len(on_today),
            "dates_changed_from_source": len(date_mismatch),
            "dates_with_a_time_other_than_midnight": sum(1 for _, d in written_dates if not d.endswith("00:00:00")),
        },
        "created_at": {"oldest": created[0], "newest": created[-1]},
        "leads_by_stage": count_list(l["stage"] for l in leads),
        "leads_by_lost_reason": count_list(l["reason"] for l in leads if l["reason"]),
        "leads_by_source": count_list(l["source"] for l in leads),
        "leads_by_project": count_list(l["project_key"] for l in leads),
        "leads_by_user": count_list(l["assigned_to_name"] for l in leads),
        "distinct_values": {
            "secondary_stages": count_list(r["secondary_stages"] for r in rows),
            "source": count_list(r["source"] for r in rows),
            "project": count_list(r["project"] for r in rows),
            "lead_assign_user": count_list(r["lead_assign_user"] for r in rows),
            "configuration -> requirement": count_list(r["configuration"] for r in rows),
            "broker_name + broker_number": "%d distinct pairs on %d rows, all listed in channel_partners.json"
                                          % (len(channel_partners), sum(c["lead_count"] for c in channel_partners)),
            "name": "%d distinct values; split already in master-data.json" % len({r["raw_name"] for r in rows}),
            "mobile_no": "%d distinct values; %d are not plain 10 digits"
                         % (len({r["mobile_no"] for r in rows}),
                            sum(1 for r in rows if r["mobile_no"] and not re.fullmatch(r"\d{10}", r["mobile_no"]))),
        },
        "legacy_only_columns_non_empty": {c: sum(1 for r in rows if r[c]) for c in LEGACY_ONLY_COLUMNS},
        "system_columns_same_on_every_lead": SYSTEM_DEFAULTS,
        "leads_filled_field_counts": dict(filled_counts.most_common()),
        "leads_flag_counts": dict(flag_counts.most_common()),
        "leads_flag_rows": {f: rows_with(f) for f, c in flag_counts.most_common() if c <= 150},
        "duplicates": {
            "groups": len(duplicate_groups),
            "rows_involved": sum(g["count"] for g in duplicate_groups),
            "largest_group": max((g["count"] for g in duplicate_groups), default=0),
            "groups_with_same_project_more_than_once": sum(1 for g in duplicate_groups
                                                           if g["same_project_more_than_once"]),
            "rows_whose_mobile_alt_is_another_rows_main_number": len(alt_matches),
            "exact_full_row_duplicate_rows": sorted(exact_twins),
        },
        "todos": {
            "generated": len(todos),
            "by_source_column": {c: todo_by_column[c] for c in todo_source_order},
            "by_type": count_list(t["type"] for t in todos),
            "with_date": sum(1 for t in todos if t["scheduled_at"]),
            "without_date": sum(1 for t in todos if not t["scheduled_at"]),
            "flag_counts": dict(todo_flag_counts.most_common()),
            "flag_rows": {
                f: sorted({t["lead_row_number"] for t in todos if f in t["_flags"]})
                for f in ("no_date", "date_cell_held_text", "date_in_future", "date_before_lead_created_at",
                          "no_assigned_user")
            },
        },
        "guesses": guesses,
        "warnings": warnings,
    }

    # -----------------------------------------------------------------------
    # Hard stops: the three rules
    # -----------------------------------------------------------------------
    assert len(leads) == len(rows), "row count changed"
    assert not bad_format, bad_format[:5]
    assert not on_today, on_today[:5]
    assert not date_mismatch, date_mismatch[:5]

    write("leads.json", leads)
    write("todos.json", todos)
    write("projects.json", projects, compact=True)
    write("users.json", users, compact=True)
    write("sources.json", sources, compact=True)
    write("stages.json", stages, compact=True)
    write("lost_reasons.json", lost_reasons, compact=True)
    write("channel_partners.json", channel_partners, compact=True)
    write("duplicates.json", {"groups": duplicate_groups, "alt_number_matches": alt_matches}, compact=True)
    write("report.json", report, compact=True)

    print(f"{len(rows)} rows in, {len(leads)} leads out, {len(todos)} todos -> {OUT}")


if __name__ == "__main__":
    sys.exit(main())
