## 2024-05-18 - Missing Authentication on Root Utility Scripts
**Vulnerability:** Root-level scripts like `importar-csv.php` and `diagnostico_isbn.php` were accessible without authentication, allowing attackers to upload CSVs or run diagnostics unauthenticated.
**Learning:** These scripts initialized SLiMS components (`sysconfig.inc.php`) but didn't include the session validation scripts (`admin/default/session.inc.php` and `admin/default/session_check.inc.php`).
**Prevention:** Always verify that any script intended for administrative or utility purposes explicitly requires `session_check.inc.php` or implements a similar authentication check. Do not assume utilities are protected just by being undocumented.
