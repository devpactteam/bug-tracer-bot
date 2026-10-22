## Purpose

Give panel operators a ticket-specific record of Telegram delivery attempts and outcomes so they can investigate missing ticket notifications from the ticket detail page.

## ADDED Requirements

### Requirement: Ticket Telegram delivery attempts are recorded
The system SHALL record each ticket delivery message and each associated photo attachment attempt in that ticket's activity history, including the attempt time, intended assignee, delivery item type, and outcome. The system MUST record when delivery is skipped because the assignee or Telegram chat destination is unavailable. A successful ticket message outcome SHALL mean the configured Telegram gateway reported success, and a successful photo outcome SHALL mean the Telegram API reported success; neither outcome MUST be presented as proof that the user received or read the message.

#### Scenario: Ticket message is accepted by the gateway
- **WHEN** the system sends a ticket notification and the configured Telegram gateway reports success
- **THEN** the ticket activity history records a successful message send with its time and intended assignee

#### Scenario: Ticket photo is accepted by the Telegram API
- **WHEN** the system sends a ticket photo and the Telegram API reports success
- **THEN** the ticket activity history records a successful photo send with its time and intended assignee

#### Scenario: Ticket message send fails
- **WHEN** the system attempts to send a ticket notification and the gateway reports failure or throws an exception
- **THEN** the ticket activity history records a failed attempt and a sanitized failure summary when available

#### Scenario: Assignee chat destination is unavailable
- **WHEN** ticket delivery is requested but no assignee or Telegram chat destination is configured
- **THEN** the ticket activity history records that delivery was skipped and the reason

#### Scenario: Ticket photo delivery has mixed outcomes
- **WHEN** one or more ticket photo attachments are sent and their outcomes differ
- **THEN** the ticket activity history records each attachment attempt and outcome separately

#### Scenario: Ticket is automatically re-delivered
- **WHEN** a ticket without a successful Telegram message delivery is retried during assignee registration
- **THEN** the system records the retry as a new delivery attempt in the same ticket's activity history

### Requirement: Ticket delivery history protects sensitive content
The system SHALL NOT store Telegram bot tokens, authorization or secret values, raw Telegram request or response bodies, ticket message text, sample data, or attachment contents in ticket delivery history. It MAY store safe operational metadata and sanitized failure details needed to diagnose the outcome.

#### Scenario: Delivery outcome contains an error
- **WHEN** a Telegram delivery fails with an error containing sensitive values or message content
- **THEN** the stored activity entry excludes those values and retains only a sanitized summary

### Requirement: Operators can inspect ticket delivery history
The authenticated ticket detail page SHALL show ticket Telegram delivery entries in the existing activity timeline, with a clear distinction between accepted, failed, and skipped attempts, their timestamps, and the intended assignee. Failed entries SHALL show the sanitized failure summary when available.

#### Scenario: Operator investigates a missing notification
- **WHEN** an authenticated panel operator opens a ticket with a failed, skipped, or retried Telegram delivery
- **THEN** the operator can identify the delivery outcome and relevant safe diagnostic detail in the ticket activity timeline
