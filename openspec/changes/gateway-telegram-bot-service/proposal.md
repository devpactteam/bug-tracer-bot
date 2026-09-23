## Why

The application server cannot call Telegram directly, while most operations in `TelegramBotService` still target Telegram's API. The existing `sendMessage` gateway also omits inline-keyboard parameters and discards Telegram's response, preventing workflows that depend on the returned message ID.

## What Changes

- Extend the existing `send-message.php` gateway while preserving its working GET query contract and accepting JSON requests for compatibility.
- Add separate gateway scripts for `editMessageText`, `deleteMessage`, `answerCallbackQuery`, `getFile`, and Telegram file downloads.
- Add gateway helpers to `SendsTelegramViaGateway` that preserve Telegram JSON responses and raw downloaded file bytes.
- Retain the direct Telegram implementations as commented reference code in `TelegramBotService`, and route its public operations through the gateway trait.
- Add focused tests for gateway parameters, response propagation, error behavior, and file downloads.
- Add opt-in step-by-step diagnostic logging to every deployment PHP script, with a separate log file per script and secrets excluded from log context.
- Keep Telegram operations outside `TelegramBotService`, including the assignee service and polling commands, out of scope for this change.

## Capabilities

### New Capabilities

- `telegram-service-gateway`: Routes every Telegram operation exposed by `TelegramBotService` through standalone intermediary PHP gateway endpoints while preserving the service's public contracts.

### Modified Capabilities

None.

## Impact

- Affected application code: `app/Services/TelegramBotService.php`, `app/Services/Concerns/SendsTelegramViaGateway.php`, and Telegram gateway configuration.
- Affected deployment code: every PHP script under `deployment/`, including the outbound endpoints and webhook relay.
- Affected tests and deployment documentation for gateway endpoint paths and request/response contracts.
- No new Composer dependencies and no changes to `AssigneeNotificationService` or Telegram polling commands.
