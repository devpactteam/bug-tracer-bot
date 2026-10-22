## Context

See `proposal.md` for motivation and `specs/ticket-telegram-delivery-observability/spec.md` for the behavior contract. The ticket activity timeline is backed by `ticket_logs`, whose existing action, description, actor, and value fields can represent these delivery events. Ticket messages use the configured gateway and currently expose a boolean outcome without a guaranteed Telegram message ID; ticket photos use a direct Telegram API request and return a response or throw on an HTTP failure.

## Goals / Non-Goals

**Goals:**

- Persist a delivery record before each outbound request, then update it with the result so an interrupted send remains visible as pending/unknown.
- Make gateway acceptance, failure, and skipped delivery distinguishable in the ticket timeline.
- Record message and attachment outcomes separately and retain safe diagnostic details when the gateway provides them.
- Reuse delivery outcomes to identify tickets that still need their first successful notification during the existing registration retry flow.

**Non-Goals:**

- Claim that Telegram accepted a message means the recipient received or read it.
- Store or display ticket message content, attachment content, raw gateway responses, or credentials in delivery history.
- Add a separate diagnostics page or a general-purpose Telegram message audit system.

## Decisions

### Store delivery events in the existing ticket log

Use one `ticket_logs` row per ticket message or photo attempt. Create the row before calling the gateway with an attempted outcome, then update it to accepted, failed, or skipped. Use the existing action and value fields to identify delivery item type and outcome; put the target assignee and sanitized summary in the existing actor/description fields. This keeps the history on the ticket and leaves interrupted requests visible without a schema migration.

Alternative considered: add a separate delivery table. That provides a more specialized schema, but adds a second history source and a migration for records that fit the existing ticket activity model.

### Normalize ticket message results without retaining response bodies

Have the assignee notification path inspect an explicit gateway success result rather than treating a configured chat ID as success. Preserve a safe failure category or sanitized summary when the gateway exposes one; otherwise record a generic gateway failure. Never persist raw response or exception text without sanitization. The outcome label `accepted` means only that the configured gateway reported success.

Alternative considered: continue returning only a boolean. That preserves a narrow API but loses information needed to distinguish an accepted send from a failed one in both history and retry decisions.

### Preserve the direct Telegram API photo path

Keep sending photos through the existing direct Telegram API client. Treat a successful API response as accepted and an exception or unsuccessful response as failed; store only a safe summary, never a raw response body, caption, or file path. This keeps observability scoped to ticket delivery and avoids adding a photo endpoint to the gateway.

Alternative considered: add photo delivery to the gateway. That changes the transport architecture and deployment surface without being required to record photo outcomes.

### Log every attachment attempt separately

Create and finalize an independent log event for each photo send, preserving the existing best-effort behavior so one failed attachment does not prevent later attachments. The ticket message outcome remains separate from attachment outcomes.

Alternative considered: combine attachment status into the ticket message entry. A single summary can conceal partial failure and makes it harder to identify which delivery stage failed.

### Use accepted delivery history for registration retries

The registration flow currently selects open tickets whose `assignee_message_id` is null. Since the assignee gateway does not guarantee a Telegram message ID, use the accepted ticket-message delivery event as the signal that a first delivery succeeded; keep tickets with no accepted event eligible for retry. This avoids interpreting the missing message ID as a failed send.

Alternative considered: keep `assignee_message_id` as the sole signal. That can repeatedly retry messages that the gateway accepted but for which it did not return a message ID.

## Risks / Trade-offs

- [The message gateway may return only a boolean and no useful failure details] → Record a clear generic failure outcome and include a more specific sanitized reason only when available.
- [A process can stop after the request succeeds but before the log is updated] → Leave the pre-created event in an attempted/unknown state rather than falsely recording success; operators can distinguish it from accepted delivery.
- [Historical tickets have no delivery entries] → Treat the absence of an accepted delivery event as retry eligible, matching the existing null-message-ID retry behavior for older tickets.
- [A recipient can still fail to see a gateway-accepted message] → Label the timeline outcome as accepted by the gateway and do not claim receipt or read status.

## Migration Plan

No database migration is expected; use the existing `ticket_logs` columns. Deploy the application changes together so writes and timeline rendering recognize the delivery actions. On rollback, the older application can continue to ignore the new action values, and the rows remain ordinary ticket activity records.
