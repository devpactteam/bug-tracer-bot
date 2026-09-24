## Context

The Telegram webhook controller directly invokes the update handler and returns JSON. The handler has multiple legitimate early-return branches, while some paths dispatch `ProcessIncidentBundleJob` to the configured queue. Existing Laravel logs record exceptions but do not provide one ordered, searchable account of a webhook update's decisions or downstream work. See proposal.md and the `telegram-webhook-observability` specification for the required behavior.

## Goals / Non-Goals

**Goals:**

- Provide a durable, panel-visible diagnostic timeline for each Laravel-received Telegram webhook request.
- Correlate synchronous webhook decisions and subsequent incident-analysis job work.
- Mirror sanitized lifecycle events to a dedicated Laravel daily log for quick server-side tailing.
- Preserve normal webhook responses and processing behavior.

**Non-Goals:**

- Observe the deployment relay scripts, Telegram delivery attempts that never reach Laravel, or generic application traffic.
- Store raw Telegram payloads, credentials, media IDs, or AI content for debugging.
- Introduce a third-party observability service or replace Laravel's failed-job facilities.

## Decisions

### Persist a trace and ordered event records

Add a webhook trace record for each request and a child event record for each checkpoint. The trace holds lookup and summary fields (correlation ID, update ID, event type, outcome, session ID, latest checkpoint, failure summary, timestamps); events retain the ordered timeline plus a JSON metadata field containing only allow-listed data.

This supports the panel without parsing log files, retains evidence after rotation, and cleanly links queued work. A single JSON trace blob was considered, but child events make ordered additions and queries simpler. Log-only tracing was rejected because it cannot reliably power an in-app diagnostic view or job correlation.

### Use an explicit observability service with a request-scoped correlation ID

Introduce one Laravel service responsible for creating traces, recording checkpoints, sanitizing metadata, and emitting to the `telegram-webhook` log channel. The controller creates the trace before authentication and passes the correlation context to downstream calls; handler and job code record named outcomes through the service.

An implicit global logging context alone was considered, but queued jobs do not inherit request context reliably. Passing the trace/correlation ID in the job payload (or resolving it via the session at dispatch) gives the asynchronous boundary an explicit contract.

### Instrument branch outcomes at ownership boundaries

Record receipt and authentication outcomes in the controller; record claim, ignored, authorization, message/callback, and dispatch decisions in the update handler; and record start, skipped, delayed/re-dispatched, completion, and failure outcomes in the incident-processing job. Instrumentation remains at these boundaries rather than logging every method call, preventing noise and avoiding content capture.

### Enforce allow-list sanitization at the observability boundary

The observability service accepts named event metadata but removes forbidden keys recursively and only writes the approved operational fields. It also derives a safe error summary from exceptions rather than storing trace strings or request data.

Relying on every caller to omit sensitive fields was rejected because future changes could accidentally leak Telegram or AI data.

### Add a dedicated daily log channel and restricted panel route

Configure a `telegram-webhook` daily Laravel log channel. Add panel routes/controllers/views that follow the existing authenticated panel access model and present paginated trace summaries plus an individual trace timeline. The view is read-only.

Using the default Laravel log alone would mingle operational webhook facts with unrelated application errors; a separate admin API is unnecessary for the immediate debugging workflow.

## Risks / Trade-offs

- [High webhook volume increases database writes] → Store compact allow-listed metadata, index lookup fields, paginate the panel, and define a scheduled retention/pruning mechanism.
- [Instrumentation changes can obscure normal webhook behavior] → Keep tracing best-effort: diagnostics persistence/logging failures are reported but do not alter the webhook's functional response or retry semantics.
- [Queued work has no request memory] → Persist and pass the trace correlation explicitly at each dispatch boundary.
- [Exception messages can contain sensitive input] → Sanitize and truncate exception summaries; never persist stack traces in trace records.
- [Diagnostics themselves expand panel data exposure] → Reuse authenticated panel authorization and show only redacted fields.

## Migration Plan

1. Deploy schema changes, models, configuration, and code together; the feature starts collecting traces without replaying historical updates.
2. Verify the panel access rule and daily log file permissions in staging using successful, duplicate, unauthorized, and failed job cases.
3. Deploy with the normal queue worker restart process so updated job payload handling is active.
4. Roll back application code if needed; existing diagnostic tables are additive and can remain harmlessly until a later cleanup migration. Disable the dedicated log channel through environment configuration if log volume requires immediate reduction.

## Open Questions

- Retention duration and the maximum number of visible traces can be selected during implementation as operational configuration; they do not change the tracing contract.
