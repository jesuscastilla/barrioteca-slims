1.  **Analyze the Performance Bottleneck:**
    *   In `api/v1/controllers/LoanController.php`, the method `getSummaryDate($date)` contains an N+1 query problem.
    *   It first fetches a list of dates (around 8 dates) using `$this->getDate($date, false)`.
    *   Then, it iterates over these dates using a `foreach` loop.
    *   Inside the loop, it executes 3 separate queries (`SELECT COUNT(...)`) for each date to get counts for 'loan', 'return', and 'extend'.
    *   This results in 1 (initial) + 8 * 3 (in loop) = 25 database queries for a single API endpoint call.

2.  **Proposed Optimization:**
    *   We can rewrite `getSummaryDate($date)` to fetch all required counts in a single query by using conditional aggregation (`SUM(CASE WHEN ... THEN 1 ELSE 0 END)`).
    *   We group by the `loan_date` or `return_date` and filter by the date range we retrieved.
    *   Alternatively, to exactly preserve existing logic that loops over exactly the retrieved dates and sets 0 if there are no records, we can keep the loop to format the response but execute *one* grouped query beforehand.
    *   Actually, looking closer, the queries inside the loop group by `loan_date` and `return_date` but filter exactly on `loan_date = '".$value."'`. The return count groups by `return_date` but filters by `loan_date = '".$value."'`.
    *   Wait, the `getSummary()` method in `api/v1/controllers/LoanController.php` executes 5 separate queries for total, new, return, extend, overdue:
        *   `SELECT COUNT(loan_id) FROM loan`
        *   `SELECT COUNT(loan_id) FROM loan WHERE is_return=0 AND is_lent=1`
        *   `SELECT COUNT(loan_id) FROM loan WHERE is_return=1 AND is_lent=1`
        *   `SELECT COUNT(loan_id) FROM loan WHERE is_return=0 AND is_lent=1 AND renewed > 0`
        *   `SELECT COUNT(loan_id) FROM loan WHERE is_return=0 AND is_lent=1 AND due_date < now()`
    *   We can combine all 5 into a single query in `getSummary()`:
        ```sql
        SELECT
            COUNT(loan_id) AS total,
            SUM(CASE WHEN is_return=0 AND is_lent=1 THEN 1 ELSE 0 END) AS new,
            SUM(CASE WHEN is_return=1 AND is_lent=1 THEN 1 ELSE 0 END) AS `return`,
            SUM(CASE WHEN is_return=0 AND is_lent=1 AND renewed > 0 THEN 1 ELSE 0 END) AS extend,
            SUM(CASE WHEN is_return=0 AND is_lent=1 AND due_date < now() THEN 1 ELSE 0 END) AS overdue
        FROM loan
        ```
    *   This turns 5 queries into 1, avoiding multiple table scans (or multiple index lookups/counts), and drastically reduces the overhead of connecting to the DB 5 times in PHP. This is a very clean <50 line fix that directly addresses a performance bottleneck.

3.  **Journal Entry:**
    *   Update `.jules/bolt.md` with a learning about replacing multiple aggregate queries on the same table with conditional aggregation.

4.  **Verification:**
    *   Check `php -l api/v1/controllers/LoanController.php`.
    *   Run any existing tests (using `docker compose up -d --build` then `composer test` or similar, or just check syntax).
    *   Test the `LoanController@getSummary` output structure to ensure it remains exactly the same.
