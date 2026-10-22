## 1. Persist delivery outcomes

- [x] 1.1 Add a ticket delivery log helper that writes an attempted `ticket_logs` entry before sending and finalizes it as accepted, failed, or skipped with safe metadata.
- [x] 1.2 Update the assignee Telegram send result handling to distinguish gateway acceptance from failure and expose only sanitized diagnostic details.
- [x] 1.3 Record missing assignee or chat destination as a skipped delivery entry without attempting a Telegram request.

## 2. Cover ticket messages, attachments, and retries

- [x] 2.1 Record each ticket message delivery attempt and outcome for initial notification and assignee registration re-delivery.
- [x] 2.2 Record every ticket photo attachment attempt separately while preserving best-effort delivery of remaining attachments after a failure.
- [x] 2.3 Use accepted ticket message delivery history when selecting tickets eligible for registration re-delivery, including tickets without a Telegram message ID.

## 3. Show delivery events in ticket history

- [x] 3.1 Render delivery item type, target assignee, timestamp, attempted/accepted/failed/skipped state, and sanitized failure summary in the existing ticket timeline.
- [x] 3.2 Keep message text, sample data, credentials, raw gateway responses, and attachment contents out of delivery entries.

## 4. Verify behavior

- [x] 4.1 Add focused service coverage for accepted, failed, skipped, interrupted, and attachment delivery outcomes without persisting sensitive content.
- [x] 4.2 Add panel coverage confirming delivery events and failure summaries render in the authenticated ticket detail timeline.
- [x] 4.3 Add coverage that registration re-delivery retries tickets without an accepted message event and does not retry tickets already accepted by the gateway solely because the message ID is absent.
