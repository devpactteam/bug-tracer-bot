## 1. Diagnostic data and logging foundation

- [x] 1.1 Add migrations, models, relationships, indexes, and casts for webhook traces and ordered trace events.
- [x] 1.2 Add the dedicated daily `telegram-webhook` Laravel log channel and retention configuration.
- [x] 1.3 Implement the observability service that creates traces, records ordered checkpoints, updates summaries, emits structured logs, and allow-list sanitizes metadata and error summaries.
- [x] 1.4 Add unit tests proving sanitization excludes credentials, raw payload content, media identifiers, AI data, and unsafe exception details.

## 2. Webhook and job instrumentation

- [x] 2.1 Instrument the Telegram webhook controller to create a correlation context, record receipt and authentication outcomes, and record successful or failed terminal request outcomes without changing API responses.
- [x] 2.2 Instrument Telegram update handling to record duplicate, ignored, unauthorized, message, callback, Telegram action, session, and queue-dispatch checkpoints.
- [x] 2.3 Propagate the trace correlation across incident-analysis job dispatches and instrument job start, skipped/delayed, completion, retryable failure, and permanent failure outcomes.
- [ ] 2.4 Add feature and job tests for accepted, duplicate, unauthorized, ignored, dispatch, completion, and failure trace timelines.

## 3. Protected diagnostics panel

- [x] 3.1 Add authenticated panel routes and controller actions for paginated trace summaries and individual trace timelines.
- [x] 3.2 Build read-only panel views that display the required safe summary fields, ordered checkpoints, and sanitized failures.
- [x] 3.3 Add authorization and rendering tests verifying unauthenticated users are denied and sensitive data is absent from the diagnostics UI.

## 4. Operational verification

- [x] 4.1 Add retention/pruning configuration and a scheduled or documented maintenance path for old diagnostic traces.
- [ ] 4.2 Run targeted tests and the full relevant Laravel test suite; verify database-queue worker behavior and `telegram-webhook` log output in a staging-like configuration.
