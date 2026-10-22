## MODIFIED Requirements

### Requirement: Diagnostics protect sensitive content
The system SHALL NOT persist or write to diagnostic logs Telegram bot tokens, webhook or relay secrets, authorization header values, raw webhook bodies, message text or captions, media identifiers, or AI request/response content. It MAY retain safe operational metadata such as update ID, event type, payload keys, numeric actor and chat identifiers, the Telegram sender username when present, session identifier, status code, queue name, timestamps, duration, and sanitized exception class and message.

#### Scenario: Trace records an authenticated request
- **WHEN** an authenticated webhook request is traced
- **THEN** its persisted and log-visible metadata excludes credential values and raw message content

#### Scenario: Trace records a sender username
- **WHEN** an incoming Telegram update includes a sender username
- **THEN** diagnostics retain that username with the trace without retaining the raw update payload

### Requirement: Authorized operators can inspect recent diagnostics
The system SHALL provide authenticated panel users a protected diagnostics view of recent Telegram webhook traces. The view MUST present each trace's timestamp, correlation ID, update ID when available, sender username when available or sender ID when no username is available, current or terminal outcome, latest checkpoint, related session ID when available, and sanitized failure summary when applicable. It MUST allow an operator to inspect the ordered checkpoints of one trace and filter the trace list by sender username. The username filter MUST match case-insensitively, accept a value with or without a leading `@`, and remain applied while navigating paginated results.

#### Scenario: Operator reviews a failed trace
- **WHEN** an authenticated panel user opens a recent failed webhook trace
- **THEN** the user can see its ordered checkpoints and sanitized failure summary without seeing secrets or message content

#### Scenario: Operator filters traces by username
- **WHEN** an authenticated panel user submits a username filter
- **THEN** the diagnostics list shows traces whose sender username matches the filter case-insensitively, whether or not the input begins with `@`

#### Scenario: Operator navigates filtered results
- **WHEN** an authenticated panel user moves to another page of filtered traces
- **THEN** the username filter remains applied to that page

#### Scenario: Trace has no Telegram username
- **WHEN** a trace has a sender ID but the sender has no Telegram username
- **THEN** the diagnostics list displays the sender ID in place of a username

#### Scenario: Unauthenticated access is attempted
- **WHEN** an unauthenticated user requests the diagnostics view
- **THEN** the system denies access using the panel's normal authentication behavior
