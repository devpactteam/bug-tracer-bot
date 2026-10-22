## Why

Operators can inspect webhook traces but cannot tell which Telegram account sent an update or quickly find one account’s activity. Capturing the sender identity and adding a username filter makes the diagnostics page useful for investigating account-specific reports.

## What Changes

- Record the Telegram sender's username and numeric user ID on new webhook traces when present.
- Show the sender username in the webhook diagnostics table, with the sender ID as a fallback when no username is available.
- Add a username filter to the diagnostics list that works across pagination.
- Keep older traces valid; sender details are unavailable for historical rows because raw webhook payloads are not retained.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `telegram-webhook-observability`: Retain safe sender identity metadata and let authenticated operators view and filter traces by Telegram username.

## Impact

- Affected application areas: webhook observability, Telegram webhook trace persistence, the panel diagnostics controller and list view.
- Affected database: an additive schema change for sender identity fields.
- Affected tests: webhook trace recording and authenticated diagnostics list/filter behavior.
- No new dependencies or Telegram API calls are required.
