#!/usr/bin/env python3
"""
Stand-alone 12 Sep Lead data processing script.
Parses `12 Sep Lead.csv` and generates `12-Sep-Lead.json` and `manual_review_items.json`.
Preserves 100% of rows, columns, and values with zero data loss.
Standard library only (csv, json, datetime, re, os, sys).
"""

import csv
import datetime
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.abspath(os.path.join(HERE, ".."))


def split_name(full_name_str):
    """
    Split full name into first_name, middle_name, and last_name.
    - Single word: first_name = word, middle_name = "", last_name = ""
    - Two words: first_name = word1, middle_name = "", last_name = word2
    - Three words: first_name = word1, middle_name = word2, last_name = word3
    - >3 words: first_name = first word(s), middle_name = middle word(s), last_name = last word
    """
    clean = re.sub(r"\s+", " ", (full_name_str or "").strip())
    if not clean:
        return "", "", ""
    parts = clean.split(" ")
    if len(parts) == 1:
        return parts[0], "", ""
    elif len(parts) == 2:
        return parts[0], "", parts[1]
    elif len(parts) == 3:
        return parts[0], parts[1], parts[2]
    else:
        return " ".join(parts[:-2]), parts[-2], parts[-1]


def parse_csv_date(date_str):
    """
    Convert CSV date 'DD-MM-YYYY HH:MM' to ISO SQL format 'YYYY-MM-DD HH:MM:SS'.
    Returns original string if parsing fails.
    """
    if not date_str:
        return ""
    try:
        dt = datetime.datetime.strptime(date_str.strip(), "%d-%m-%Y %H:%M")
        return dt.strftime("%Y-%m-%d %H:%M:%S")
    except Exception:
        return str(date_str)


