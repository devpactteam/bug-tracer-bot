## Why

Telegram updates can reach the Laravel webhook yet appear to do nothing because normal control-flow decisions and downstream queue work are not observable. Operators need a fast, safe way to determine where a webhook update stopped without inspecting deployment relay scripts or exposing secrets and message content.

## What Changes

- Add Laravel-native, structured tracing for Telegram webhook receipt, authentication, update handling decisions, outgoing Telegram actions, and dispatched incident-analysis jobs.
- Add a protected diagnostics view for recent webhook traces and their related asynchronous job outcomes.
- Record safe diagnostic metadata and failure details while redacting webhook credentials and message/AI payload content.
- Correlate records produced during one webhook update across the HTTP handler and queued incident-processing work.

## Capabilities

### New Capabilities

- `telegram-webhook-observability`: Safe, correlated visibility into Telegram webhook processing and its downstream Laravel queue work.

### Modified Capabilities

- None.

## Impact

- Affects the Telegram webhook controller and update handler, incident-processing jobs, Laravel logging configuration, database schema, and protected panel routes/views.
- Adds persisted diagnostic trace data and a dedicated Laravel log channel; no external telemetry service is required.
