## Context

See `proposal.md` for motivation. The configured gateway URL is already a working GET endpoint for `sendMessage`. `TelegramBotService` additionally edits and deletes messages, answers callbacks, resolves files, and downloads raw file bytes. Callers depend on Telegram's returned `result.message_id`, while file intake depends on byte-for-byte download responses.

## Goals / Non-Goals

**Goals:**

- Preserve the proven send-message GET URL and its parameter names.
- Make all public operations of `TelegramBotService` use gateway endpoints.
- Preserve current public return values and exception handling.
- Allow the deployment scripts to consume either query parameters or JSON payloads.

**Non-Goals:**

- Gatewaying `AssigneeNotificationService` or either `getUpdates` polling command.
- Replacing the gateway with a generic unrestricted Telegram API proxy.
- Changing webhook delivery, which has its own relay.

## Decisions

### Preserve the current send URL while deriving other endpoint URLs

`incident.telegram.gateway_url` remains the exact working send-message URL. Other endpoint URLs default to sibling PHP filenames under that URL's base and can be overridden individually through configuration. This avoids breaking the deployed send endpoint while supporting conventional file deployments.

Alternative considered: redefine the existing setting as a base URL and append `send-message.php`. This could break the confirmed root-URL deployment and is therefore rejected.

### Use GET from Laravel and accept GET or JSON at the scripts

Laravel will continue to issue GET requests so the confirmed hosting behavior remains compatible. Each script will normalize query values, top-level JSON values, and JSON values nested under `body`. Telegram requests themselves will use form POSTs, matching the existing script and Bot API behavior.

Alternative considered: require JSON POSTs from Laravel. This avoids URL-length and logging concerns but would discard the known-good gateway contract; it can be introduced later as a controlled migration.

### Preserve upstream responses

JSON gateway operations will echo Telegram's body and propagate its HTTP status. The trait will decode that response and use Laravel's normal `throw()` behavior. The download endpoint will return raw bytes and the upstream content type. This preserves message IDs and makes the existing expired-callback exception branch work through the gateway.

### Keep endpoint scripts independently deployable

Each endpoint will contain its small amount of request normalization and cURL forwarding logic instead of requiring a shared include. This introduces duplication but allows individual scripts to be uploaded without hidden dependencies, matching the requested deployment model.

### Retain direct implementations as comments

Each public service method will keep its prior direct Telegram code as a commented reference block and delegate to a narrowly named trait helper. The private direct client remains only to make those reference blocks understandable and is not called by active service paths.

## Risks / Trade-offs

- [GET URLs can expose tokens in intermediary access logs] → Preserve the required compatibility now, document HTTPS and log-handling expectations, and leave authenticated POST migration for a future change.
- [Long text or reply markup can exceed URL limits] → Keep JSON input support in every deployment script so clients can migrate without another script rewrite.
- [Duplicated endpoint plumbing can drift] → Cover every endpoint contract with focused application tests and keep each script intentionally small.
- [Gateway timeout differs for file downloads] → Use a longer timeout for raw file downloads than for JSON API operations.

## Migration Plan

1. Upload the updated send script and the five new endpoint scripts to the gateway server.
2. Confirm the existing send URL and configure endpoint overrides only if sibling filenames are not publicly reachable.
3. Deploy the Laravel configuration, trait, and service changes.
4. Send a keyboard message, edit/delete it, answer a callback, and ingest a photo as smoke tests.
5. Roll back Laravel first if necessary; the expanded send script remains backward-compatible with the old caller.
