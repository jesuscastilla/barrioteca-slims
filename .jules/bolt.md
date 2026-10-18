## 2026-10-18 - [Pagination & Cartesian Product JOIN issue]
**Learning:** Found an N+1 fetching all records at index page without pagination. Furthermore found a catastrophic Cartesian product bug on admin page with JOIN statement `ba.biblio_id=ba.biblio_id`.
**Action:** Always check SQL JOIN syntax for duplicate variables resulting in Cartesian products instead of targeted row lookups. Use pagination on lists.