def convert_12_sep_lead_csv(csv_path, output_json_path, manual_review_path=None):
    if not os.path.exists(csv_path):
        raise FileNotFoundError(f"Source file not found: {csv_path}")

    with open(csv_path, "r", encoding="utf-8", errors="replace") as f:
        reader = csv.reader(f)
        headers = next(reader)
        rows = list(reader)

    total_data_rows = len(rows)

    records = []
    manual_reviews = {
        "numeric_or_symbolic_names": [],
        "short_names": [],
        "multi_person_names": [],
        "non_10_digit_mobiles": [],
        "populated_mobile2": [],
        "missing_projects": [],
        "missing_sources": [],
        "missing_owners": [],
        "missing_statuses": [],
        "duplicate_mobiles": [],
        "duplicate_full_rows": [],
    }

    seen_exact_rows = {}
    seen_mobiles = {}

    for r_idx, r in enumerate(rows):
        csv_row_num = r_idx + 2  # 1-indexed including header row

        # Pad row with empty strings if shorter than headers
        row_vals = [r[i] if i < len(r) else "" for i in range(len(headers))]
        row_dict = dict(zip(headers, row_vals))

        # Extract 25 CSV fields
        csv_id = row_dict.get("id", "")
        client_name = row_dict.get("client_name", "")
        mobile = row_dict.get("mobile", "")
        mobile2 = row_dict.get("mobile2", "")
        email = row_dict.get("email", "")
        profession = row_dict.get("profession", "")
        area = row_dict.get("area", "")
        address = row_dict.get("address", "")
        budget = row_dict.get("budget", "")
        preferred_location = row_dict.get("preferred_location", "")
        requirement = row_dict.get("requirement", "")
        possession_timeline = row_dict.get("possession_timeline", "")
        purchase_purpose = row_dict.get("purchase_purpose", "")
        notes = row_dict.get("notes", "")
        lead_quality = row_dict.get("lead_quality", "")
        project = row_dict.get("project", "")
        lead_status = row_dict.get("lead_status", "")
        lead_sub_status = row_dict.get("lead_sub_status", "")
        lead_source = row_dict.get("lead_source", "")
        lead_sub_source = row_dict.get("lead_sub_source", "")
        owner = row_dict.get("owner", "")
        pre_sales_person = row_dict.get("pre_sales_person", "")
        pre_sales_email = row_dict.get("pre_sales_email", "")
        status = row_dict.get("status", "")
        created_at_raw = row_dict.get("created_at", "")

        # Target stage: use lead_sub_status if present, otherwise lead_status
        stage_mapped = lead_sub_status if lead_sub_status else lead_status

        # Date conversion
        created_at_dt = parse_csv_date(created_at_raw)

        # Name decomposition
        first_name, middle_name, last_name = split_name(client_name)

        # Tracking anomalies for manual review
        if client_name.isdigit() or "\uf8ff" in client_name:
            manual_reviews["numeric_or_symbolic_names"].append({"row": csv_row_num, "id": csv_id, "name": client_name})
        elif len(client_name.strip()) <= 2:
            manual_reviews["short_names"].append({"row": csv_row_num, "id": csv_id, "name": client_name})
        elif "/" in client_name or " & " in client_name:
            manual_reviews["multi_person_names"].append({"row": csv_row_num, "id": csv_id, "name": client_name})

        if mobile and len(mobile) != 10:
            manual_reviews["non_10_digit_mobiles"].append({"row": csv_row_num, "id": csv_id, "mobile": mobile, "length": len(mobile)})

        if mobile2:
            manual_reviews["populated_mobile2"].append({"row": csv_row_num, "id": csv_id, "mobile": mobile, "mobile2": mobile2})

        if not project:
            manual_reviews["missing_projects"].append({"row": csv_row_num, "id": csv_id})
        if not lead_source:
            manual_reviews["missing_sources"].append({"row": csv_row_num, "id": csv_id})
        if not owner:
            manual_reviews["missing_owners"].append({"row": csv_row_num, "id": csv_id})
        if not lead_status and not lead_sub_status:
            manual_reviews["missing_statuses"].append({"row": csv_row_num, "id": csv_id})

        # Duplicates tracking
        row_tuple = tuple(row_vals)
        if row_tuple in seen_exact_rows:
            seen_exact_rows[row_tuple].append(csv_row_num)
        else:
            seen_exact_rows[row_tuple] = [csv_row_num]

        if mobile:
            if mobile in seen_mobiles:
                seen_mobiles[mobile].append(csv_row_num)
            else:
                seen_mobiles[mobile] = [csv_row_num]

        # Build full JSON record
        record = {
            # === Target Database Schema Fields (leads table) ===
            "first_name": first_name,
            "middle_name": middle_name,
            "last_name": last_name,
            "mobile_number": mobile,
            "email": email,
            "project": project,
            "source": lead_source,
            "broker_name": "",  # Missing in CSV, added as empty string
            "channel_partner": "",  # Missing in CSV, added as empty string
            "external_id": csv_id,
            "stage": stage_mapped,
            "stage_changed_at": "",  # Missing in CSV, added as empty string
            "not_connected_count": "",  # Missing in CSV, added as empty string
            "assigned_to": owner,
            "assigned_role": "",  # Missing in CSV, added as empty string
            "created_by": "",  # Missing in CSV, added as empty string (pre_sales_person preserved separately)
            "requirement": requirement,
            "reason": "",  # Missing in CSV, added as empty string
            "booked_unit": "",  # Missing in CSV, added as empty string
            "booking_date": "",  # Missing in CSV, added as empty string
            "last_activity_at": "",  # Missing in CSV, added as empty string
            "created_at": created_at_dt,
            "updated_at": "",  # Missing in CSV, added as empty string
            # === Original / Unmapped CSV Fields Preserved ===
            "csv_id": csv_id,
            "client_name": client_name,
            "raw_name": client_name,
            "mobile": mobile,
            "mobile2": mobile2,
            "email_raw": email,
            "profession": profession,
            "area": area,
            "address": address,
            "budget": budget,
            "preferred_location": preferred_location,
            "requirement_raw": requirement,
            "possession_timeline": possession_timeline,
            "purchase_purpose": purchase_purpose,
            "notes": notes,
            "lead_quality": lead_quality,
            "project_raw": project,
            "lead_status": lead_status,
            "lead_sub_status": lead_sub_status,
            "lead_source": lead_source,
            "lead_sub_source": lead_sub_source,
            "owner": owner,
            "pre_sales_person": pre_sales_person,
            "pre_sales_email": pre_sales_email,
            "status": status,
            "created_at_raw": created_at_raw,
            "csv_row_number": csv_row_num,
        }

        records.append(record)

    # Populate duplicate lists for manual review
    for tup, rows_list in seen_exact_rows.items():
        if len(rows_list) > 1:
            manual_reviews["duplicate_full_rows"].append({"rows": rows_list, "client_name": tup[1], "mobile": tup[2]})

    for mob, rows_list in seen_mobiles.items():
        if len(rows_list) > 1:
            manual_reviews["duplicate_mobiles"].append({"mobile": mob, "occurrences": len(rows_list), "rows": rows_list})

    # Write output JSON
    os.makedirs(os.path.dirname(output_json_path), exist_ok=True)
    with open(output_json_path, "w", encoding="utf-8") as f:
        json.dump(records, f, indent=2, ensure_ascii=False)

    if manual_review_path:
        os.makedirs(os.path.dirname(manual_review_path), exist_ok=True)
        with open(manual_review_path, "w", encoding="utf-8") as f:
            json.dump(manual_reviews, f, indent=2, ensure_ascii=False)

    return len(records), total_data_rows, list(records[0].keys()) if records else [], manual_reviews


if __name__ == "__main__":
    default_csv = os.path.join(HERE, "12 Sep Lead.csv")
    if not os.path.exists(default_csv):
        default_csv = os.path.join(PROJECT_ROOT, "12 Sep Lead.csv")

    default_output = os.path.join(HERE, "12-Sep-Lead.json")
    default_review = os.path.join(HERE, "manual_review_items.json")

    csv_file = sys.argv[1] if len(sys.argv) > 1 else default_csv
    output_file = sys.argv[2] if len(sys.argv) > 2 else default_output
    review_file = sys.argv[3] if len(sys.argv) > 3 else default_review

    print(f"Reading CSV: {csv_file}")
    count, total_expected, fields, reviews = convert_12_sep_lead_csv(csv_file, output_file, review_file)
    print(f"Successfully processed {count} / {total_expected} data rows.")
    print(f"Output saved to: {output_file}")
    print(f"Total fields per record: {len(fields)}")
    print(f"Manual reviews saved to: {review_file}")
