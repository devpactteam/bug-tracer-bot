## Purpose

Provide an intermediary HTTP gateway for every Telegram operation exposed by the main bot service when the application server cannot reach Telegram directly.

## ADDED Requirements

### Requirement: Send messages through the gateway
The system SHALL send main-bot messages through the configured gateway using the existing GET-compatible contract. It SHALL forward the bot token, chat identifier, text, HTML parse mode, and optional inline reply markup, and SHALL return Telegram's complete JSON response.

#### Scenario: Message with inline keyboard
- **WHEN** the main bot sends a message with reply markup
- **THEN** the gateway forwards the text, HTML parse mode, and reply markup to Telegram and returns a response containing Telegram's result fields

#### Scenario: Existing GET client
- **WHEN** a client invokes the send-message endpoint with `token`, `chatId`, and `text` query parameters
- **THEN** the endpoint forwards the message using those values

### Requirement: Mutating message operations use dedicated endpoints
The system SHALL expose separate gateway endpoints for editing message text, deleting a message, and answering a callback query, and SHALL preserve Telegram's JSON response and HTTP failure semantics.

#### Scenario: Edit a message
- **WHEN** the service edits a message with text and optional reply markup
- **THEN** the edit-message endpoint calls Telegram's `editMessageText` operation with the corresponding parameters

#### Scenario: Delete a message
- **WHEN** the service deletes a known message from a chat
- **THEN** the delete-message endpoint calls Telegram's `deleteMessage` operation with the chat and message identifiers

#### Scenario: Answer a callback query
- **WHEN** the service answers a callback query
- **THEN** the answer-callback endpoint forwards the callback identifier and optional text and returns Telegram's response

### Requirement: Telegram files use the gateway
The system SHALL resolve Telegram file identifiers and download the resulting file content through dedicated gateway endpoints without making a direct Telegram request from the application server.

#### Scenario: Resolve and download a file
- **WHEN** the service receives a valid Telegram file identifier
- **THEN** it resolves the file path through the get-file endpoint and can retrieve the exact raw bytes through the download-file endpoint

#### Scenario: File operation fails
- **WHEN** resolving or downloading a Telegram file fails
- **THEN** the service reports the failure and returns null, preserving its existing public behavior

### Requirement: Gateway input compatibility
Each gateway endpoint SHALL accept query-string inputs used by the application and SHALL also accept an equivalent JSON body, including values nested below a `body` key.

#### Scenario: JSON gateway request
- **WHEN** an endpoint receives valid parameters as JSON rather than query parameters
- **THEN** it performs the same Telegram operation and returns the same response contract

### Requirement: Scope remains limited to the main bot service
The change SHALL NOT alter Telegram access performed by the assignee notification service or the long-polling console commands.

#### Scenario: Deferred Telegram integrations
- **WHEN** the gateway change is deployed
- **THEN** assignee-bot operations and polling retain their existing implementations

### Requirement: Deployment diagnostics are configurable and separated
Every deployment PHP script SHALL write structured, step-by-step diagnostic events to its own log file when deployment logging is enabled through an environment variable. Logging SHALL be disabled by default and SHALL NOT record bot tokens, webhook secrets, authorization secrets, message contents, callback text, or downloaded file bytes.

#### Scenario: Logging enabled
- **WHEN** `AMPTRACE_DEPLOYMENT_LOG_ENABLED` is set to a true value and a deployment script handles a request
- **THEN** that script appends timestamped events with a request identifier, processing stage, status, timing, and safe metadata to its own log file

#### Scenario: Logging disabled
- **WHEN** `AMPTRACE_DEPLOYMENT_LOG_ENABLED` is unset or false
- **THEN** deployment scripts do not create or append diagnostic log files

#### Scenario: Request fails
- **WHEN** validation, cURL initialization, Telegram communication, or Laravel relay communication fails
- **THEN** the responsible script records the failing stage and safe error metadata before returning its error response
