## 1. Gateway Deployment Scripts

- [x] 1.1 Extend `send-message.php` to normalize GET and JSON inputs, forward HTML parse mode and reply markup, and propagate Telegram responses securely.
- [x] 1.2 Add independent gateway scripts for edit-message, delete-message, answer-callback-query, and get-file operations.
- [x] 1.3 Add a download-file gateway script that streams Telegram file bytes and preserves upstream status and content type.

## 2. Laravel Gateway Integration

- [x] 2.1 Add configurable gateway endpoint URLs while retaining the existing send gateway URL contract.
- [x] 2.2 Expand `SendsTelegramViaGateway` with typed helpers for all operations and response forms used by `TelegramBotService`.
- [x] 2.3 Comment the direct implementations in `TelegramBotService` and delegate each public Telegram operation to the gateway helpers.

## 3. Verification and Documentation

- [x] 3.1 Add or update tests covering send parameters, complete JSON responses, the other gateway operations, failure handling, and raw downloads.
- [x] 3.2 Update deployment environment examples and README instructions for the new endpoint files and scope limitation.
- [x] 3.3 Run available syntax checks, focused tests, formatting checks, and strict OpenSpec validation.

## 4. Deployment Diagnostics

- [x] 4.1 Add opt-in structured logging helpers and step events to every outbound gateway PHP script, with a distinct file per endpoint and sensitive values excluded.
- [x] 4.2 Add the same per-script logging behavior to the Telegram webhook relay, including validation, forwarding, retry response, and timing events.
- [x] 4.3 Document logging environment variables, permissions, safe log placement, and rotation guidance in deployment examples and README.
- [x] 4.4 Add logging smoke coverage and rerun syntax, focused tests, formatting, and strict OpenSpec validation.
