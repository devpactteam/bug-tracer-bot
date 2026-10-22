## Why

Operators can see that a ticket was created but cannot tell from its detail page whether its Telegram notification was attempted or why it failed. Recording ticket-specific delivery outcomes in the existing activity timeline will make missing notifications easier to investigate.

## What Changes

- Record ticket notification attempts and outcomes in the ticket activity history, including missing assignee chat details and sanitized send failures.
- Capture outcomes for the ticket message and its photo attachments separately where possible.
- Show the delivery event, target assignee, timestamp, and useful safe failure detail in the ticket history.
- Distinguish a successful send request from confirmed user receipt; Telegram API acceptance does not prove that a user read the message.

## Capabilities

### New Capabilities

- `ticket-telegram-delivery-observability`: Persist and show ticket-specific Telegram notification attempts and outcomes in the ticket activity history.

### Modified Capabilities

None.

## Impact

- Ticket notification flow in `AssigneeNotificationService` and its ticket creation/re-delivery callers.
- Ticket activity log persistence and the panel ticket detail timeline.
- Telegram gateway result handling for assignee messages and direct Telegram API result handling for photo attachments.
- Database schema may need an additive change if existing ticket log fields cannot safely represent structured delivery outcomes.
- Focused service and panel coverage; no new external dependencies.
