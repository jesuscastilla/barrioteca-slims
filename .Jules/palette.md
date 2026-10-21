## 2024-10-21 - Missing aria-label on Mobile Menu Toggles
**Learning:** Icon-only navigation links (like mobile menu hamburger/close toggles, e.g., `<a ...><span></span></a>`) frequently omit text content. Without an `aria-label`, they are entirely inaccessible to screen readers, preventing visually impaired users from interacting with primary site navigation.
**Action:** Always verify that interactive elements without visible text (like hamburger menus or close buttons) contain an `aria-label` conveying their purpose.
