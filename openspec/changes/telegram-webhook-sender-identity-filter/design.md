## Context

See `proposal.md` for motivation and `specs/telegram-webhook-observability/spec.md` for the behavior contract. The webhook observability service creates a trace as soon as it receives the decoded Telegram update. The trace table currently stores update metadata but not sender identity, and the diagnostics index paginates the newest traces without filters.

## Goals / Non-Goals

**Goals:**

- Capture the sender identity at trace creation time, before webhook handling can exit early or fail.
- Make username lookup efficient and preserve the selected filter when paginating.
- Keep existing trace rows readable after adding sender fields.

**Non-Goals:**

- Backfill historical sender identities; raw Telegram update bodies are intentionally not retained.
- Add filters by Telegram numeric ID or by sender display name.
- Change Telegram update handling or make additional Telegram API requests.

## Decisions

### Store sender identity as nullable trace columns

Add nullable `sender_username` and `sender_telegram_id` fields to the trace record. This keeps the values directly queryable for list filtering and avoids parsing event JSON for every page. Existing records remain valid with null values.

Alternative considered: store sender fields only in event metadata. That avoids a table change, but makes filtering harder to express and index and couples the list query to event JSON support.

### Extract the sender from supported update shapes when the trace begins

Read `from.username` and `from.id` from `message` updates and `callback_query` updates, matching the sender objects in the webhook payload. Store the username as received and preserve the numeric ID as an integer. If either value is absent, store null for that field. This captures identity for rejected or failed handling paths because trace creation happens first.

Alternative considered: derive the sender later in the update handler. That can miss updates that fail authentication or return early and makes observability depend on business handling.

### Use a case-insensitive partial username filter

Trim the filter value, remove one optional leading `@`, and match it as a case-insensitive substring against `sender_username`. A partial search helps operators find a name without typing the full handle. Preserve the normalized query in pagination links; an empty query returns the unfiltered list.

Alternative considered: exact matching is predictable but less forgiving for a diagnostics search box and does not fit the operator's quick-find goal as well.

### Display the numeric sender ID when the username is absent

Show `@username` when available. Otherwise show the Telegram numeric sender ID, or an em dash when neither identity field is present. Telegram usernames are optional, while numeric IDs can still distinguish users who have not set one.

## Risks / Trade-offs

- [Telegram usernames can change] → Store the username observed on each update; the filter describes the value captured at receipt time, not a live Telegram profile lookup.
- [A substring query can match several usernames] → Keep the username visible on each row so operators can distinguish matches.
- [Older traces have no sender identity] → Leave their new columns null and render the existing trace normally with an em dash.
- [Case-insensitive matching varies by database collation] → Normalize the search expression explicitly so behavior does not depend on the configured collation.

## Migration Plan

1. Add nullable sender username and Telegram ID columns through a forward-only additive migration.
2. Deploy the application changes; new webhook traces begin recording sender identity, while historical rows remain null.
3. Rollback consists of reverting the application and then the additive migration if required; no existing values need transformation.

## Open Questions

None.
