## 2024-05-24 - Accessibility improvements on generic links
**Learning:** Found that hardcoded values in templates such as `alt="Avatar of Jonathan Reinink"` might be present instead of dynamic ones. There are several anchor tags that act as buttons (icon-only links like Help, Close, or Shopping Basket) that lack `aria-label` and `role="button"`.
**Action:** When inspecting navigation and template parts, check for missing generic `aria-label` attributes on icon-only links and ensure `alt` attributes rely on user session data where appropriate.
