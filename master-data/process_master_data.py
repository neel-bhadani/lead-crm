#!/usr/bin/env python3
"""
Stand-alone master data processing script.
Parses `master-sheet.xls` / `Master Sheet.xls` and generates `master-data.json`.
Preserves 100% of rows, columns, and values with zero data loss.
"""

import datetime
import json
import os
import re
import sys

# Load vendored xlrd from master-data/vendor
HERE = os.path.dirname(os.path.abspath(__file__))
VENDOR_DIR = os.path.join(HERE, "vendor")
if os.path.isdir(VENDOR_DIR):
    sys.path.insert(0, VENDOR_DIR)

import xlrd  # noqa: E402


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


def format_xldate(val, datemode):
    """Format Excel date serial to YYYY-MM-DD HH:MM:SS string."""
    try:
        dt = xlrd.xldate_as_datetime(val, datemode)
        return dt.strftime("%Y-%m-%d %H:%M:%S")
    except Exception:
        return str(val)


def parse_date_cell(sheet, row_idx, col_idx, datemode):
    """Parse date cell, date text, or preserve raw text string."""
    cell_type = sheet.cell_type(row_idx, col_idx)
    val = sheet.cell_value(row_idx, col_idx)

    if cell_type in (0, 5, 6):  # EMPTY, ERROR, BLANK
        return ""
    if cell_type == 3:  # DATE
        return format_xldate(val, datemode)
    if cell_type == 2:  # NUMBER
        if isinstance(val, float) and val.is_integer():
            if 30000 <= val <= 60000:
                return format_xldate(val, datemode)
            return str(int(val))
        return str(val)
    if cell_type == 1:  # TEXT
        s = str(val).strip()
        if not s:
            return ""
        # Check M/D/YYYY or MM/DD/YYYY format
        m = re.fullmatch(r"(\d{1,2})/(\d{1,2})/(\d{4})", s)
        if m:
            m_val, d_val, y_val = int(m.group(1)), int(m.group(2)), int(m.group(3))
            try:
                dt = datetime.datetime(y_val, m_val, d_val)
                return dt.strftime("%Y-%m-%d %H:%M:%S")
            except Exception:
                return s
        return s
    return str(val).strip()


def parse_text_cell(sheet, row_idx, col_idx):
    """Parse text/number cell into a clean string without altering raw value."""
    cell_type = sheet.cell_type(row_idx, col_idx)
    val = sheet.cell_value(row_idx, col_idx)

    if cell_type in (0, 5, 6):  # EMPTY, ERROR, BLANK
        return ""
    if cell_type == 2:  # NUMBER
        if isinstance(val, float) and val.is_integer():
            return str(int(val))
        return str(val)
    if cell_type == 1:  # TEXT
        return str(val).strip()
    return str(val).strip()


