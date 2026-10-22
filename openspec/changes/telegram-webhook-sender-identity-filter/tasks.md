## 1. Persist Telegram sender identity

- [x] 1.1 Add nullable sender username and Telegram ID columns to webhook traces with an additive migration and model support.
- [x] 1.2 Extract sender identity from message and callback query updates when creating a webhook trace.
- [ ] 1.3 Add focused coverage for sender extraction, absent username or ID values, and safe diagnostic persistence.

## 2. Display and filter sender identity

- [x] 2.1 Add the sender column and username search control to the diagnostics list, displaying the sender ID when the username is absent.
- [x] 2.2 Apply trimmed, case-insensitive partial username filtering with optional leading `@` normalization and preserve the filter in pagination links.
- [ ] 2.3 Add authenticated panel coverage for username display, missing-username fallback, filter matching, and pagination state.

## 3. Verify compatibility

- [x] 3.1 Confirm historical traces with null sender fields still render and remain accessible through the diagnostics page.
