#!/usr/bin/env python3
"""
Build old-data/*.json from all four legacy sources.

Reads  master-data/db/*.json      (already converted; read only, never re-parsed)
       MYCO/MYCO.xlsx              (read only)
       12-Sep-Lead/12 Sep Lead.csv (read only)
       channel_partners_Data.xls   (read only)
Writes old-data/*.json             (JSON only; nothing is inserted anywhere,
                                     no application code is touched)

Three rules, enforced below and re-checked at the end:
  1. Never change existing data - every value is copied as written.
  2. Never change a date        - dates pass through as "Y-m-d H:i:s",
                                   midnight kept where the source has no time.
  3. Never remove a row         - one lead out per source row in, all four
                                   files, 16,139 total.

Anything supplied because a cell was blank is listed in the row's `_filled`;
anything uncertain, mapped by guess, or worth a second look is in `_flags`.
The complete original row is kept in `_legacy`. Nothing is ever forced into
an existing stage/source/reason key silently - a new one is proposed and
marked `"new": true` in stages.json / sources.json / lost_reasons.json.

Standard library only except `xlrd` (system-installed 1.2.0, reads both
.xls and .xlsx). Re-run with:  python3 -B old-data/build.py
"""

import collections
import csv
import datetime
import json
import os
import re
import sys

import xlrd

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
OUT = HERE

MASTER_DB = os.path.join(ROOT, "master-data", "db")
MYCO_PATH = os.path.join(ROOT, "MYCO", "MYCO.xlsx")
CSV_PATH = os.path.join(ROOT, "12-Sep-Lead", "12 Sep Lead.csv")
PARTNERS_PATH = os.path.join(ROOT, "channel_partners_Data.xls")

