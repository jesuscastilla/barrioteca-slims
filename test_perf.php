<?php
// We can optimize the LoanController getSummaryDate to use a single query instead of a loop executing 3 queries for each date.

$query = "
SELECT
    loan_date,
    SUM(CASE WHEN is_lent = 1 AND renewed = 0 AND is_return = 0 THEN 1 ELSE 0 END) AS loan_count,
    SUM(CASE WHEN is_lent = 1 AND is_return = 1 THEN 1 ELSE 0 END) AS return_count,
    SUM(CASE WHEN is_lent = 1 AND renewed >= 1 AND is_return = 0 THEN 1 ELSE 0 END) AS extend_count
FROM loan
WHERE loan_date IN ('date1', 'date2', 'date3')
GROUP BY loan_date
";

// Or another approach is to optimize the `getTotal`, `getNew`, `getReturn`, `getExtends`, `getOverdue` methods in `getSummary()` by batching them in a single query since they are all querying `loan` table without any `GROUP BY` but taking a global COUNT.
