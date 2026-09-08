QA harness for QA-REPORT-2.md (8 Sep 2026).

    backup-qa2.sql   mysqldump taken before the first write; the database was
                     restored from this at the end and diffs to 0 lines.
    phpunit.qa.xml   PHPUnit config pointing at the LIVE lead_crm database.
                     No RefreshDatabase — each suite cleans up after itself.
    suite/           the probes, in the order they were run (A01 … N01).

Run one suite:

    /mnt/c/php/php.exe vendor/bin/phpunit -c .qa-tmp/phpunit.qa.xml --filter B01

WARNING: these write to the real development database. Take a fresh dump first.
The alert dedupe probe (K02) was removed after the run because it called
Alert::truncate(), which MySQL treats as DDL — it implicitly commits and cannot
be rolled back. Do not reintroduce a truncate in this harness.
