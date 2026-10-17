## 2024-10-18 - [Reflected XSS in Admin Modules]
**Vulnerability:** Reflected Cross-Site Scripting (XSS) in `$_GET['keywords']` where user input was being directly echoed into HTML within multiple administrative listing modules (fines list, member loan history, system modules, etc.).
**Learning:** Legacy PHP files often concatenate search terms directly into informational UI messages (e.g., "Found X results for your keywords: ..."). In this codebase, while `escape_string` was used for SQL, the same variable/input was echoed without HTML escaping.
**Prevention:** Always sanitize user inputs (e.g., `$_GET` or `$_POST`) using `htmlspecialchars()` or `htmlentities()` before echoing them to the client to prevent script injection.
