## Purpose

Provides safe, correlated evidence of how each Telegram update moves through Laravel and its downstream asynchronous work.

## ADDED Requirements

### Requirement: Webhook lifecycle is traceable
The system SHALL create a correlated diagnostic trace for every request to the Telegram webhook that reaches Laravel. The trace MUST identify the received update when an update ID is available, record the terminal request outcome, and record meaningful handling checkpoints, including authentication rejection, duplicate suppression, ignored update, authorization rejection, successful handling, and unhandled failure.

#### Scenario: Accepted message update
- **WHEN** an authenticated Telegram message update is accepted by the webhook
- **THEN** diagnostics show the update ID, a correlation ID, receipt and handling checkpoints, and the successful HTTP outcome

#### Scenario: Duplicate update is ignored
- **WHEN** Laravel receives an update ID that was already claimed
- **THEN** diagnostics show that the update was received and ignored as a duplicate without classifying it as an application failure

#### Scenario: Webhook request fails
- **WHEN** webhook processing raises an exception
- **THEN** diagnostics show a failed terminal outcome with sanitized failure details and the correlation ID

### Requirement: Downstream incident work is correlated
The system SHALL associate incident-analysis work initiated by a Telegram update with that update's diagnostic trace. It MUST record queue dispatch and the terminal state of the related work, including completion, intentional skip, retryable failure, or permanent failure.

#### Scenario: Webhook dispatches analysis
- **WHEN** an accepted update dispatches incident-analysis work
- **THEN** the trace shows the session identifier, queue dispatch checkpoint, and the eventual job outcome

#### Scenario: Queued analysis fails
- **WHEN** incident-analysis work fails after the webhook has returned successfully
- **THEN** the related trace shows the failure state and sanitized failure details

### Requirement: Diagnostics protect sensitive content
The system SHALL NOT persist or write to diagnostic logs Telegram bot tokens, webhook or relay secrets, authorization header values, raw webhook bodies, message text or captions, media identifiers, or AI request/response content. It MAY retain safe operational metadata such as update ID, event type, payload keys, numeric actor and chat identifiers, session identifier, status code, queue name, timestamps, duration, and sanitized exception class and message.

#### Scenario: Trace records an authenticated request
- **WHEN** an authenticated webhook request is traced
- **THEN** its persisted and log-visible metadata excludes credential values and raw message content

### Requirement: Authorized operators can inspect recent diagnostics
The system SHALL provide authenticated panel users a protected diagnostics view of recent Telegram webhook traces. The view MUST present each trace's timestamp, correlation ID, update ID when available, current or terminal outcome, latest checkpoint, related session ID when available, and sanitized failure summary when applicable. It MUST allow an operator to inspect the ordered checkpoints of one trace.

#### Scenario: Operator reviews a failed trace
- **WHEN** an authenticated panel user opens a recent failed webhook trace
- **THEN** the user can see its ordered checkpoints and sanitized failure summary without seeing secrets or message content

#### Scenario: Unauthenticated access is attempted
- **WHEN** an unauthenticated user requests the diagnostics view
- **THEN** the system denies access using the panel's normal authentication behavior
