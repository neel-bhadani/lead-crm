# 12 Sep Lead Data Processing & Conversion

This directory contains the stand-alone workflow for parsing `12 Sep Lead.csv`, preserving all original records, mapping attributes to the CRM database structure, and exporting the data into a clean, uniform JSON file (`12-Sep-Lead.json`) for future database seeders.

---

## Directory Structure

```text
12-Sep-Lead/
├── 12 Sep Lead.csv             # Exact copy of the source CSV file
├── 12-Sep-Lead.json            # Final exported JSON containing all 2,940 records
├── manual_review_items.json    # Catalog of anomalies and duplicates requiring manual review
├── process_12_sep_lead.py      # Stand-alone conversion script (Python standard library only)
└── README.md                   # Full documentation & summary report
```

---

## Workflow Execution

To re-run or regenerate the dataset at any time:

```bash
python3 12-Sep-Lead/process_12_sep_lead.py
```

Optional arguments:
```bash
python3 12-Sep-Lead/process_12_sep_lead.py [path/to/csv_file] [path/to/output_json] [path/to/manual_review_json]
```

---

## Summary & Verification Statistics

### 1. Record & Row Processing
* **Total CSV Lines in File:** `3,296` lines (due to multiline text fields in `notes`).
* **Source Header Rows:** `1` (Row 1 containing `25` columns).
* **Source Data Rows Processed:** `2,940` (100% of rows extracted; 0 skipped or dropped).
* **Total JSON Records Generated:** `2,940`
* **Cell Reconciliation:** 73,500 / 73,500 raw cells (100%) verified with 0 mismatches.

### 2. Duplicate Preservation
* **Exact Full-Row Duplicates:** `0`
* **Duplicate CSV IDs:** `0` (all 2,940 IDs are unique from `2100` to `5602`).
* **Duplicate Mobile Numbers:** `8` unique phone numbers appear across `16` total rows (each occurs in 2 separate projects) — all preserved.
* **Duplicate (Mobile + Project) Pairs:** `0`

---

## Field Breakdown & Database Schema Mapping

Each record in `12-Sep-Lead.json` contains a total of **50 fields**:

### A. Fields Successfully Mapped to Existing Database Structure (`leads` table) (12 fields)
1. `first_name` — Parsed from `client_name`
2. `middle_name` — Parsed from `client_name` (when multi-word name available)
3. `last_name` — Parsed from `client_name` (when available)
4. `mobile_number` — Contact number from `mobile` (e.g., `9648931029`)
5. `email` — Contact email from `email` (298 populated)
6. `project` — Project name mapped from `project` (e.g., `Vanam`, `Skydeck`, `Felicity`)
7. `source` — Lead acquisition channel from `lead_source` (e.g., `Social Media`, `Direct`, `Channel Partner`, `Refferral`)
8. `external_id` — External CRM ID mapped from `id`
9. `stage` — Primary stage mapped from `lead_sub_status` (or fallback to `lead_status` if sub_status is empty)
10. `assigned_to` — Lead owner / salesperson from `owner` (e.g., `Mayur Patel`, `Ravi Patel`, `Dhanashri Meshram`, `Riya Gandhi`)
11. `requirement` — Requirement type from `requirement` (`""` in source CSV)
12. `created_at` — Standard ISO datetime (`YYYY-MM-DD HH:MM:SS`) converted from `created_at` (`DD-MM-YYYY HH:MM`)

