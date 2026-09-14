#!/usr/bin/env python3
"""
Stand-alone MYCO data processing script.
Parses `MYCO.xlsx` and generates `MYCO.json` and `manual_review_items.json`.
Preserves 100% of rows, columns, and values with zero data loss.
Standard library only (uses zipfile and xml.etree.ElementTree).
"""

import datetime
import json
import os
import re
import sys
import xml.etree.ElementTree as ET
import zipfile

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


def excel_serial_to_datetime(serial_str):
    """
    Convert Excel serial float string to 'YYYY-MM-DD HH:MM:SS'.
    Returns empty string if blank, or original string if not convertible.
    """
    if not serial_str:
        return ""
    try:
        serial = float(serial_str)
        # Excel 1900 date system (accounting for Excel 1900 leap-year bug)
        base = datetime.datetime(1899, 12, 30)
        dt = base + datetime.timedelta(days=serial)
        return dt.strftime("%Y-%m-%d %H:%M:%S")
    except Exception:
        return str(serial_str)


def convert_myco_sheet(excel_path, output_json_path, manual_review_path=None):
    if not os.path.exists(excel_path):
        raise FileNotFoundError(f"Source file not found: {excel_path}")

    ns = {"ns": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}

    with zipfile.ZipFile(excel_path, "r") as z:
        # 1. Read shared strings
        shared_strings = []
        if "xl/sharedStrings.xml" in z.namelist():
            sst = ET.fromstring(z.read("xl/sharedStrings.xml"))
            for si in sst.findall("ns:si", ns):
                text_parts = [t.text or "" for t in si.findall(".//ns:t", ns)]
                shared_strings.append("".join(text_parts))

        # 2. Parse sheet1.xml
        sheet = ET.fromstring(z.read("xl/worksheets/sheet1.xml"))
        rows = sheet.findall(".//ns:row", ns)

    if not rows:
        raise ValueError("No rows found in Excel sheet")

    col_letters = ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R", "S", "T"]

    # Extract Header mapping from Row 1
    r1 = rows[0]
    header_map = {}
    for c in r1.findall("ns:c", ns):
        r_ref = c.attrib.get("r")
        col = "".join([ch for ch in r_ref if ch.isalpha()])
        t = c.attrib.get("t")
        v = c.find("ns:v", ns)
        val = v.text if v is not None else ""
        if t == "s":
            val = shared_strings[int(val)]
        header_map[col] = val

    total_sheet_rows = len(rows)
    total_data_rows = total_sheet_rows - 1

    records = []
    manual_reviews = {
        "missing_names": [],
        "numeric_or_unusual_names": [],
        "multi_person_names": [],
        "missing_mobiles": [],
        "mobiles_without_country_code": [],
        "missing_sources": [],
        "missing_projects": [],
        "combined_multi_projects": [],
        "duplicate_full_rows": [],
        "duplicate_mobiles": [],
        "duplicate_mobile_project_pairs": [],
    }

    # Tracking duplicates
    seen_exact_rows = {}
    seen_mobiles = {}
    seen_mob_proj = {}

    for r in rows[1:]:
        r_num = int(r.attrib.get("r"))
        row_dict = {}
        for c in r.findall("ns:c", ns):
            r_ref = c.attrib.get("r")
            col = "".join([ch for ch in r_ref if ch.isalpha()])
            t = c.attrib.get("t")
            v = c.find("ns:v", ns)
            val = v.text if v is not None else ""
            if t == "s":
                val = shared_strings[int(val)]
            elif t == "b":
                val = "TRUE" if val == "1" else "FALSE"
            row_dict[col] = val

        # Ensure all columns exist
        for col in col_letters:
            if col not in row_dict:
                row_dict[col] = ""

        # Raw values from 20 Excel columns
        salutation_raw = row_dict["A"]
        name_raw = row_dict["B"]
        professional_raw = row_dict["C"]
        email_raw = row_dict["D"]
        mobile_raw = row_dict["E"]
        lead_status_raw = row_dict["F"]
        lead_source_raw = row_dict["G"]
        sub_lead_source_raw = row_dict["H"]
        lead_owner_raw = row_dict["I"]
        created_at_raw = row_dict["J"]
        created_by_raw = row_dict["K"]
        updated_at_raw = row_dict["L"]
        updated_by_raw = row_dict["M"]
        company_city_raw = row_dict["N"]
        company_state_raw = row_dict["O"]
        company_country_raw = row_dict["P"]
        company_industry_raw = row_dict["Q"]
        gst_number_raw = row_dict["R"]
        pan_card_number_raw = row_dict["S"]
        description_raw = row_dict["T"]

        # Date conversions
        created_at_dt = excel_serial_to_datetime(created_at_raw)
        updated_at_dt = excel_serial_to_datetime(updated_at_raw)

        # Name decomposition
        first_name, middle_name, last_name = split_name(name_raw)

        # Anomaly / manual review tracking
        if not name_raw:
            manual_reviews["missing_names"].append(r_num)
        elif name_raw.isdigit() or re.match(r"^[0-9\u0660-\u0669\u0966-\u096F\U0001D7D8-\U0001D7FF]+$", name_raw):
            manual_reviews["numeric_or_unusual_names"].append({"row": r_num, "name": name_raw})
        elif "/" in name_raw or " & " in name_raw:
            manual_reviews["multi_person_names"].append({"row": r_num, "name": name_raw})

        if not mobile_raw:
            manual_reviews["missing_mobiles"].append(r_num)
        elif not mobile_raw.startswith("+91"):
            manual_reviews["mobiles_without_country_code"].append({"row": r_num, "mobile": mobile_raw})

        if not lead_source_raw:
            manual_reviews["missing_sources"].append(r_num)

        if not company_industry_raw:
            manual_reviews["missing_projects"].append(r_num)
        elif "/" in company_industry_raw:
            manual_reviews["combined_multi_projects"].append({"row": r_num, "project": company_industry_raw})

        # Duplicates tracking
        row_tuple = tuple(row_dict[col] for col in col_letters)
        if row_tuple in seen_exact_rows:
            seen_exact_rows[row_tuple].append(r_num)
        else:
            seen_exact_rows[row_tuple] = [r_num]

        if mobile_raw:
            if mobile_raw in seen_mobiles:
                seen_mobiles[mobile_raw].append(r_num)
            else:
                seen_mobiles[mobile_raw] = [r_num]

            mob_proj_pair = (mobile_raw, company_industry_raw)
            if mob_proj_pair in seen_mob_proj:
                seen_mob_proj[mob_proj_pair].append(r_num)
            else:
                seen_mob_proj[mob_proj_pair] = [r_num]

        # Build full JSON record
        record = {
            # === Target Database Schema Fields (leads table) ===
            "first_name": first_name,
            "middle_name": middle_name,
            "last_name": last_name,
            "mobile_number": mobile_raw,
            "email": email_raw,
            "project": company_industry_raw,
            "source": lead_source_raw,
            "broker_name": "",  # Missing in Excel, added as empty string
            "channel_partner": "",  # Missing in Excel, added as empty string
            "external_id": "",  # Missing in Excel, added as empty string
            "stage": lead_status_raw,
            "stage_changed_at": "",  # Missing in Excel, added as empty string
            "not_connected_count": "",  # Missing in Excel, added as empty string
            "assigned_to": lead_owner_raw,
            "assigned_role": "",  # Missing in Excel, added as empty string
            "created_by": created_by_raw,
            "requirement": "",  # Missing in Excel, added as empty string
            "reason": "",  # Missing in Excel, added as empty string
            "booked_unit": "",  # Missing in Excel, added as empty string
            "booking_date": "",  # Missing in Excel, added as empty string
            "last_activity_at": "",  # Missing in Excel, added as empty string
            "created_at": created_at_dt,
            "updated_at": updated_at_dt,
            # === Original / Unmapped Excel Fields Preserved ===
            "salutation": salutation_raw,
            "raw_name": name_raw,
            "lead_name": name_raw,
            "professional": professional_raw,
            "mobile_raw": mobile_raw,
            "email_raw": email_raw,
            "lead_status": lead_status_raw,
            "lead_source": lead_source_raw,
            "sub_lead_source": sub_lead_source_raw,
            "lead_owner": lead_owner_raw,
            "created_at_raw": created_at_raw,
            "created_by_raw": created_by_raw,
            "updated_at_raw": updated_at_raw,
            "updated_by": updated_by_raw,
            "company_city": company_city_raw,
            "company_state": company_state_raw,
            "company_country": company_country_raw,
            "company_industry": company_industry_raw,
            "gst_number": gst_number_raw,
            "pan_card_number": pan_card_number_raw,
            "description": description_raw,
            "excel_row_number": r_num,
        }

        records.append(record)

    # Populate duplicate lists for manual review
    for tup, rows_list in seen_exact_rows.items():
        if len(rows_list) > 1:
            manual_reviews["duplicate_full_rows"].append({"rows": rows_list, "lead_name": tup[1], "mobile": tup[4]})

    for mob, rows_list in seen_mobiles.items():
        if len(rows_list) > 1:
            manual_reviews["duplicate_mobiles"].append({"mobile": mob, "occurrences": len(rows_list), "rows": rows_list})

    for (mob, proj), rows_list in seen_mob_proj.items():
        if len(rows_list) > 1:
            manual_reviews["duplicate_mobile_project_pairs"].append({
                "mobile": mob,
                "project": proj,
                "occurrences": len(rows_list),
                "rows": rows_list,
            })

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
    default_excel = os.path.join(HERE, "MYCO.xlsx")
    if not os.path.exists(default_excel):
        default_excel = os.path.join(PROJECT_ROOT, "MYCO.xlsx")

    default_output = os.path.join(HERE, "MYCO.json")
    default_review = os.path.join(HERE, "manual_review_items.json")

    excel_file = sys.argv[1] if len(sys.argv) > 1 else default_excel
    output_file = sys.argv[2] if len(sys.argv) > 2 else default_output
    review_file = sys.argv[3] if len(sys.argv) > 3 else default_review

    print(f"Reading Excel: {excel_file}")
    count, total_expected, fields, reviews = convert_myco_sheet(excel_file, output_file, review_file)
    print(f"Successfully processed {count} / {total_expected} data rows.")
    print(f"Output saved to: {output_file}")
    print(f"Total fields per record: {len(fields)}")
    print(f"Manual reviews saved to: {review_file}")
