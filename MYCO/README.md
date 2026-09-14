# MYCO Client Data Processing & Conversion

This directory contains the stand-alone workflow for parsing `MYCO.xlsx`, preserving all original records, mapping attributes to the CRM database structure, and exporting the data into a clean, uniform JSON file (`MYCO.json`) for future database seeders.

---

## Directory Structure

```text
MYCO/
├── MYCO.xlsx                   # Exact copy of the source spreadsheet
├── MYCO.json                   # Final exported JSON containing all 4,337 records
├── manual_review_items.json    # Catalog of anomalies and duplicates requiring manual review
├── process_myco.py             # Stand-alone conversion script (Python standard library only)
└── README.md                   # Full documentation & summary report
```

---

## Workflow Execution

To re-run or regenerate the dataset at any time:

```bash
python3 MYCO/process_myco.py
```

Optional arguments:
```bash
python3 MYCO/process_myco.py [path/to/excel_file] [path/to/output_json] [path/to/manual_review_json]
```

---

## Summary & Verification Statistics

### 1. Sheet & Record Processing
* **Excel Sheets in Workbook:** `1` (`Main sheet`).
* **Source Header Rows:** `1` (Row 1 with columns `A` through `T`).
* **Source Data Rows Processed:** `4,337` (100% of data rows extracted; 0 skipped or dropped).
* **Total JSON Records Generated:** `4,337`
* **Cell Reconciliation:** 86,740 / 86,740 raw cells (100%) verified with 0 mismatches.

### 2. Duplicate Preservation
* **Exact Full-Row Duplicates:** `9` distinct pairs (`18` total rows) — all preserved.
* **Duplicate Mobile Numbers:** `330` unique phone numbers appear across `761` rows — all preserved.
* **Duplicate (Mobile + Project) Pairs:** `178` pairs across `385` rows — all preserved.

---

## Field Breakdown & Database Schema Mapping

Each record in `MYCO.json` contains a total of **45 fields**:

### A. Fields Successfully Mapped to Existing Database Structure (`leads` table) (12 fields)
1. `first_name` — Parsed from `Lead Name`
2. `middle_name` — Parsed from `Lead Name` (when multi-word name available)
3. `last_name` — Parsed from `Lead Name` (when available)
4. `mobile_number` — Contact number from `Mobile` (e.g., `+919879751280`)
5. `email` — Contact email from `Email` (2,010 records populated)
6. `project` — Project name mapped from `Company Industry` (e.g., `Felicity`, `Vanam`, `SkyDeck`)
7. `source` — Lead acquisition channel from `Lead Source` (e.g., `Social Media`, `Direct`, `Referral`, `Channel Parner`)
8. `stage` — Lead status / pipeline stage from `Lead Status` (e.g., `Lost-Not Interested`, `Lost-Budget Issue`)
9. `assigned_to` — Salesperson / lead owner from `Lead Owner` (e.g., `Riya Gandhi`, `Dhanashri Meshram`, `Mayur Patel`, `Harnish Gajjar`)
10. `created_by` — User who created the lead from `Created By` (e.g., `Mayur Patel`, `Riya Gandhi`)
11. `created_at` — Standard ISO datetime (`YYYY-MM-DD HH:MM:SS`) converted from `Created At` serial
12. `updated_at` — Standard ISO datetime (`YYYY-MM-DD HH:MM:SS`) converted from `Updated At` serial

### B. Fields That Do Not Currently Exist in Database Structure (22 fields)
Preserved with 100% fidelity from the original spreadsheet:
* `salutation` — Col A (`Mr.`, `Dr.`, or empty)
* `raw_name` — Col B exact text
* `lead_name` — Col B exact text
* `professional` — Col C (e.g., `CHPL`, `Chemical Business`, `Mine & Petrol Pump`)
* `mobile_raw` — Col E exact text
* `email_raw` — Col D exact text
* `lead_status` — Col F exact text
* `lead_source` — Col G exact text
* `sub_lead_source` — Col H (e.g., `Facebook`, `Direct Walk Inn`, `WhatsApp`, broker details)
* `lead_owner` — Col I exact text
* `created_at_raw` — Col J exact serial string
* `created_by_raw` — Col K exact text
* `updated_at_raw` — Col L exact serial string
* `updated_by` — Col M (e.g., `Riya Gandhi`, `Mayur Patel`, `Dhanashri Meshram`, `Harnish Gajjar`)
* `company_city` — Col N (e.g., `Ahmedabad`, `Surat`, `Sarkhej`)
* `company_state` — Col O (`Gujarat`)
* `company_country` — Col P (`India`)
* `company_industry` — Col Q (original project/industry value)
* `gst_number` — Col R
* `pan_card_number` — Col S
* `description` — Col T (visit notes, interest details, customer remarks)
* `excel_row_number` — 1-indexed row number in the source Excel file (Rows 2 to 4338)

### C. Missing Database Fields Added as Empty Values (`""`) (11 fields)
Added explicitly with `""` for future database seeder compatibility:
* `broker_name`, `channel_partner`, `external_id`, `stage_changed_at`, `not_connected_count`, `assigned_role`, `requirement`, `reason`, `booked_unit`, `booking_date`, `last_activity_at`.

---

## Data Items Requiring Manual Review

All specific items are cataloged in [`MYCO/manual_review_items.json`](file:///mnt/c/Users/bhada/projects/lead-crm/MYCO/manual_review_items.json):

1. **Numeric or Unusual Lead Names (2 rows):**
   * Row `1445`: Name is `"14"`
   * Row `2648`: Name is Unicode bold `"𝟐𝟎𝟎𝟖"`
2. **Multi-Person / Joint Names (19 rows):**
   * Examples: Row `118` (`"Ashvin/Meet Kachadia"`), Row `433` (`"Maya Shah/Kiran shah"`), Row `786` (`"Tejas & Haresh Vashi"`), Row `845` (`"Dilip Patel & Jitubhai Kheni"`).
3. **Missing Mobile Numbers (33 rows):**
   * Rows `434`, `509`, `766`, `781`, `787`, `836`, `847`, `850`, `870`, `938`, etc.
4. **Mobiles Without Country Code `+91` (21 rows):**
   * Numbers starting with `+7...`, `+49...`, `+96...`, `+95...`, `+84...`, `+1...` (e.g., Row `1992`: `+4917621429292`, Row `2087`: `+16205114828`).
5. **Missing Lead Sources (2 rows):**
   * Rows `847` and `3491` have empty source values.
6. **Missing Projects (159 rows):**
   * Rows with empty `Company Industry` (e.g., Rows `9`, `1387`, `1395`, `1412`, etc.).
7. **Combined Multi-Project Values (58 rows):**
   * Rows where `Company Industry` contains multiple projects: `"Felicity/SkyDeck"` (48 rows), `"वनम् / Felicity / SkyDeck"` (6 rows), `"Vanam/ Felicity"` (4 rows).
8. **Sub Lead Source with Broker Names & Phone Numbers:**
   * In channel partner / broker leads, `Sub Lead Source` contains the broker's name and phone number (e.g., `"Ravi Patel - 9824199232"`, `"Sharad Patil\t9898454342"`).