### B. Fields That Do Not Currently Exist in Database Structure (27 fields)
Preserved with 100% fidelity from the original CSV file:
* `csv_id` — Col 1 (`id`)
* `client_name` — Col 2 exact text
* `raw_name` — Col 2 exact text
* `mobile` — Col 3 exact text
* `mobile2` — Col 4 secondary contact number (5 populated)
* `email_raw` — Col 5 exact text
* `profession` — Col 6 (e.g., `Construction`, `Textile`, `Business`)
* `area` — Col 7 (e.g., `Dindoli`, `Jahangirpura`, `Adajan`)
* `address` — Col 8 (e.g., `Palanpur`, `Sangrampura`)
* `budget` — Col 9 (e.g., `55`)
* `preferred_location` — Col 10 (189 populated)
* `requirement_raw` — Col 11
* `possession_timeline` — Col 12 (e.g., `1-2 years`, `3+ years`, `Immediate`)
* `purchase_purpose` — Col 13 (e.g., `Self Use`, `Investment`)
* `notes` — Col 14 (survey questionnaires, Facebook lead form details, remarks)
* `lead_quality` — Col 15 (e.g., `cold`, `hot`, `warm`)
* `project_raw` — Col 16
* `lead_status` — Col 17 (e.g., `Lost`, `Qualified`, `Open`, `Won`)
* `lead_sub_status` — Col 18 (e.g., `Fresh`, `SV Done`, `Ringing`, `Not Interested`)
* `lead_source` — Col 19
* `lead_sub_source` — Col 20 (e.g., `Facebook`, `Direct Walk-In`, `Squrefit Page`, broker names)
* `owner` — Col 21
* `pre_sales_person` — Col 22 (`Riya Gandhi`)
* `pre_sales_email` — Col 23 (`riyagandhi@shaligram.com`)
* `status` — Col 24 (`active`)
* `created_at_raw` — Col 25 (`DD-MM-YYYY HH:MM` original string)
* `csv_row_number` — 1-indexed row number in the CSV file (Rows 2 to 2941)

### C. Missing Database Fields Added as Empty Values (`""`) (11 fields)
Added explicitly with `""` for future database seeder compatibility:
* `broker_name`, `channel_partner`, `stage_changed_at`, `not_connected_count`, `assigned_role`, `created_by`, `reason`, `booked_unit`, `booking_date`, `last_activity_at`, `updated_at`.

---

## Data Items Requiring Manual Review

All specific items are cataloged in [`12-Sep-Lead/manual_review_items.json`](file:///mnt/c/Users/bhada/projects/lead-crm/12-Sep-Lead/manual_review_items.json):

1. **Numeric or Symbolic Client Names (2 rows):**
   * Row `2331` (ID `2720`): Name is `"9924230639"` (mobile number entered as name)
   * Row `2219` (ID `3380`): Name is Apple logo Unicode symbol `"\uf8ff"`
2. **Short Client Names (14 rows):**
   * Names with 1 or 2 characters: Row `331` (`"Om"`), Row `790` (`"ad"`), Row `873` (`"Y"`), Row `988` (`"ST"`), Row `1134` (`"R"`), Row `1375` (`"M"`), Row `1549` (`"IY"`), Row `1650` (`"ds"`), Row `2030` (`"MK"`), Row `2063` (`"pj"`), Row `2433` (`"M"`), Row `2510` (`"Th"`), Row `2644` (`"P"`), Row `2691` (`"Mk"`).
3. **Multi-Person / Joint Names (11 rows):**
   * Examples: Row `128` (`"Tejas & Haresh Vashi"`), Row `1162` (`"Vimal/Mitesh Gandhi"`), Row `2039` (`"Dilip Patel & Jitubhai Kheni"`).
4. **Non-10-Digit Mobile Numbers (1 row):**
   * Row `2729` (ID `2314`): Mobile `"19054070973"` (11-digit international number).
5. **Populated Secondary Mobile `mobile2` (5 rows):**
   * Rows `2254` (`8320056041`), `2262` (`9825190849`), `2313` (`7016934976`), `2349` (`9724332707`), `2698` (`9825574304`).
6. **Missing Projects (7 rows):**
   * Rows `2066`, `2191`, `2307`, `2379`, `2380`, `2381`, `2382`.
7. **Missing Lead Sources (2 rows):**
   * Rows `2066`, `2382`.
8. **Missing Owners (2 rows):**
   * Rows `39`, `2066`.
9. **Missing Statuses (1 row):**
   * Row `2066` (both `lead_status` and `lead_sub_status` are empty).
10. **Duplicate Mobile Numbers Across Projects (8 numbers, 16 rows):**
    * Mobiles `9712978787`, `9726092876`, `9723231280`, `8487848604`, `9879020430`, `9825127344`, `9974095280`, `9727723930` appear in 2 separate projects each.
11. **Channel Partner Leads with Broker Names in `lead_sub_source`:**
    * In Channel Partner leads, `lead_sub_source` contains broker names (e.g., `Shailesh Thakkar`, `Ravi Patel`, `Samir Sheth`, `Vinod Moga`, `Denish Sakhiya`).
