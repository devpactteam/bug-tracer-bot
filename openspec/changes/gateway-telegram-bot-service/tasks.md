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
