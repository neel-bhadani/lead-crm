# Master Data Processing & Conversion

This directory contains the stand-alone workflow for parsing `master-sheet.xls`, preserving all original records, mapping attributes to the CRM database structure, and exporting the data into a clean, uniform JSON file (`master-data.json`) for future database seeders.

---

## Directory Structure

```text
master-data/
├── master-sheet.xls            # Exact copy of the source spreadsheet
├── master-data.json            # Final exported JSON containing all 8,862 records
├── manual_review_items.json    # Catalog of anomalies requiring manual review
├── process_master_data.py      # Stand-alone conversion script
├── vendor/                     # Pure-Python vendored dependencies (xlrd)
└── README.md                   # Full documentation & summary report
```

---

## Workflow Execution

To re-run or regenerate the dataset at any time:

```bash
python3 master-data/process_master_data.py
```

Optional arguments:
```bash
python3 master-data/process_master_data.py [path/to/excel_file] [path/to/output_json] [path/to/manual_review_json]
```

---

## Summary & Verification Statistics

### 1. Sheet & Record Processing
* **Excel Sheets Processed:** `1` (`Sheet1` has 8,863 rows; `Sheet2` and `Sheet3` are empty 0-row sheets).
* **Source Header Rows:** `1`
* **Source Data Rows Processed:** `8,862` (100% of rows extracted; 0 skipped or dropped).
* **Total JSON Records Generated:** `8,862`

### 2. Duplicate Preservation
* **Exact Full-Row Duplicates:** `4` distinct pairs (`8` total rows) — all preserved.
* **Duplicate Mobile Numbers:** `768` unique phone numbers appear across `1,824` rows — all preserved.
* **Duplicate (Mobile + Project) Pairs:** `468` pairs across `1,045` rows — all preserved.

---

## Field Breakdown & Database Schema Mapping

Each record in `master-data.json` contains a total of **55 fields**:

### A. Fields Mapped to Existing Database Structure (`leads` table) (11 fields)
1. `first_name` — Parsed from `Name`
2. `middle_name` — Parsed from `Name` (when available)
3. `last_name` — Parsed from `Name` (when available)
4. `mobile_number` — Contact number from `Mobile No`
5. `project` — Project name from `Project`
6. `source` — Lead acquisition channel from `Source`
7. `broker_name` — Referring broker name from `Broker Name`
8. `stage` — Current status from `Secondary Stages`
9. `assigned_to` — Salesperson name from `Lead Assign User`
10. `requirement` — Unit type / BHK from `Configuration`
11. `created_at` — Timestamp from `Create Date` / `Months`

### B. Fields That Do Not Currently Exist in Database Structure (33 fields)
Preserved with 100% fidelity from the original spreadsheet:
* `raw_name`, `months`, `create_date`, `mobile_no`, `secondary_stages`, `configuration`, `broker_number`, `lead_assign_user`, `address`, `professional`, `lead_quality`, `first_visit`, `first_visit_month`, `first_visit_note`, `second_visit`, `second_visit_month`, `second_visit_note`, `third_visit`, `third_visit_month`, `third_visit_note`, `next_follow_up`, `follow_up_1`, `follow_up_1_remark`, `follow_up_2`, `follow_up_2_remark`, `follow_up_3`, `follow_up_3_remark`, `follow_up_4`, `follow_up_4_remark`, `follow_up_5`, `follow_up_5_remark`, `send_message`, `excel_row_number`.

### C. Missing Database Fields Added as Empty Values (`""`) (11 fields)
Added explicitly with `""` for future database seeder compatibility:
* `email`, `channel_partner`, `external_id`, `stage_changed_at`, `not_connected_count`, `assigned_role`, `created_by`, `reason`, `booked_unit`, `booking_date`, `last_activity_at`.

---

## Data Items Requiring Manual Review

All specific items are cataloged in [`master-data/manual_review_items.json`](file:///mnt/c/Users/bhada/projects/lead-crm/master-data/manual_review_items.json):

1. **Missing Names (12 rows):** Rows `11`, `13`, `48`, `70`, `294`, `331`, `594`, `768`, `3991`, `5353`, `7889`, `8649` have empty name cells.
2. **Numeric Names (1 row):** Row `8037` has name `"8141036369"`.
3. **Missing Mobile Numbers (56 rows):** Empty contact numbers (e.g., rows `137`, `267`, `324`, `401`, `547`).
4. **Multiple Mobile Numbers (87 rows):** Cells with multiple phone numbers or trailing slashes (e.g., `"9979709048/7573050129"` at row `101`, `"9173516199/"` at row `128`).
5. **Missing Sources (4 rows):** Rows `34`, `4301`, `4302`, `4303` have no lead source specified.
6. **Missing Assigned Users (55 rows):** Rows without an assigned salesperson (e.g., rows `1825`-`1827`).
7. **Text in Date Columns (2 rows):**
   * Row `2024`, Col `Follow Up 4`: `"Call not connect"`
   * Row `7707`, Col `Months`: `"visit done with wife early morning"`
8. **Excel Formula Errors (6 cells):** Formula error `#REF!` (code 23) in visit month columns at rows `83` and `140`.