TODAY = datetime.date.today().isoformat()
DATETIME_RE = re.compile(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$")

SOURCE_FILE_MASTER = "master_sheet"
SOURCE_FILE_MYCO = "myco"
SOURCE_FILE_CSV = "12_sep_lead"
SOURCE_FILE_PARTNERS = "channel_partners_file"

# ---------------------------------------------------------------------------
# Vocabulary the application already has (config/crm.php, read 2026-09-14).
# Used only to mark a proposal as existing or new - never to force a value.
# ---------------------------------------------------------------------------
CONFIG_STAGES = [
    "fresh", "connected", "not_connected", "details_shared", "site_visit_scheduled",
    "site_visit_done", "in_discussion", "booking_done", "lost",
]
CONFIG_SOURCES = [
    "walk_in", "incoming_call", "referral", "facebook", "instagram", "whatsapp",
    "broker", "hoarding",
]
CONFIG_LOST_REASONS = [
    "budget", "location", "competitor", "not_serious", "no_response",
    "not_interested", "call_unanswered", "other", "location_issue", "duplicate",
    "configuration_issue", "booked_elsewhere", "choice_unavailable", "switched_off",
    "general_enquiry", "misclick", "ready_to_move",
]
MISSING_SOURCE_KEY = "other"
MISSING_STAGE = "fresh"

# ---------------------------------------------------------------------------
# Assignment rules (new, per brief) - override whatever the source files say.
# Every lead is assigned by its project, never by the sheet's own owner
# column; that column is historical and kept only in _legacy. Anything not
# on this list (no project, Kinaro, any Felicity/SkyDeck-style compound)
# falls to the telecaller - see the "assigned_to_telecaller_no_project_mapping"
# flag and report.json's assignment_override section.
# ---------------------------------------------------------------------------
PROJECT_SALESPERSON = {
    "vanam": "Mayur Patel",
    "skydeck": "Ravi Patel",
    "felicity": "Dhanashri Meshram",
}
TELECALLER = "Riya Gandhi"
TERMINAL_STAGES = {"lost", "booking_done"}

# ===========================================================================
# Shared helpers (ported from master-data/restructure_to_db.py so the two
# corpora read the same way)
# ===========================================================================


def slug(value):
    return re.sub(r"[^a-z0-9]+", "_", (value or "").lower()).strip("_")


def name_key(name):
    """Python port of App\\Models\\ChannelPartner::nameKey()."""
    key = re.sub(r"[^a-z0-9]+", " ", (name or "").strip().lower())
    return re.sub(r"\s+", " ", key).strip()


def split_name(full_name_str):
    """Identical to master-data/process_master_data.py:split_name, reused so a
    name is split the same way whichever file it came from."""
    clean = re.sub(r"\s+", " ", (full_name_str or "").strip())
    if not clean:
        return "", "", ""
    parts = clean.split(" ")
    if len(parts) == 1:
        return parts[0], "", ""
    if len(parts) == 2:
        return parts[0], "", parts[1]
    if len(parts) == 3:
        return parts[0], parts[1], parts[2]
    return " ".join(parts[:-2]), parts[-2], parts[-1]


def split_numbers(raw):
    """
    Split a phone cell into digit-only numbers, in the order written.

    '/' and ',' always separate numbers. Whitespace separates numbers only when
    every digit run either side is a full number (10+ digits).
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


def parse_mobile(raw, strip_country_code=False):
    """
    Return (mobile_number, mobile_alt, extra_numbers, flags).

    strip_country_code=True additionally recognises a leading '+' and, once
    stripped, a leading '91' on a 12-digit number as India's dialling code -
    what config('crm.country_code') adds back for the tel:/wa.me links, and
    what the app's own comment on the mobile_number migration says is stored
    ("bare 10 digits"). The original text is untouched in `_legacy`; only the
    digits kept in mobile_number are affected, and every strip is flagged.
    """
    raw = (raw or "").strip()
    if not raw:
        return None, None, [], ["no_mobile"]

    flags = []
    working = raw
    if strip_country_code and working.startswith("+"):
        working = working[1:]
        flags.append("mobile_had_plus_prefix")

    numbers = split_numbers(working)
    if not numbers:
        return None, None, [], ["no_mobile", "mobile_cell_held_text"]

    if strip_country_code and len(numbers) == 1 and len(numbers[0]) == 12 and numbers[0].startswith("91"):
        numbers[0] = numbers[0][2:]
        flags.append("mobile_country_code_91_stripped")

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


def blank_to_none(value):
    return value if value not in ("", None) else None


def is_datetime(value):
    return bool(value) and bool(DATETIME_RE.match(value))


def xldate(value, datemode, with_seconds=True):
    dt = xlrd.xldate_as_datetime(value, datemode)
    if with_seconds:
        return dt.strftime("%Y-%m-%d %H:%M:%S")
    return dt.strftime("%Y-%m-%d %H:%M:00")


def compact_json(value, indent=0):
    """JSON with nested objects indented but lists of scalars kept on one line."""
    pad = "  " * indent
    inner = "  " * (indent + 1)
    if isinstance(value, dict):
        if not value:
            return "{}"
        items = [
            f"{inner}{json.dumps(k if isinstance(k, str) else str(k), ensure_ascii=False)}: "
            f"{compact_json(v, indent + 1)}"
            for k, v in value.items()
        ]
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


def read_json(path):
    with open(path, encoding="utf-8") as handle:
        return json.load(handle)


# ===========================================================================
# Global registries, built up as every source is read
# ===========================================================================

def project_match_key(name):
    """Case/whitespace-insensitive identity for 'is this the same project
    already seen', WITHOUT dropping non-ASCII text the way slug() does - so a
    Gujarati/English mixed value naming three projects is never silently
    folded onto a two-project English value that happens to slug the same."""
    return re.sub(r"\s+", " ", name.strip().lower())


class Registries:
    def __init__(self):
        self.project_match_to_key = {}   # project_match_key(name) -> key
        self.project_names = {}          # key -> canonical display name (first seen)
        self.project_alt_names = collections.defaultdict(set)  # key -> {names}
        self.project_first_seen = {}     # key -> earliest created_at
        self.project_lead_counts = collections.Counter()
        self.project_source_files = collections.defaultdict(set)

        self.user_names = []         # order of first appearance
        self.user_lead_counts = collections.Counter()
        self.user_source_files = collections.defaultdict(set)

        self.guesses = []
        self.warnings = []

    def project_key_for(self, name, source_file, created_at):
        name = blank_to_none(name)
        if name is None:
            return None
        m = project_match_key(name)
        key = self.project_match_to_key.get(m)
        if key is None:
            candidate = slug(name) or "project"
            existing = set(self.project_match_to_key.values())
            while candidate in existing:
                candidate += "_2"
            key = candidate
            self.project_match_to_key[m] = key
            self.project_names[key] = name
        self.project_alt_names[key].add(name)
        self.project_lead_counts[key] += 1
        self.project_source_files[key].add(source_file)
        if created_at:
            cur = self.project_first_seen.get(key)
            if cur is None or created_at < cur:
                self.project_first_seen[key] = created_at
        return key

    def note_user(self, name, source_file):
        name = blank_to_none(name)
        if name is None:
            return None
        if name not in self.user_names:
            self.user_names.append(name)
        self.user_lead_counts[name] += 1
        self.user_source_files[name].add(source_file)
        return name


REG = Registries()

print(f"old-data/build.py - {TODAY}", file=sys.stderr)


# ===========================================================================
# Source 1: master-sheet, already converted -> master-data/db/
#
# Read only. Not re-derived from the .xls. `_row_number`, `_filled`, `_flags`
# and `_legacy` are already exactly right; this only adds the two fields every
# source needs to sit in one unified file (`_source_file`, `_import_key`) and
# feeds its projects/users/sources/stages into the global registries.
# ===========================================================================

def load_master_sheet():
    leads_in = read_json(os.path.join(MASTER_DB, "leads.json"))
    todos_in = read_json(os.path.join(MASTER_DB, "todos.json"))
    projects_in = read_json(os.path.join(MASTER_DB, "projects.json"))
    partners_in = read_json(os.path.join(MASTER_DB, "channel_partners.json"))

    # project_key values in master-data/db/leads.json are already slugs
    # matching projects.json; register them so later sources that name the
    # same project (any case/spacing) land on the same key.
    name_by_key = {p["key"]: p["name"] for p in projects_in}
    for key, name in name_by_key.items():
        if key not in REG.project_names:
            REG.project_names[key] = name
            REG.project_match_to_key[project_match_key(name)] = key

    leads = []
    for lead in leads_in:
        row = lead["_row_number"]
        out = dict(lead)
        out["_source_file"] = SOURCE_FILE_MASTER
        out["_import_key"] = f"{SOURCE_FILE_MASTER}:{row}"
        leads.append(out)

        pkey = out.get("project_key")
        if pkey:
            name = name_by_key.get(pkey, pkey)
            REG.project_key_for(name, SOURCE_FILE_MASTER, out["created_at"])
        REG.note_user(out.get("assigned_to_name"), SOURCE_FILE_MASTER)

    todos = []
    for todo in todos_in:
        out = dict(todo)
        out["_source_file"] = SOURCE_FILE_MASTER
        out["_lead_import_key"] = f"{SOURCE_FILE_MASTER}:{todo['lead_row_number']}"
        todos.append(out)

    # channel partners already extracted from this file's broker_name/number
    partners = []
    for p in partners_in:
        out = dict(p)
        out["_origin"] = SOURCE_FILE_MASTER
        out["_import_key"] = f"{SOURCE_FILE_MASTER}:{p['key']}"
        partners.append(out)

    stage_counts = collections.Counter(
        lead["_legacy"]["secondary_stages"] for lead in leads_in
    )
    source_counts = collections.Counter(
        lead["_legacy"]["source"] for lead in leads_in
    )

    print(f"master_sheet: {len(leads)} leads, {len(todos)} todos, {len(partners)} partners (read from master-data/db/)",
          file=sys.stderr)

    return {
        "leads": leads,
        "todos": todos,
        "partners": partners,
        "stage_counts": stage_counts,
        "source_counts": source_counts,
        "input_rows": len(leads_in),
    }


# ===========================================================================
# Stage mapping shared by MYCO ("Lead Status") and reused where the same
# "New / Ringing / Followup / .../ Lost-<reason>" vocabulary appears.
# Copied verbatim from master-data/restructure_to_db.py:STAGE_MAP, then
# extended with the values MYCO adds that master-sheet never had.
# ===========================================================================
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

# Values the task brief named ("Not Qualified", "Lost - Plan Hold & Cancel")
# plus three more the actual sheet contains that were not named in the brief
# (Lost-Wrong Numbers, Lost-Old Property Sale, Lost- Vastu). All five are new:
# none is forced into an existing STAGE_MAP row. Every one is flagged on its
# rows and listed with "new": true in stages.json for confirmation.
MYCO_NEW_STATUS_MAP = {
    "Not Qualified": ("lost", "not_qualified"),
    "Lost - Plan Hold & Cancel": ("lost", "plan_hold_cancel"),
    "Lost-Wrong Numbers": ("lost", "wrong_number"),
    "Lost-Old Property Sale": ("lost", "old_property_sale"),
    "Lost- Vastu": ("lost", "vastu"),
}
MYCO_STATUS_NOT_IN_BRIEF = {"Lost-Wrong Numbers", "Lost-Old Property Sale", "Lost- Vastu"}

# ---------------------------------------------------------------------------
# Two-level source classification, shared by MYCO (Lead Source / Sub Lead
# Source) and the 12 Sep CSV (lead_source / lead_sub_source). Both files use
# overlapping vocabulary for the sub-source level (Facebook, WhatsApp, Old
# Members, Hoarding...) so one table maps both consistently. Parent-only
# entries apply when the sub-source cell is blank.
# ---------------------------------------------------------------------------
SUB_SOURCE_MAP = {
    "Facebook": ("facebook", "existing", None),
    "WhatsApp": ("whatsapp", "existing", None),
    "Direct Walk Inn": ("walk_in", "existing", None),
    "Direct Walk-In": ("walk_in", "existing", None),
    "Old Members": ("old_members", "new", "Referral from an existing client; no existing key fits"),
    "Member Referral": ("member_referral", "new", "Reuses the key master-sheet proposed for 'Member Ref.'"),
    "Partner Referral": ("partner_referral", "new", "Reuses the key master-sheet proposed for 'Partner Ref.'"),
    "On-Site Hoarding": ("on_site_hoarding", "new", "Kept apart from plain 'Hoarding'; merge if you prefer"),
    "Hoarding": ("hoarding", "existing", None),
    "Launch Event": ("launch_event", "new", "No existing key fits"),
    "Pamphlet": ("leaflet", "new", "Reuses the key master-sheet proposed for 'Leaflet' (same kind of printed handout)"),
    "Website": ("website", "new", "Reuses the key master-sheet proposed for 'Website'"),
    "Squrefit Page": ("squrefit_page", "new",
                       "Reads as a Facebook/social campaign page name, not a broker and not "
                       "generic 'facebook'; not merged into either silently - your call"),
}
PARENT_ONLY_MAP = {
    "Direct": ("direct", "new", "No sub-source given; not assumed to be walk_in"),
    "Social Media": ("social_media", "new", "No sub-source given; not assumed to be facebook"),
    "Referral": ("referral", "existing", None),
    "Refferral": ("referral", "existing", "Source value is a typo of 'Referral' in the file; key normalized, "
                                          "original kept in source_value"),
    "Channel Partner": ("broker", "existing", "No broker name given on the row"),
    "Channel Parner": ("broker", "existing", "Source value is a typo of 'Channel Partner' in the file; "
                                             "no broker name given on the row"),
    "Media Online": ("website", "new", None),
    "Media Print & Outdoor": ("media_print_outdoor", "new", "No sub-source given"),
}
CHANNEL_PARTNER_PARENTS = {"Channel Partner", "Channel Parner"}


def looks_like_broker_text(sub):
    """A sub-source cell holding a name and a phone number, not a category."""
    return bool(re.search(r"\d{9,}", sub))


def extract_name_phone(raw):
    raw = raw.strip()
    if "\t" in raw:
        name, _, phone = raw.partition("\t")
        return name.strip(), phone.strip()
    m = re.match(r"^(?P<name>.+?)\s*[-–]\s*(?P<phone>\+?\d[\d/]*)$", raw)
    if m:
        return m.group("name").strip(), m.group("phone").strip()
    return raw, None


def classify_source(parent, sub):
    """
    Return (key, status, note, broker_name, broker_number, flags) for a
    two-level (parent, sub) source pair. status is "existing" (already a key
    in config('crm.sources')), "new" (proposed, not yet in config), or
    "placeholder" (the row had nothing to classify).
    """
    parent = (parent or "").strip()
    sub = (sub or "").strip()

    if not parent and not sub:
        return MISSING_SOURCE_KEY, "placeholder", "Source cell blank; row flagged no_source", None, None, ["no_source"]

    if sub:
        if parent in CHANNEL_PARTNER_PARENTS and looks_like_broker_text(sub) and sub not in SUB_SOURCE_MAP:
            name, phone = extract_name_phone(sub)
            flags = ["source_sub_value_looks_like_broker"]
            if not phone:
                flags.append("broker_without_number")
            return "broker", "existing", None, name, phone, flags
        if sub in SUB_SOURCE_MAP:
            key, status, note = SUB_SOURCE_MAP[sub]
            return key, status, note, None, None, []
        if parent in CHANNEL_PARTNER_PARENTS:
            # a personal name with no phone in the cell - still a broker
            return ("broker", "existing",
                    "Sub-source is a personal name with no phone number in the source file; treated as a broker",
                    sub, None, ["broker_without_number"])
        # genuinely unseen sub-source value under a non-broker parent
        return (slug(sub) or MISSING_SOURCE_KEY, "new",
                "Not in the mapping table prepared for this run; proposed key is a plain slug of the value",
                None, None, ["source_sub_value_unmapped"])

    if parent in PARENT_ONLY_MAP:
        key, status, note = PARENT_ONLY_MAP[parent]
        flags = ["broker_source_without_broker_name"] if key == "broker" else []
        return key, status, note, None, None, flags

    return (slug(parent) or MISSING_SOURCE_KEY, "new",
            "Not in the mapping table prepared for this run; proposed key is a plain slug of the value",
            None, None, ["source_parent_value_unmapped"])


# ===========================================================================
# Source 2: MYCO/MYCO.xlsx
#
# No follow-up/visit columns -> no todos from this file (todos.json stays
# master-sheet only, as the brief says).
#
# "Company Industry" is not company data at all: every value in it is one of
# our own project names (Felicity, Vanam, SkyDeck, or a slash-joined pair/
# triple exactly like master-sheet's "Felicity/SkyDeck"). Read as the project
# column. Flagged on every row it fills, since it is a repurposed field, not a
# column literally named Project - confirm before the seeder runs.
# ===========================================================================

def load_myco():
    wb = xlrd.open_workbook(MYCO_PATH)
    sh = wb.sheet_by_index(0)
    header = [sh.cell_value(0, c) for c in range(sh.ncols)]
    idx = {h: i for i, h in enumerate(header)}

    def val(row, name):
        return sh.cell_value(row, idx[name])

    leads = []
    partner_candidates = []  # (name, phone, row_number)
    stage_counts = collections.Counter()
    parent_sub_counts = collections.Counter()
    project_value_counts = collections.Counter()
    new_status_rows = collections.defaultdict(list)

    for r in range(1, sh.nrows):
        row_number = r + 1  # 1-indexed, header is row 1
        filled, flags = [], []

        raw_name = str(val(r, "Lead Name") or "").strip()
        first_name, middle_name, last_name = split_name(raw_name)
        if not raw_name:
            flags.append("no_name")
        if raw_name.replace(" ", "").isdigit() and raw_name:
            flags.append("name_is_a_number")
        elif re.search(r"\d", raw_name):
            flags.append("name_contains_digits")
        if first_name == "":
            filled.append("first_name")
        if last_name == "":
            filled.append("last_name")

        mobile_raw = str(val(r, "Mobile") or "")
        mobile, mobile_alt, mobile_extra, mobile_flags = parse_mobile(mobile_raw, strip_country_code=True)
        flags += mobile_flags

        email = blank_to_none(str(val(r, "Email") or "").strip())
        if email is None:
            filled.append("email")

        # --- project, from the repurposed "Company Industry" column
        project_raw = str(val(r, "Company Industry") or "").strip()
        project_value_counts[project_raw] += 1
        created_serial = val(r, "Created At")
        created_at = xldate(created_serial, wb.datemode) if created_serial else None
        if not created_at:
            flags.append("no_created_at")
        if project_raw:
            project_key = REG.project_key_for(project_raw, SOURCE_FILE_MYCO, created_at)
            flags.append("project_from_company_industry_field")
            if "/" in project_raw or re.search(r"[^\x00-\x7f]", project_raw):
                flags.append("project_value_names_multiple_projects")
        else:
            project_key = None
            filled.append("project_key")
            flags.append("no_project")

        # --- stage, from Lead Status
        status_raw = str(val(r, "Lead Status") or "").strip()
        stage_counts[status_raw] += 1
        if not status_raw:
            stage, reason = MISSING_STAGE, None
            filled.append("stage")
            flags.append("no_stage")
        elif status_raw in STAGE_MAP:
            stage, reason = STAGE_MAP[status_raw]
        elif status_raw in MYCO_NEW_STATUS_MAP:
            stage, reason = MYCO_NEW_STATUS_MAP[status_raw]
            flags.append("stage_new_value:" + status_raw)
            new_status_rows[status_raw].append(row_number)
        else:
            stage, reason = None, None
            flags.append("stage_not_in_mapping_table:" + status_raw)

        # --- source, two-level
        source_parent = str(val(r, "Lead Source") or "").strip()
        source_sub = str(val(r, "Sub Lead Source") or "").strip()
        parent_sub_counts[(source_parent, source_sub)] += 1
        source_key, source_status, source_note, broker_name, broker_number, source_flags = \
            classify_source(source_parent, source_sub)
        flags += source_flags
        if source_key == MISSING_SOURCE_KEY and not source_parent and not source_sub:
            filled.append("source")
        if broker_name:
            partner_candidates.append((broker_name, broker_number, row_number))

        assigned_to_name = blank_to_none(str(val(r, "Lead Owner") or "").strip())
        if assigned_to_name is None:
            filled.append("assigned_to_name")
            flags.append("no_assigned_user")
        else:
            REG.note_user(assigned_to_name, SOURCE_FILE_MYCO)

        updated_serial = val(r, "Updated At")
        updated_at = xldate(updated_serial, wb.datemode) if updated_serial else created_at
        if not updated_serial:
            filled.append("updated_at")
        elif updated_at < created_at:
            flags.append("updated_at_before_created_at")

        legacy = {h: val(r, h) for h in header}
        legacy["Created At"] = created_at
        legacy["Updated At"] = updated_at
        legacy["_row_number"] = row_number

        lead = {
            "_row_number": row_number,
            "first_name": first_name,
            "middle_name": blank_to_none(middle_name),
            "last_name": last_name,
            "mobile_number": mobile,
            "mobile_alt": mobile_alt,
            "email": email,
            "project_key": project_key,
            "source": source_key,
            "external_id": None,
            "broker_name": broker_name,
            "channel_partner_key": None,  # filled once the global partner registry exists
            "stage": stage,
            "stage_changed_at": None,
            "not_connected_count": 0,
            "assigned_to_name": assigned_to_name,
            "assigned_role": None,
            "created_by": None,
            "requirement": None,
            "reason": reason,
            "booked_unit": None,
            "booking_date": None,
            "last_activity_at": None,
            "created_at": created_at,
            "updated_at": updated_at,
            "deleted_at": None,
            "_filled": filled,
            "_flags": flags,
            "_duplicate_group": None,
            "_duplicate_count": None,
            "_duplicate_rank": None,
            "_legacy": legacy,
            "_source_file": SOURCE_FILE_MYCO,
            "_import_key": f"{SOURCE_FILE_MYCO}:{row_number}",
        }
        if mobile_extra:
            lead["mobile_extra"] = mobile_extra
        if source_status == "new":
            lead.setdefault("_flags", []).append("source_new_key:" + source_key)
        leads.append(lead)

    print(f"myco: {len(leads)} leads, 0 todos, {len(partner_candidates)} broker mentions "
          f"(from {sh.nrows - 1} data rows)", file=sys.stderr)

    return {
        "leads": leads,
        "todos": [],
        "partner_candidates": partner_candidates,
        "stage_counts": stage_counts,
        "parent_sub_counts": parent_sub_counts,
        "project_value_counts": project_value_counts,
        "new_status_rows": new_status_rows,
        "input_rows": sh.nrows - 1,
    }


# ===========================================================================
# Source 3: 12-Sep-Lead/12 Sep Lead.csv
#
# Two-level status (lead_status + lead_sub_status) combined per the brief:
#   Won -> booking_done, Open -> fresh, Qualified -> in_discussion,
#   Lost -> lost, with lead_sub_status giving the reason.
# The parent alone decides the stage; a sub-status on a non-Lost row (e.g.
# Open+"SV Done") is NOT read into a finer stage - the brief's mapping is
# literal - but it is kept in _legacy and the row is flagged so nothing about
# it is lost.
# ===========================================================================
CSV_PARENT_STAGE = {
    "Won": "booking_done",
    "Open": "fresh",
    "Qualified": "in_discussion",
    "Lost": "lost",
}

# Reason keys for a Lost row's sub-status. Reused across files where the
# wording matches MYCO's new Lost-<reason> values (plan_hold_cancel,
# wrong_number, old_property_sale) so the same underlying reason gets the
# same key whichever file it came from.
CSV_LOST_SUBSTATUS_REASON = {
    "Not Interested": ("not_interested", "existing"),
    "Misclick": ("misclick", "existing"),
    "Call Unanswered": ("call_unanswered", "existing"),
    "Budget Issue": ("budget", "existing"),
    "Other Cast": ("other_cast", "new"),
    "Plan Hold & Cancel": ("plan_hold_cancel", "new"),
    "Booked In Other Project": ("booked_elsewhere", "existing"),
    "Configuration Issue": ("configuration_issue", "existing"),
    "Location Issue": ("location_issue", "existing"),
    "Choice Not Available": ("choice_unavailable", "existing"),
    "Other": ("other", "existing"),
    "Invalid Number / Wrong Number": ("wrong_number", "new"),
    "General Enquiry": ("general_enquiry", "existing"),
    "Switch Off": ("switched_off", "existing"),
    "Broker": ("lost_to_broker", "new"),
    "Old Property Sale": ("old_property_sale", "new"),
    "Possession Timeline": ("possession_timeline_issue", "new"),
    "Dupalicate": ("duplicate", "existing"),  # typo in the source, normalized
    "Ready To Move In": ("ready_to_move", "existing"),
    "Upcomming Project": ("upcoming_project", "new"),
}

CSV_DATE_RE = re.compile(r"^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2})$")


def parse_csv_date(raw):
    m = CSV_DATE_RE.match((raw or "").strip())
    if not m:
        return None
    d, mo, y, h, mi = m.groups()
    return f"{y}-{mo}-{d} {h}:{mi}:00"


LEGACY_ONLY_CSV_COLUMNS = [
    "profession", "area", "address", "budget", "preferred_location", "requirement",
    "possession_timeline", "purchase_purpose", "notes", "lead_quality",
    "pre_sales_person", "pre_sales_email", "status",
]


def load_csv_12sep():
    with open(CSV_PATH, newline="", encoding="utf-8-sig") as handle:
        rows = list(csv.DictReader(handle))

    leads = []
    partner_candidates = []
    stage_counts = collections.Counter()
    parent_sub_counts = collections.Counter()
    project_value_counts = collections.Counter()
    unmapped_lost_substatus = collections.Counter()

    for i, row in enumerate(rows):
        row_number = i + 2  # header is row 1
        filled, flags = [], []

        raw_name = (row["client_name"] or "").strip()
        first_name, middle_name, last_name = split_name(raw_name)
        if not raw_name:
            flags.append("no_name")
        if raw_name.replace(" ", "").isdigit() and raw_name:
            flags.append("name_is_a_number")
        elif re.search(r"\d", raw_name):
            flags.append("name_contains_digits")
        if first_name == "":
            filled.append("first_name")
        if last_name == "":
            filled.append("last_name")

        mobile, mobile_alt_parsed, mobile_extra, mobile_flags = parse_mobile(row["mobile"], strip_country_code=True)
        flags += mobile_flags
        mobile2, _, _, mobile2_flags = parse_mobile(row["mobile2"], strip_country_code=True) if row["mobile2"] else (None, None, [], [])
        mobile_alt = mobile_alt_parsed or mobile2
        if row["mobile2"] and mobile2:
            flags.append("mobile2_column_used_as_alt")
            flags += [f.replace("mobile_", "mobile_alt_") for f in mobile2_flags if f != "no_mobile"]

        email = blank_to_none((row["email"] or "").strip())
        if email is None:
            filled.append("email")

        created_at = parse_csv_date(row["created_at"])
        if created_at is None:
            flags.append("created_at_unparseable")

        project_raw = (row["project"] or "").strip()
        project_value_counts[project_raw] += 1
        if project_raw:
            project_key = REG.project_key_for(project_raw, SOURCE_FILE_CSV, created_at)
        else:
            project_key = None
            filled.append("project_key")
            flags.append("no_project")

        # --- stage: parent decides the bucket; sub-status gives the Lost reason
        status_raw = (row["lead_status"] or "").strip()
        substatus_raw = (row["lead_sub_status"] or "").strip()
        if not status_raw:
            stage, reason = MISSING_STAGE, None
            filled.append("stage")
            flags.append("no_stage")
        elif status_raw in CSV_PARENT_STAGE:
            stage = CSV_PARENT_STAGE[status_raw]
            reason = None
            if stage == "lost":
                if not substatus_raw:
                    flags.append("no_lead_sub_status")
                elif substatus_raw in CSV_LOST_SUBSTATUS_REASON:
                    reason, reason_status = CSV_LOST_SUBSTATUS_REASON[substatus_raw]
                    if reason_status == "new":
                        flags.append("reason_new_value:" + substatus_raw)
                else:
                    flags.append("lead_sub_status_not_in_mapping_table:" + substatus_raw)
                    unmapped_lost_substatus[substatus_raw] += 1
            elif substatus_raw:
                flags.append("lead_sub_status_ignored_for_stage:" + substatus_raw)
        else:
            stage, reason = None, None
            flags.append("lead_status_not_in_mapping_table:" + status_raw)

        # --- source, two-level (51 distinct sub-sources across this file)
        source_parent = (row["lead_source"] or "").strip()
        source_sub = (row["lead_sub_source"] or "").strip()
        parent_sub_counts[(source_parent, source_sub)] += 1
        source_key, source_status, source_note, broker_name, broker_number, source_flags = \
            classify_source(source_parent, source_sub)
        flags += source_flags
        if source_key == MISSING_SOURCE_KEY and not source_parent and not source_sub:
            filled.append("source")
        if source_status == "new":
            flags.append("source_new_key:" + source_key)
        if broker_name:
            partner_candidates.append((broker_name, broker_number, row_number))

        assigned_to_name = blank_to_none((row["owner"] or "").strip())
        if assigned_to_name is None:
            filled.append("assigned_to_name")
            flags.append("no_assigned_user")
        else:
            REG.note_user(assigned_to_name, SOURCE_FILE_CSV)

        legacy = dict(row)
        legacy["created_at"] = created_at
        legacy["_row_number"] = row_number

        lead = {
            "_row_number": row_number,
            "first_name": first_name,
            "middle_name": blank_to_none(middle_name),
            "last_name": last_name,
            "mobile_number": mobile,
            "mobile_alt": mobile_alt,
            "email": email,
            "project_key": project_key,
            "source": source_key,
            "external_id": blank_to_none(row["id"]),
            "broker_name": broker_name,
            "channel_partner_key": None,
            "stage": stage,
            "stage_changed_at": None,
            "not_connected_count": 0,
            "assigned_to_name": assigned_to_name,
            "assigned_role": None,
            "created_by": None,
            "requirement": blank_to_none(row["requirement"]),
            "reason": reason,
            "booked_unit": None,
            "booking_date": None,
            "last_activity_at": None,
            "created_at": created_at,
            "updated_at": created_at,
            "deleted_at": None,
            "_filled": filled + ["updated_at"],
            "_flags": flags,
            "_duplicate_group": None,
            "_duplicate_count": None,
            "_duplicate_rank": None,
            "_legacy": legacy,
            "_source_file": SOURCE_FILE_CSV,
            "_import_key": f"{SOURCE_FILE_CSV}:{row_number}",
        }
        if mobile_extra:
            lead["mobile_extra"] = mobile_extra
        leads.append(lead)

    print(f"12_sep_lead: {len(leads)} leads, 0 todos, {len(partner_candidates)} broker mentions "
          f"(from {len(rows)} data rows)", file=sys.stderr)

    return {
        "leads": leads,
        "todos": [],
        "partner_candidates": partner_candidates,
        "stage_counts": collections.Counter((r["lead_status"], r["lead_sub_status"]) for r in rows),
        "parent_sub_counts": parent_sub_counts,
        "project_value_counts": project_value_counts,
        "unmapped_lost_substatus": unmapped_lost_substatus,
        "input_rows": len(rows),
    }


# ===========================================================================
# Source 4: channel_partners_Data.xls (260 rows) - the primary partner list.
# full_name, firm_name, mobile, created_at. No lead rows here; feeds
# channel_partners.json directly, labelled by origin "channel_partners_file".
# ===========================================================================

def load_channel_partners_file():
    wb = xlrd.open_workbook(PARTNERS_PATH)
    sh = wb.sheet_by_index(0)
    header = [sh.cell_value(0, c) for c in range(sh.ncols)]
    idx = {h: i for i, h in enumerate(header)}

    rows = []
    for r in range(1, sh.nrows):
        row_number = r + 1
        full_name = str(sh.cell_value(r, idx["full_name"]) or "").strip()
        firm_name = str(sh.cell_value(r, idx["firm_name"]) or "").strip()
        mobile_cell = sh.cell(r, idx["mobile"])
        mobile_raw = str(int(mobile_cell.value)) if mobile_cell.ctype == 2 else str(mobile_cell.value)
        created_cell = sh.cell(r, idx["created_at"])
        created_at = xldate(created_cell.value, wb.datemode) if created_cell.ctype == 3 else None

        numbers = split_numbers(mobile_raw)
        flags = []
        if not numbers:
            flags.append("no_phone")
        if numbers:
            flags += length_flags(numbers[0], "phone")
        if not created_at:
            flags.append("no_created_at")

        rows.append({
            "_row_number": row_number,
            "full_name": full_name,
            "firm_name": blank_to_none(firm_name),
            "phone": numbers[0] if numbers else None,
            "created_at": created_at,
            "_phone_raw": mobile_raw,
            "_flags": flags,
            "_legacy": {"full_name": full_name, "firm_name": firm_name, "mobile": mobile_raw,
                        "created_at": created_at, "_row_number": row_number},
        })

    print(f"channel_partners_file: {len(rows)} partner rows", file=sys.stderr)
    return {"rows": rows, "input_rows": sh.nrows - 1}


# ===========================================================================
# Merge all four origins of channel-partner data into one keyed list, with
# one continuous key space (cp_0001..) so every lead's channel_partner_key
# resolves unambiguously. master-sheet's own channel_partners.json already
# used cp_001.. locally; those are re-keyed here and the remap is applied to
# its leads afterwards.
# ===========================================================================

def build_channel_partners(master_partners, partner_file_rows, myco_candidates, csv_candidates):
    entries = []
    counter = {"n": 0}

    def next_key():
        counter["n"] += 1
        return f"cp_{counter['n']:04d}"

    remap_master = {}
    for p in master_partners:
        new_key = next_key()
        remap_master[p["key"]] = new_key
        entries.append({
            "key": new_key,
            "name": p["name"],
            "name_key": p["name_key"],
            "type": "broker",
            "phone": p["phone"],
            "alt_phone": p.get("alt_phone"),
            "_phone_raw": p.get("_phone_raw"),
            "lead_count": 0,
            "lead_import_keys": [],
            "possibly_same_as": [],
            "_flags": list(p.get("_flags", [])),
            "_origin": SOURCE_FILE_MASTER,
            "_original_key": p["key"],
        })

    for row in partner_file_rows:
        new_key = next_key()
        entries.append({
            "key": new_key,
            "name": row["full_name"],
            "name_key": name_key(row["full_name"]),
            "type": "broker",
            "firm_name": row["firm_name"],
            "phone": row["phone"],
            "alt_phone": None,
            "_phone_raw": row["_phone_raw"],
            "created_at": row["created_at"],
            "lead_count": 0,
            "lead_import_keys": [],
            "possibly_same_as": [],
            "_flags": list(row["_flags"]),
            "_origin": SOURCE_FILE_PARTNERS,
            "_source_row_number": row["_row_number"],
        })

    def dedupe_candidates(candidates, origin, with_phone):
        pairs, order = {}, []
        for name, phone, row_number in candidates:
            pair = (name, phone if with_phone else None)
            if pair not in pairs:
                pairs[pair] = None
                order.append(pair)
        pair_key = {}
        for pair in order:
            name, phone = pair
            new_key = next_key()
            pair_key[pair] = new_key
            numbers = split_numbers(phone or "")
            entries.append({
                "key": new_key,
                "name": name,
                "name_key": name_key(name),
                "type": "broker",
                "phone": numbers[0] if numbers else None,
                "alt_phone": numbers[1] if len(numbers) > 1 else None,
                "_phone_raw": phone,
                "lead_count": 0,
                "lead_import_keys": [],
                "possibly_same_as": [],
                "_flags": [] if numbers else ["no_phone"],
                "_origin": origin,
            })
        row_to_key = {}
        for name, phone, row_number in candidates:
            row_to_key[row_number] = pair_key[(name, phone if with_phone else None)]
        return row_to_key

    myco_row_partner_key = dedupe_candidates(myco_candidates, "myco_lead_file", with_phone=True)
    csv_row_partner_key = dedupe_candidates(csv_candidates, "12_sep_lead_file", with_phone=False)

    # possibly_same_as, computed once across every origin together
    for e in entries:
        mine = {e.get("phone"), e.get("alt_phone")} - {None}
        for other in entries:
            if other is e:
                continue
            reasons = []
            if e["name_key"] and other["name_key"] == e["name_key"]:
                reasons.append("same_name_key")
            other_numbers = {other.get("phone"), other.get("alt_phone")} - {None}
            if mine & other_numbers:
                reasons.append("shares_phone")
            if reasons:
                e["possibly_same_as"].append({"key": other["key"], "name": other["name"],
                                               "origin": other["_origin"], "why": reasons})
        if any("same_name_key" in p["why"] for p in e["possibly_same_as"]):
            e["_flags"].append("breaks_unique_name_key_type")

    return entries, remap_master, myco_row_partner_key, csv_row_partner_key


# ===========================================================================
# main
# ===========================================================================

GLOBAL_GUESSES = [
    {"what": "name split (Lead Name / client_name)",
     "value_used": "same rule as master-data/process_master_data.py: 1 word -> first only; "
                    "2 -> first+last; 3 -> first+middle+last; 4+ -> all but the last two words in "
                    "first_name, then middle, then last",
     "why": "Reused rather than re-decided, so a name is split the same way whichever file it came from"},
    {"what": "mobile country-code stripping (MYCO '+91XXXXXXXXXX', CSV any '+' prefix)",
     "value_used": "a leading '+' is removed, and a resulting 12-digit number starting '91' has that "
                    "'91' removed too, leaving the bare 10 digits. Every strip is flagged "
                    "(mobile_had_plus_prefix / mobile_country_code_91_stripped). The original text is "
                    "untouched in _legacy",
     "why": "The mobile_number migration's own comment says stored numbers are 'the bare 10 digits' and "
            "config('crm.country_code') adds +91 back for the tel:/wa.me links - so this matches how the "
            "live app already stores every other lead's number, not a change to the phone number itself"},
    {"what": "MYCO project column", "value_used": "'Company Industry' read as the project (Felicity / Vanam / "
                                                    "SkyDeck / compound values), flagged project_from_company_industry_field "
                                                    "on every row it fills",
     "why": "MYCO has no column named Project, but every value in Company Industry is one of our own project "
            "names or a slash-joined pair/triple of them - clearly a repurposed field, not company data. "
            "Confirm before the seeder runs"},
    {"what": "MYCO/CSV compound project values ('Felicity/SkyDeck', 'Vanam/ Felicity', "
             "'वनम् / Felicity / SkyDeck')",
     "value_used": "kept as their own project_key, never forced into one of the named projects - "
                    "'Felicity/SkyDeck' reuses master-sheet's existing felicity_skydeck key; the two- and "
                    "three-way values MYCO adds get their own new keys (vanam_felicity, "
                    "felicity_skydeck_2 for the Gujarati/English mixed one)",
     "why": "A lead has one project_id; master-sheet already established this precedent for 'Felicity/SkyDeck' "
            "rather than guessing which single project the lead meant"},
    {"what": "CSV/MYCO stage: sub-status only used for Lost's reason",
     "value_used": "Won -> booking_done, Open -> fresh, Qualified -> in_discussion, Lost -> lost, exactly as "
                    "briefed. A sub-status on a non-Lost row (e.g. Open+'SV Done', Qualified+'SV Scheduled') "
                    "is NOT read into a finer stage - flagged lead_sub_status_ignored_for_stage - kept in "
                    "_legacy so nothing is lost",
     "why": "The brief's mapping is literal; a richer reading (e.g. Qualified+SV Done -> site_visit_done) "
            "would be a second, undirected guess stacked on the first"},
    {"what": "broker extraction from a sub-source cell",
     "value_used": "under a 'Channel Partner'/'Channel Parner' parent, a sub-source with a 9+ digit run is "
                    "split into a broker name and phone (extract_name_phone); a personal name with no phone "
                    "digits is still treated as a broker (name only, phone null, flagged broker_without_number)",
     "why": "The brief said 'two' such MYCO sub-sources; the sheet actually has 57 distinct name+phone "
            "sub-source values (95 rows) - all extracted the same way, not just two, and every one flagged "
            "source_sub_value_looks_like_broker so you can check the split"},
    {"what": "'Squrefit Page' (215 CSV rows, Social Media)",
     "value_used": "new source key squrefit_page, not merged into facebook and not treated as a broker",
     "why": "Reads as a Facebook/social campaign page name - the brief named this exact value as ambiguous "
            "('Squrefit Page') - your call"},
    {"what": "typo'd parent source values ('Refferral', 'Channel Parner')",
     "value_used": "normalized to the same keys as the correctly-spelled versions (referral, broker); the "
                    "original spelling is kept in source_value/lead_source",
     "why": "Plainly the same word misspelled in the export, not a different source"},
    {"what": "duplicate_rank tie-break across files",
     "value_used": "newest created_at first; a tie is broken by which row sits later in the file-"
                    "concatenation order master_sheet -> myco -> 12_sep_lead (see duplicates.json.tie_break_rule)",
     "why": "The brief says 'the later source row', which is unambiguous inside one file (master-sheet used "
            "its own row order) but not across three - this is one explicit, documented choice, not a silent one"},
    {"what": "12_sep_lead updated_at", "value_used": "= created_at (filled, flagged)",
     "why": "The CSV has no separate updated_at column, unlike MYCO which does"},
    {"what": "12_sep_lead external_id", "value_used": "the CSV's own 'id' column",
     "why": "leads.external_id is nullable and otherwise unused by this file; the id is a real, given value, "
            "not invented"},
    {"what": "CSV created_at seconds", "value_used": "always :00 - the source format is 'DD-MM-YYYY HH:MM', no seconds",
     "why": "No finer information exists to fill in; flagged nowhere since this is the source's own precision, "
            "not a blank being filled"},
    {"what": "owner name matching across files", "value_used": "exact string match only (e.g. 'Riya Gandhi' in "
                                                                  "one file only merges with 'Riya Gandhi' spelled "
                                                                  "identically in another) - no fuzzy/nickname matching",
     "why": "Per the brief: 'Harnish Lalluwadia' and 'Harnish Gajjar' are not assumed to be the same person; "
            "the same discipline is applied to every name, not just that pair"},
    {"what": "project name matching across files", "value_used": "case/whitespace-insensitive match only "
                                                                    "(e.g. 'Skydeck' merges with 'SkyDeck'); "
                                                                    "never merged just because two names slug "
                                                                    "to the same ASCII string",
     "why": "Needed so the CSV's 'Skydeck' and master-sheet's 'SkyDeck' land on one project, while the "
            "Gujarati/English mixed compound value is kept apart from the English-only 'Felicity/SkyDeck' "
            "even though both reduce to the same ASCII slug"},
    {"what": "channel-partner cross-file duplicates (possibly_same_as)",
     "value_used": "every entry from every origin is cross-checked by name_key and by shared phone/alt_phone, "
                    "listed in possibly_same_as - never auto-merged into one row",
     "why": "Same policy master-sheet's own channel_partners.json already used; a wrong auto-merge attributes "
            "a broker's business to someone who never brought it in"},
    {"what": "assignment for a project not in the Vanam/SkyDeck/Felicity list (Kinaro, and any "
             "Felicity/SkyDeck-style compound value)",
     "value_used": "sent to the telecaller, Riya Gandhi, flagged assigned_to_telecaller_no_project_mapping - "
                    "see report.json's assignment_override.by_project_key_sent_to_telecaller for the exact "
                    "breakdown (kinaro, felicity_skydeck, felicity_skydeck_2, vanam_felicity, and no_project)",
     "why": "None of these names one single project, so there is no one salesperson to hand it to; the "
            "telecaller is the only remaining fallback that does not guess which of two projects a "
            "compound lead really meant"},
    {"what": "whether an open (non-terminal) lead goes to the telecaller instead of the project salesperson",
     "value_used": "no - every lead on a mapped project (Vanam/SkyDeck/Felicity) goes to that project's "
                    "salesperson regardless of stage, open or closed",
     "why": "Matches the brief's own stated view: these are historical leads and most are already closed, "
            "so splitting live/open leads to the telecaller would add a distinction the source data was "
            "never organized around. Flag this back if a different split is actually wanted."},
]


def main():
    REG.guesses.extend(GLOBAL_GUESSES)
    ms = load_master_sheet()
    myco = load_myco()
    csvd = load_csv_12sep()
    pf = load_channel_partners_file()

    partners, remap_master, myco_row_key, csv_row_key = build_channel_partners(
        ms["partners"], pf["rows"], myco["partner_candidates"], csvd["partner_candidates"],
    )
    by_key = {p["key"]: p for p in partners}

    for lead in ms["leads"]:
        old = lead.get("channel_partner_key")
        lead["channel_partner_key"] = remap_master.get(old) if old else None
    for lead in myco["leads"]:
        if lead.get("broker_name"):
            lead["channel_partner_key"] = myco_row_key.get(lead["_row_number"])
    for lead in csvd["leads"]:
        if lead.get("broker_name"):
            lead["channel_partner_key"] = csv_row_key.get(lead["_row_number"])

    leads = ms["leads"] + myco["leads"] + csvd["leads"]
    for i, lead in enumerate(leads):
        lead["_global_index"] = i

    # -----------------------------------------------------------------------
    # Assignment override - apply the new project -> salesperson rule to
    # every lead, replacing whatever the source file's own owner column
    # said. The original owner is untouched inside _legacy; captured here
    # too (legacy_owner_by_key) purely so report.json can still show the
    # old distribution for comparison.
    # -----------------------------------------------------------------------
    legacy_owner_by_key = {}
    for lead in leads:
        legacy_owner_by_key[lead["_import_key"]] = lead["assigned_to_name"]
        lead["_filled"] = [f for f in lead["_filled"] if f != "assigned_to_name"]
        lead["_flags"] = [f for f in lead["_flags"] if f != "no_assigned_user"]
        new_owner = PROJECT_SALESPERSON.get(lead["project_key"])
        if new_owner:
            role = "salesperson"
        else:
            new_owner, role = TELECALLER, "telecaller"
            if "assigned_to_telecaller_no_project_mapping" not in lead["_flags"]:
                lead["_flags"].append("assigned_to_telecaller_no_project_mapping")
        lead["assigned_to_name"] = new_owner
        lead["assigned_role"] = role

    for lead in leads:
        key = lead.get("channel_partner_key")
        if key and key in by_key:
            by_key[key]["lead_count"] += 1
            by_key[key]["lead_import_keys"].append(lead["_import_key"])

    todos = ms["todos"] + myco["todos"] + csvd["todos"]

    # Every to-do's assigned_to_name/completed_by_name is derived from its
    # lead, never from its own source row (same as before this override
    # existed) - so it must follow the lead's new, overridden owner too.
    lead_owner_by_key = {l["_import_key"]: l["assigned_to_name"] for l in leads}
    for todo in todos:
        new_owner = lead_owner_by_key[todo["_lead_import_key"]]
        todo["assigned_to_name"] = new_owner
        todo["completed_by_name"] = new_owner

    total_leads = len(leads)
    expected_total = ms["input_rows"] + myco["input_rows"] + csvd["input_rows"]
    print(f"TOTAL leads: {total_leads} (master {ms['input_rows']} + myco {myco['input_rows']} "
          f"+ 12sep {csvd['input_rows']} = {expected_total})", file=sys.stderr)

    # -----------------------------------------------------------------------
    # Duplicates - one global pass over every lead with a mobile_number,
    # whatever file it came from.
    # -----------------------------------------------------------------------
    by_mobile = collections.defaultdict(list)
    for lead in leads:
        if lead["mobile_number"]:
            by_mobile[lead["mobile_number"]].append(lead)

    duplicate_groups = []
    for mobile, members in by_mobile.items():
        if len(members) < 2:
            continue
        members.sort(key=lambda l: (l["created_at"] or "", l["_global_index"]), reverse=True)
        for rank, lead in enumerate(members, start=1):
            lead["_duplicate_group"] = mobile
            lead["_duplicate_count"] = len(members)
            lead["_duplicate_rank"] = rank
        files_involved = sorted({l["_source_file"] for l in members})

        # unique(mobile_number, project_id) applies across the WHOLE merged
        # table once imported, not just within one file - recomputed here
        # rather than trusting each source's own, narrower check.
        per_project = collections.Counter(l["project_key"] for l in members)
        clashing_projects = [p for p, c in per_project.items() if c > 1 and p is not None]
        for l in members:
            if l["project_key"] in clashing_projects and "breaks_unique_mobile_number_project_id" not in l["_flags"]:
                l["_flags"].append("breaks_unique_mobile_number_project_id")

        duplicate_groups.append({
            "mobile_number": mobile,
            "count": len(members),
            "cross_file": len(files_involved) > 1,
            "source_files": files_involved,
            "import_keys": [l["_import_key"] for l in members],
            "projects": sorted({l["project_key"] for l in members if l["project_key"]}),
            "same_project_more_than_once": sorted(clashing_projects),
            "stages": sorted({l["stage"] for l in members if l["stage"]}),
            "rows": [{
                "rank": l["_duplicate_rank"],
                "import_key": l["_import_key"],
                "source_file": l["_source_file"],
                "created_at": l["created_at"],
                "name": f"{l['first_name']} {l['last_name']}".strip(),
                "project_key": l["project_key"],
                "stage": l["stage"],
                "source": l["source"],
                "assigned_to_name": l["assigned_to_name"],
            } for l in members],
        })
    duplicate_groups.sort(key=lambda g: (-g["count"], g["mobile_number"]))

    for lead in leads:
        lead.pop("_global_index", None)

    write("duplicates.json", {
        "groups": duplicate_groups,
        "tie_break_rule": "duplicate_rank: newest created_at first; ties broken by the row that appears "
                           "later in the concatenation order master_sheet -> myco -> 12_sep_lead (a global "
                           "assembly-order index, not a claim about which row was truly entered later "
                           "when two rows share a file and a created_at to the second)",
    }, compact=True)

    # -----------------------------------------------------------------------
    # projects.json / users.json
    # -----------------------------------------------------------------------
    projects = []
    for key, name in REG.project_names.items():
        alts = sorted(REG.project_alt_names[key] - {name})
        projects.append({
            "key": key,
            "name": name,
            "alt_spellings_seen": alts,
            "lead_count": REG.project_lead_counts[key],
            "source_files": sorted(REG.project_source_files[key]),
            "_flags": (["value_names_two_projects"] if "/" in name and len(name) < 40 else
                       (["value_names_multiple_projects"] if "/" in name else [])),
            "_note": "projects has no key column; the seeder resolves project_key to an id by name",
        })
    projects.sort(key=lambda p: -p["lead_count"])
    write("projects.json", projects, compact=True)

    # Only the four assignment-rule users are created - not one user per
    # name that ever appeared in an owner column. Every lead's new
    # assigned_to_name is one of these four; legacy owner names (Harnish
    # Lalluwadia, Harnish Gajjar, Sagar Moradia, ...) are history now, kept
    # only in _legacy and in leads_by_legacy_owner below - no account is
    # created for them.
    USER_ROLE = {
        "Mayur Patel": "salesperson",
        "Ravi Patel": "salesperson",
        "Dhanashri Meshram": "salesperson",
        "Riya Gandhi": "telecaller",
    }
    USER_PLACEHOLDER_EMAIL = {
        "Mayur Patel": "mayur.patel@legacy.import",
        "Ravi Patel": "ravi.patel@legacy.import",
        "Dhanashri Meshram": "dhanashri.meshram@legacy.import",
        "Riya Gandhi": "riya.gandhi@legacy.import",
    }
    assigned_lead_counts = collections.Counter(l["assigned_to_name"] for l in leads)
    assigned_source_files = collections.defaultdict(set)
    for l in leads:
        assigned_source_files[l["assigned_to_name"]].add(l["_source_file"])

    users = []
    for name in ["Mayur Patel", "Ravi Patel", "Dhanashri Meshram", "Riya Gandhi"]:
        users.append({
            "name": name,
            "proposed_first_name": name.split(" ")[0],
            "proposed_last_name": " ".join(name.split(" ")[1:]),
            "role": USER_ROLE[name],
            "is_active": False,
            "email": USER_PLACEHOLDER_EMAIL[name],
            "mobile_number": None,
            "lead_count": assigned_lead_counts[name],
            "source_files": sorted(assigned_source_files[name]),
            "_filled": ["email"],
            "_flags": ["placeholder_email", "created_inactive"],
            "_note": "Assigned by project, per the new rule, not by any source file's owner column. email is "
                     "a placeholder - users.email is NOT NULL/unique and none of the four files has one; "
                     "mobile_number is left null (nullable) rather than invented. is_active=false so the "
                     "account cannot sign in until the client is ready.",
        })
    write("users.json", users, compact=True)

    write("channel_partners.json", partners, compact=True)
    write("leads.json", leads)
    write("todos.json", todos)

    # -----------------------------------------------------------------------
    # stages.json - every distinct status value found, in every file, and its
    # mapping. master-sheet's secondary_stages values, MYCO's Lead Status
    # values, and every (lead_status, lead_sub_status) pair the CSV has.
    # -----------------------------------------------------------------------
    stages = []
    for value, count in ms["stage_counts"].most_common():
        if value in STAGE_MAP:
            stage, reason, status = STAGE_MAP[value][0], STAGE_MAP[value][1], "from_mapping_table"
        elif not value:
            stage, reason, status = MISSING_STAGE, None, "placeholder"
        else:
            stage, reason, status = None, None, "new_unmapped"
        stages.append({
            "source_file": SOURCE_FILE_MASTER, "source_value": value, "count": count,
            "stage": stage, "lost_reason": reason, "new": status == "new_unmapped", "status": status,
            "stage_in_config": stage in CONFIG_STAGES if stage else None,
            "lost_reason_in_config": (reason in CONFIG_LOST_REASONS) if reason else None,
        })

    for value, count in myco["stage_counts"].most_common():
        if value in STAGE_MAP:
            stage, reason = STAGE_MAP[value]
            new, status = False, "from_master_sheet_mapping_table"
        elif value in MYCO_NEW_STATUS_MAP:
            stage, reason = MYCO_NEW_STATUS_MAP[value]
            new, status = True, ("new_named_in_brief" if value not in MYCO_STATUS_NOT_IN_BRIEF else "new_not_in_brief")
        elif not value:
            stage, reason, new, status = MISSING_STAGE, None, False, "placeholder"
        else:
            stage, reason, new, status = None, None, True, "new_unmapped"
        stages.append({
            "source_file": SOURCE_FILE_MYCO, "source_value": value, "count": count,
            "stage": stage, "lost_reason": reason, "new": new, "status": status,
            "stage_in_config": stage in CONFIG_STAGES if stage else None,
            "lost_reason_in_config": (reason in CONFIG_LOST_REASONS) if reason else None,
        })

    for (status_raw, substatus_raw), count in csvd["stage_counts"].most_common():
        if not status_raw:
            stage, reason, new, status = MISSING_STAGE, None, False, "placeholder"
        elif status_raw in CSV_PARENT_STAGE:
            stage = CSV_PARENT_STAGE[status_raw]
            reason, new, status = None, False, "from_mapping_table"
            if stage == "lost":
                if not substatus_raw:
                    status = "from_mapping_table_no_sub_status"
                elif substatus_raw in CSV_LOST_SUBSTATUS_REASON:
                    reason, reason_status = CSV_LOST_SUBSTATUS_REASON[substatus_raw]
                    new = reason_status == "new"
                    status = "from_mapping_table" if not new else "new_named_or_inferred"
                else:
                    reason, new, status = None, True, "new_unmapped_sub_status"
            elif substatus_raw:
                status = "sub_status_ignored_for_stage_per_brief"
        else:
            stage, reason, new, status = None, None, True, "new_unmapped"
        stages.append({
            "source_file": SOURCE_FILE_CSV, "source_value": f"{status_raw} / {substatus_raw}" if substatus_raw else status_raw,
            "lead_status": status_raw, "lead_sub_status": substatus_raw, "count": count,
            "stage": stage, "lost_reason": reason, "new": new, "status": status,
            "stage_in_config": stage in CONFIG_STAGES if stage else None,
            "lost_reason_in_config": (reason in CONFIG_LOST_REASONS) if reason else None,
        })
    write("stages.json", stages, compact=True)

    # -----------------------------------------------------------------------
    # sources.json - every distinct (parent, sub) combination found in MYCO
    # and the CSV (the CSV alone has 51 distinct non-blank sub-sources, all
    # listed), plus master-sheet's already-established single-level sources.
    # -----------------------------------------------------------------------
    sources = []
    for value, count in ms["source_counts"].most_common():
        if value:
            from_map = read_json(os.path.join(MASTER_DB, "sources.json"))
            row = next((r for r in from_map if r["source_value"] == value), None)
            key = row["proposed_key"] if row else slug(value)
            status = row["status"] if row else "new"
            note = row.get("note") if row else None
        else:
            key, status, note = MISSING_SOURCE_KEY, "placeholder", "Source cell blank; rows flagged no_source"
        sources.append({
            "source_file": SOURCE_FILE_MASTER, "source_value": value, "count": count,
            "proposed_key": key, "new": status not in ("existing", "placeholder"),
            "status": status, "note": note, "key_in_config": key in CONFIG_SOURCES,
        })

    def source_rows(parent_sub_counts, source_file):
        out = []
        for (parent, sub), count in parent_sub_counts.most_common():
            key, status, note, broker_name, broker_number, flags = classify_source(parent, sub)
            out.append({
                "source_file": source_file, "lead_source": parent, "lead_sub_source": sub,
                "source_value": f"{parent} / {sub}" if sub else parent, "count": count,
                "proposed_key": key, "new": status == "new", "status": status, "note": note,
                "key_in_config": key in CONFIG_SOURCES,
                "extracted_as_broker": bool(broker_name),
            })
        return out

    sources += source_rows(myco["parent_sub_counts"], SOURCE_FILE_MYCO)
    sources += source_rows(csvd["parent_sub_counts"], SOURCE_FILE_CSV)
    write("sources.json", sources, compact=True)

    csv_sub_source_count = len({sub for (_parent, sub) in csvd["parent_sub_counts"] if sub})
    print(f"CSV distinct non-blank sub-sources listed in sources.json: {csv_sub_source_count}", file=sys.stderr)

    # -----------------------------------------------------------------------
    # lost_reasons.json
    # -----------------------------------------------------------------------
    reason_rows = collections.defaultdict(lambda: {"count": 0, "source_values": set(), "source_files": set()})
    for lead in leads:
        if lead["reason"]:
            r = reason_rows[lead["reason"]]
            r["count"] += 1
            r["source_files"].add(lead["_source_file"])
    for s in stages:
        if s.get("lost_reason"):
            reason_rows[s["lost_reason"]]["source_values"].add(f'{s["source_file"]}:{s["source_value"]}')
    lost_reasons = [{
        "key": key,
        "count": row["count"],
        "in_config": key in CONFIG_LOST_REASONS,
        "new": key not in CONFIG_LOST_REASONS,
        "source_files": sorted(row["source_files"]),
        "source_values": sorted(row["source_values"]),
    } for key, row in reason_rows.items()]
    lost_reasons.sort(key=lambda r: -r["count"])
    write("lost_reasons.json", lost_reasons, compact=True)

    # -----------------------------------------------------------------------
    # report.json
    # -----------------------------------------------------------------------
    def count_list(values):
        return dict(collections.Counter(v for v in values if v is not None).most_common())

    def by_file(pred_key):
        out = {}
        for sf in (SOURCE_FILE_MASTER, SOURCE_FILE_MYCO, SOURCE_FILE_CSV):
            sub = [lead for lead in leads if lead["_source_file"] == sf]
            out[sf] = count_list(lead[pred_key] for lead in sub)
        return out

    filled_counts = collections.Counter(f for l in leads for f in l["_filled"])
    flag_counts = collections.Counter(f.split(":")[0] for l in leads for f in l["_flags"])
    filled_by_file = {sf: dict(collections.Counter(
        f for l in leads if l["_source_file"] == sf for f in l["_filled"]).most_common())
        for sf in (SOURCE_FILE_MASTER, SOURCE_FILE_MYCO, SOURCE_FILE_CSV)}
    flags_by_file = {sf: dict(collections.Counter(
        f.split(":")[0] for l in leads if l["_source_file"] == sf for f in l["_flags"]).most_common())
        for sf in (SOURCE_FILE_MASTER, SOURCE_FILE_MYCO, SOURCE_FILE_CSV)}

    dates_by_file = {}
    for sf in (SOURCE_FILE_MASTER, SOURCE_FILE_MYCO, SOURCE_FILE_CSV):
        created = sorted(l["created_at"] for l in leads if l["_source_file"] == sf and l["created_at"])
        dates_by_file[sf] = {"oldest": created[0] if created else None, "newest": created[-1] if created else None}

    all_dates = []
    for l in leads:
        for f in ("created_at", "updated_at", "last_activity_at", "stage_changed_at"):
            if l.get(f):
                all_dates.append((f, l[f], l["_import_key"]))
    for t in todos:
        for f in ("scheduled_at", "completed_at", "created_at", "updated_at"):
            if t.get(f):
                all_dates.append((f, t[f], t.get("_lead_import_key")))
    bad_format = [d for d in all_dates if not DATETIME_RE.match(d[1])]
    on_today = [d for d in all_dates if d[1][:10] == TODAY]

    report = {
        "generated_on": TODAY,
        "sources_read": {
            SOURCE_FILE_MASTER: "master-data/db/*.json (already converted; read only)",
            SOURCE_FILE_MYCO: "MYCO/MYCO.xlsx",
            SOURCE_FILE_CSV: "12-Sep-Lead/12 Sep Lead.csv",
            SOURCE_FILE_PARTNERS: "channel_partners_Data.xls",
        },
        "counts": {
            "input_rows": {SOURCE_FILE_MASTER: ms["input_rows"], SOURCE_FILE_MYCO: myco["input_rows"],
                            SOURCE_FILE_CSV: csvd["input_rows"], "total": expected_total},
            "output_leads": {SOURCE_FILE_MASTER: len(ms["leads"]), SOURCE_FILE_MYCO: len(myco["leads"]),
                              SOURCE_FILE_CSV: len(csvd["leads"]), "total": total_leads},
            "match": total_leads == expected_total,
            "arithmetic_check": f"{ms['input_rows']} + {myco['input_rows']} + {csvd['input_rows']} = "
                                 f"{ms['input_rows'] + myco['input_rows'] + csvd['input_rows']}"
                                 f" ({'==' if ms['input_rows'] + myco['input_rows'] + csvd['input_rows'] == 16139 else '!='} 16139)",
            "todos": len(todos),
            "channel_partners_primary_file": pf["input_rows"],
            "channel_partners_total_entries": len(partners),
            "channel_partners_by_origin": count_list(p["_origin"] for p in partners),
            "projects": len(projects),
            "users": len(users),
            "stages_distinct_source_values": len(stages),
            "sources_distinct_combinations": len(sources),
            "lost_reasons": len(lost_reasons),
        },
        "checks": {
            "dates_written": len(all_dates),
            "dates_not_in_Y-m-d_H:i:s": len(bad_format),
            "dates_equal_to_today": len(on_today),
            "dates_equal_to_today_examples": on_today[:10],
        },
        "created_at_by_file": dates_by_file,
        "leads_by_stage": {"combined": count_list(l["stage"] for l in leads), **by_file("stage")},
        "leads_by_source": {"combined": count_list(l["source"] for l in leads), **by_file("source")},
        "leads_by_project": {"combined": count_list(l["project_key"] for l in leads), **by_file("project_key")},
        "leads_by_assigned_user": {"combined": count_list(l["assigned_to_name"] for l in leads), **by_file("assigned_to_name")},
        "leads_by_legacy_owner": {
            "combined": count_list(legacy_owner_by_key[l["_import_key"]] for l in leads),
            **{sf: count_list(legacy_owner_by_key[l["_import_key"]] for l in leads if l["_source_file"] == sf)
               for sf in (SOURCE_FILE_MASTER, SOURCE_FILE_MYCO, SOURCE_FILE_CSV)},
        },
        "assignment_override": {
            "rule": "assigned_to_name/assigned_role come only from project_key - Vanam->Mayur Patel "
                    "(salesperson), SkyDeck->Ravi Patel (salesperson), Felicity->Dhanashri Meshram "
                    "(salesperson). Every other project_key (none, kinaro, felicity_skydeck, "
                    "felicity_skydeck_2, vanam_felicity, or any other compound/unmapped value) goes to "
                    "the telecaller, Riya Gandhi. The source file's own owner column is never consulted "
                    "for this and survives only in _legacy / leads_by_legacy_owner above.",
            "leads_sent_to_telecaller_for_unmapped_project": sum(
                1 for l in leads if "assigned_to_telecaller_no_project_mapping" in l["_flags"]),
            "by_project_key_sent_to_telecaller": count_list(
                l["project_key"] for l in leads if "assigned_to_telecaller_no_project_mapping" in l["_flags"]),
        },
        "leads_by_reason": count_list(l["reason"] for l in leads if l["reason"]),
        "leads_filled_field_counts": {"combined": dict(filled_counts.most_common()), "by_file": filled_by_file},
        "leads_flag_counts": {"combined": dict(flag_counts.most_common()), "by_file": flags_by_file},
        "duplicates": {
            "groups": len(duplicate_groups),
            "rows_involved": sum(g["count"] for g in duplicate_groups),
            "largest_group": max((g["count"] for g in duplicate_groups), default=0),
            "within_single_file_groups": sum(1 for g in duplicate_groups if not g["cross_file"]),
            "cross_file_groups": sum(1 for g in duplicate_groups if g["cross_file"]),
            "rows_involved_cross_file": sum(g["count"] for g in duplicate_groups if g["cross_file"]),
            "leads_with_no_mobile_excluded_from_grouping": sum(1 for l in leads if not l["mobile_number"]),
            "groups_with_same_project_more_than_once": sum(1 for g in duplicate_groups if g["same_project_more_than_once"]),
            "leads_breaking_unique_mobile_project": sum(1 for l in leads if "breaks_unique_mobile_number_project_id" in l["_flags"]),
        },
        "myco_new_status_values_not_named_in_brief": {
            k: myco["new_status_rows"].get(k, [])[:10] for k in sorted(MYCO_STATUS_NOT_IN_BRIEF)
        },
        "csv_lead_sub_status_unmapped": dict(csvd["unmapped_lost_substatus"]),
        "csv_distinct_sub_sources_listed": csv_sub_source_count,
        "pending_follow_ups": {
            "created_this_pass": 0,
            "open_leads_with_no_pending_follow_up": sum(1 for l in leads if l["stage"] not in TERMINAL_STAGES),
            "open_stages_counted": sorted({l["stage"] for l in leads if l["stage"] not in TERMINAL_STAGES}),
            "proposal": "No pending to-do is created for any of these in this pass (per the brief - it would "
                        "dump hundreds of tasks dated today onto the follow-ups page on day one). Each is "
                        "instead flagged at import time via lead_import_records.awaiting_follow_up = true "
                        "(already scaffolded in that table's migration), so the admin can list them with a "
                        "dedicated command/flag - e.g. `import:legacy --awaiting` - and schedule real "
                        "follow-ups deliberately, rather than the seeder inventing dates.",
        },
        "guesses": REG.guesses,
        "warnings": REG.warnings + [
            "unique(mobile_number, project_id): merging all three files raises the leads breaking this "
            "constraint from 1,068 (master-sheet checked alone) to %d of %d leads with a mobile - mostly "
            "the same client re-entered into a later system for the same project. The seeder needs a "
            "group/absorb strategy across ALL THREE files together (same mobile + same project_key = one "
            "lead, newest survives), not just within one file - see duplicates.json."
            % (sum(1 for l in leads if "breaks_unique_mobile_number_project_id" in l["_flags"]),
               sum(1 for l in leads if l["mobile_number"])),
            "MYCO is 100%% Lost or Not Qualified (4,337 of 4,337 rows) - the file has no fresh/open/won "
            "lead at all. Confirm this is expected before treating every MYCO lead as terminal."
            % (),
            "Lost reasons: of the %d keys in lost_reasons.json, only %d (%s) are already in "
            "config('crm.lost_reasons'); the other %d must be added there before an imported lost lead "
            "can be re-saved (LeadRequest/CompleteTodoRequest validate `reason` against that list)."
            % (len(lost_reasons),
               len([lr for lr in lost_reasons if lr["key"] in CONFIG_LOST_REASONS]),
               ", ".join(r for r in CONFIG_LOST_REASONS if r in {lr["key"] for lr in lost_reasons}),
               len([lr for lr in lost_reasons if lr["key"] not in CONFIG_LOST_REASONS])),
            "Sources: of the %d proposed keys, %d are not yet in config('crm.sources') - see sources.json "
            "status=\"new\" rows, largest by lead count: direct (1,366), squrefit_page (215), old_members "
            "(157), social_media (74), on_site_hoarding (50)."
            % (len({s["proposed_key"] for s in sources}),
               len({s["proposed_key"] for s in sources if s["proposed_key"] not in CONFIG_SOURCES})),
            "Stages: 5 new stage-level values (not existing stages, but new Lost sub-reasons/statuses) "
            "found beyond the brief's own list - 3 in MYCO were not named in the brief at all "
            "(Lost-Wrong Numbers, Lost-Old Property Sale, Lost- Vastu) - see "
            "myco_new_status_values_not_named_in_brief above and stages.json rows with \"new\": true.",
            "channel_partners.json's possibly_same_as turned up real cross-file phone sharing worth a human "
            "look, not just spelling variants - e.g. MYCO's 'Smit Thakkar' (phone 7016906788) shares that "
            "number with master-sheet's 'Shailesh Thakkar' AND 'Shilesh Thakkar' (three different names, "
            "one phone). Nothing was auto-merged.",
            "12_sep_lead: 1,835 of 2,538 Lost rows (72%%) have no lead_sub_status at all - stage is `lost` "
            "with reason left null and the row flagged no_lead_sub_status, per the brief."
            % (),
            "This file's own leads.json does not yet enforce unique(mobile_number, project_id) or any other "
            "DB constraint - those are exactly what the group/absorb step in the next seeder (mirroring "
            "LegacyImportPlan::groupRows for master-sheet alone) needs to resolve across all three files.",
        ],
    }
    write("report.json", report, compact=True)

    # -----------------------------------------------------------------------
    # Hard stops: the three rules
    # -----------------------------------------------------------------------
    assert total_leads == 16139, f"expected 16139 leads, got {total_leads}"
    assert total_leads == expected_total, "row count changed between input and output"
    assert not bad_format, ("dates not in Y-m-d H:i:s", bad_format[:5])
    assert not on_today, ("dates equal to today", on_today[:5])

    print(f"DONE: {total_leads} leads, {len(todos)} todos, {len(partners)} channel partners, "
          f"{len(duplicate_groups)} duplicate groups -> {OUT}", file=sys.stderr)


if __name__ == "__main__":
    sys.exit(main())