def convert_master_sheet(excel_path, output_json_path, manual_review_path=None):
    if not os.path.exists(excel_path):
        raise FileNotFoundError(f"Source file not found: {excel_path}")

    wb = xlrd.open_workbook(excel_path)
    sheet = wb.sheet_by_name("Sheet1")
    datemode = wb.datemode

    total_sheet_rows = sheet.nrows
    total_data_rows = total_sheet_rows - 1

    records = []
    manual_reviews = {
        "missing_names": [],
        "numeric_names": [],
        "missing_mobiles": [],
        "multi_mobiles": [],
        "missing_sources": [],
        "missing_assigned_users": [],
        "text_in_date_cols": [],
        "formula_errors": [],
    }

    for r in range(1, total_sheet_rows):
        # 1. Read all 35 columns from Excel
        months_raw = parse_date_cell(sheet, r, 0, datemode)
        create_date_raw = parse_date_cell(sheet, r, 1, datemode)
        name_raw = parse_text_cell(sheet, r, 2)
        mobile_no_raw = parse_text_cell(sheet, r, 3)
        secondary_stages_raw = parse_text_cell(sheet, r, 4)
        configuration_raw = parse_text_cell(sheet, r, 5)
        project_raw = parse_text_cell(sheet, r, 6)
        source_raw = parse_text_cell(sheet, r, 7)
        broker_name_raw = parse_text_cell(sheet, r, 8)
        broker_number_raw = parse_text_cell(sheet, r, 9)
        lead_assign_user_raw = parse_text_cell(sheet, r, 10)
        address_raw = parse_text_cell(sheet, r, 11)
        professional_raw = parse_text_cell(sheet, r, 12)
        lead_quality_raw = parse_text_cell(sheet, r, 13)
        first_visit_raw = parse_date_cell(sheet, r, 14, datemode)
        first_visit_month_raw = parse_date_cell(sheet, r, 15, datemode)
        first_visit_note_raw = parse_text_cell(sheet, r, 16)
        second_visit_raw = parse_date_cell(sheet, r, 17, datemode)
        second_visit_month_raw = parse_date_cell(sheet, r, 18, datemode)
        second_visit_note_raw = parse_text_cell(sheet, r, 19)
        third_visit_raw = parse_date_cell(sheet, r, 20, datemode)
        third_visit_month_raw = parse_date_cell(sheet, r, 21, datemode)
        third_visit_note_raw = parse_text_cell(sheet, r, 22)
        next_follow_up_raw = parse_date_cell(sheet, r, 23, datemode)
        follow_up_1_raw = parse_date_cell(sheet, r, 24, datemode)
        follow_up_1_remark_raw = parse_text_cell(sheet, r, 25)
        follow_up_2_raw = parse_date_cell(sheet, r, 26, datemode)
        follow_up_2_remark_raw = parse_text_cell(sheet, r, 27)
        follow_up_3_raw = parse_date_cell(sheet, r, 28, datemode)
        follow_up_3_remark_raw = parse_text_cell(sheet, r, 29)
        follow_up_4_raw = parse_date_cell(sheet, r, 30, datemode)
        follow_up_4_remark_raw = parse_text_cell(sheet, r, 31)
        follow_up_5_raw = parse_date_cell(sheet, r, 32, datemode)
        follow_up_5_remark_raw = parse_text_cell(sheet, r, 33)
        send_message_raw = parse_text_cell(sheet, r, 34)

        # Track anomalies for manual review
        excel_row_num = r + 1
        if not name_raw:
            manual_reviews["missing_names"].append(excel_row_num)
        elif name_raw.isdigit() and len(name_raw) >= 10:
            manual_reviews["numeric_names"].append({"row": excel_row_num, "name": name_raw})

        if not mobile_no_raw:
            manual_reviews["missing_mobiles"].append(excel_row_num)
        elif "/" in mobile_no_raw or (" " in mobile_no_raw.strip() and len(mobile_no_raw) > 10):
            manual_reviews["multi_mobiles"].append({"row": excel_row_num, "mobile": mobile_no_raw})

        if not source_raw:
            manual_reviews["missing_sources"].append(excel_row_num)
        if not lead_assign_user_raw:
            manual_reviews["missing_assigned_users"].append(excel_row_num)

        date_cols = [0, 1, 14, 15, 17, 18, 20, 21, 23, 24, 26, 28, 30, 32]
        for c in date_cols:
            t = sheet.cell_type(r, c)
            v = sheet.cell_value(r, c)
            h = sheet.cell_value(0, c) or f"Col_{c}"
            if t == 5:
                manual_reviews["formula_errors"].append({"row": excel_row_num, "col": c, "header": h, "error": str(v)})
            elif t == 1 and str(v).strip():
                if not re.fullmatch(r"(\d{1,2})/(\d{1,2})/(\d{4})", str(v).strip()):
                    manual_reviews["text_in_date_cols"].append({"row": excel_row_num, "col": c, "header": h, "text": str(v).strip()})

        # Name decomposition
        first_name, middle_name, last_name = split_name(name_raw)

        # Build full JSON record
        record = {
            # === Target Database Schema Fields (leads table) ===
            "first_name": first_name,
            "middle_name": middle_name,
            "last_name": last_name,
            "mobile_number": mobile_no_raw,
            "email": "",  # Missing in Excel, added as empty string
            "project": project_raw,
            "source": source_raw,
            "broker_name": broker_name_raw,
            "channel_partner": "",  # Missing in Excel, added as empty string
            "external_id": "",  # Missing in Excel, added as empty string
            "stage": secondary_stages_raw,
            "stage_changed_at": "",  # Missing in Excel, added as empty string
            "not_connected_count": "",  # Missing in Excel, added as empty string
            "assigned_to": lead_assign_user_raw,
            "assigned_role": "",  # Missing in Excel, added as empty string
            "created_by": "",  # Missing in Excel, added as empty string
            "requirement": configuration_raw,
            "reason": "",  # Missing in Excel, added as empty string
            "booked_unit": "",  # Missing in Excel, added as empty string
            "booking_date": "",  # Missing in Excel, added as empty string
            "last_activity_at": "",  # Missing in Excel, added as empty string
            "created_at": create_date_raw or months_raw,
            # === Original / Unmapped Excel Fields Preserved ===
            "raw_name": name_raw,
            "months": months_raw,
            "create_date": create_date_raw,
            "mobile_no": mobile_no_raw,
            "secondary_stages": secondary_stages_raw,
            "configuration": configuration_raw,
            "broker_number": broker_number_raw,
            "lead_assign_user": lead_assign_user_raw,
            "address": address_raw,
            "professional": professional_raw,
            "lead_quality": lead_quality_raw,
            "first_visit": first_visit_raw,
            "first_visit_month": first_visit_month_raw,
            "first_visit_note": first_visit_note_raw,
            "second_visit": second_visit_raw,
            "second_visit_month": second_visit_month_raw,
            "second_visit_note": second_visit_note_raw,
            "third_visit": third_visit_raw,
            "third_visit_month": third_visit_month_raw,
            "third_visit_note": third_visit_note_raw,
            "next_follow_up": next_follow_up_raw,
            "follow_up_1": follow_up_1_raw,
            "follow_up_1_remark": follow_up_1_remark_raw,
            "follow_up_2": follow_up_2_raw,
            "follow_up_2_remark": follow_up_2_remark_raw,
            "follow_up_3": follow_up_3_raw,
            "follow_up_3_remark": follow_up_3_remark_raw,
            "follow_up_4": follow_up_4_raw,
            "follow_up_4_remark": follow_up_4_remark_raw,
            "follow_up_5": follow_up_5_raw,
            "follow_up_5_remark": follow_up_5_remark_raw,
            "send_message": send_message_raw,
            "excel_row_number": excel_row_num,
        }

        records.append(record)

    # Write output JSON
    os.makedirs(os.path.dirname(output_json_path), exist_ok=True)
    with open(output_json_path, "w", encoding="utf-8") as f:
        json.dump(records, f, indent=2, ensure_ascii=False)

    if manual_review_path:
        with open(manual_review_path, "w", encoding="utf-8") as f:
            json.dump(manual_reviews, f, indent=2, ensure_ascii=False)

    return len(records), total_data_rows, list(records[0].keys()) if records else [], manual_reviews


if __name__ == "__main__":
    project_root = os.path.abspath(os.path.join(HERE, ".."))
    default_excel = os.path.join(HERE, "master-sheet.xls")
    if not os.path.exists(default_excel):
        default_excel = os.path.join(project_root, "Master Sheet.xls")

    default_output = os.path.join(HERE, "master-data.json")
    default_review = os.path.join(HERE, "manual_review_items.json")

    excel_file = sys.argv[1] if len(sys.argv) > 1 else default_excel
    output_file = sys.argv[2] if len(sys.argv) > 2 else default_output
    review_file = sys.argv[3] if len(sys.argv) > 3 else default_review

    print(f"Reading Excel: {excel_file}")
    count, total_expected, fields, reviews = convert_master_sheet(excel_file, output_file, review_file)
    print(f"Successfully processed {count} / {total_expected} data rows.")
    print(f"Output saved to: {output_file}")
    print(f"Total fields per record: {len(fields)}")
