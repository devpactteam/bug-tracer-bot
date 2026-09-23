# Incident reporting and RCA bot

This scaffold implements the shared Telegram update pipeline for both local long-polling (`php artisan telegram:poll`) and production webhook delivery.

Configure:

```dotenv
TELEGRAM_BOT_TOKEN=
TELEGRAM_ALLOWED_OPERATOR_IDS=123,456
TELEGRAM_WEBHOOK_SECRET=change-me
INCIDENT_DEBOUNCE_SECONDS=45
INCIDENT_AI_DRIVER=fake
OPENAI_API_KEY=
```

Run migrations and a queue worker in the normal Laravel way. Set `INCIDENT_AI_DRIVER=gapgpt` and provide `AI_API_KEY` to use the OpenAI-compatible GapGPT endpoint. The same adapter also supports `AI_API_BASE_URL=https://api.openai.com/v1`. The fake adapter is deterministic and supports edge-case prompts containing `[clarify]`, `not sure`, `everyone`, or `only me`.

For production webhooks, configure Telegram with the same `TELEGRAM_WEBHOOK_SECRET`; requests with a mismatched `X-Telegram-Bot-Api-Secret-Token` are rejected. For local development, use `php artisan telegram:poll` and leave the webhook unset.

### Intermediary webhook server

The standalone file [deployment/telegram-webhook-relay.php](deployment/telegram-webhook-relay.php) lets Telegram call an HTTPS server that can reach the Laravel server. It requires PHP with cURL and a valid TLS certificate. Configure the environment variables on that server, or copy [deployment/.env.example](deployment/.env.example) to a non-public `deployment/.env` beside the scripts. The dependency-free loader reads that file automatically, while server-provided variables take precedence.

```dotenv
AMPTRACE_LARAVEL_WEBHOOK_URL=https://your-app.example.com/api/telegram/webhook
AMPTRACE_TELEGRAM_WEBHOOK_SECRET=same-secret-as-TELEGRAM_WEBHOOK_SECRET
AMPTRACE_RELAY_AUTH_SECRET=a-second-private-secret
```

Set Telegram's webhook to the public URL of the uploaded relay file. The relay verifies Telegram's `X-Telegram-Bot-Api-Secret-Token`, validates the JSON update, forwards it to Laravel, and returns a failure status when Laravel is unreachable so Telegram retries delivery. Telegram requires an HTTPS webhook URL; the secret token is the documented header sent with every webhook request. citeturn0search0turn0search1

Example setup:

```sh
curl -sS -X POST "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/setWebhook" \
  -d "url=https://relay.example.com/telegram-webhook-relay.php" \
  -d "secret_token=$TELEGRAM_WEBHOOK_SECRET" \
  -d 'allowed_updates=["message","callback_query"]'
```

If `AMPTRACE_RELAY_AUTH_SECRET` is configured, add the same value to Laravel as `TELEGRAM_RELAY_AUTH_SECRET`; Laravel will require the relay authorization header as well as Telegram's secret-token header. Do not put the bot token in the relay filename or query string.

The MTProxy values are recorded in `.env` for infrastructure use. Telegram MTProxy is an MTProto transport, not an HTTP/SOCKS proxy, so it cannot be passed directly to Laravel's HTTP Bot API client. To route Bot API calls through it, install a local MTProxy-to-HTTP/SOCKS bridge and set `TELEGRAM_HTTP_PROXY` (for example, `socks5h://127.0.0.1:1080`).

Upload `load-env.php` together with the outbound gateway scripts from `deployment/` to the intermediary server (and the relay when using `deployment/.env`):

- `send-message.php` (or the existing root URL mapped to this script)
- `edit-message.php`
- `delete-message.php`
- `answer-callback-query.php`
- `get-file.php`
- `download-file.php`

`TELEGRAM_GATEWAY_URL` remains the exact working send-message URL and uses the existing `token`, `chatId`, and `text` query contract. It now also carries `parse_mode` and optional `reply_markup`, and the full Telegram JSON response is returned so the application receives `message_id`. The other operations default to the sibling filenames listed above. Set `TELEGRAM_GATEWAY_BASE_URL` when those files share another base URL, or set the individual `TELEGRAM_GATEWAY_*_URL` values when their public URLs differ.

All six scripts also accept equivalent JSON input, either at the top level or under `body`. Serve them only over HTTPS and configure the intermediary web server not to record query strings because requests contain bot tokens. This change gateways only `TelegramBotService`; assignee-bot operations and both `getUpdates` polling commands still require direct Telegram connectivity and are intentionally deferred.

### Deployment diagnostics

Every PHP script in `deployment/`, including the webhook relay, supports opt-in structured logging:

```dotenv
AMPTRACE_DEPLOYMENT_LOG_ENABLED=true
AMPTRACE_DEPLOYMENT_LOG_DIR=/var/log/amptrace-telegram
```

Expose these variables to PHP through the intermediary server's environment or hosting control panel, or place them in the non-public `deployment/.env` file. Logging is disabled when `AMPTRACE_DEPLOYMENT_LOG_ENABLED` is absent or false. If the log directory is omitted, scripts use `deployment/logs`; for production, prefer a directory outside the public web root and grant the PHP worker write access without making it world-writable.

Each script writes JSON Lines to its own file, such as `send-message.log`, `download-file.log`, or `telegram-webhook-relay.log`. Records contain timestamps, per-request IDs, processing stages, timing, status codes, byte counts, and safe request metadata. Bot tokens, webhook/relay secrets, authorization headers, message and callback contents, upstream response bodies, and downloaded bytes are not logged.

Configure the operating system's `logrotate` (or the hosting provider's equivalent) for `*.log` files in this directory. The scripts append with file locking but do not delete or rotate historical logs themselves. Ensure the web server explicitly denies access to `.log` files if logs must remain under the document root.

The `incident_tickets` table contains the RCA fields (`root_cause_category`, `root_cause_description`, `resolution_action`, `root_cause_author_id`, `resolved_at`) for post-resolution workflows and monthly reporting.

## Panel login

The panel at `/panel` requires an active support user to sign in at `/panel/login` with their Telegram username and password. Usernames are case-insensitive and an optional leading `@` is accepted. All active users retain access to the existing panel features.

### HTTPS in production

The login page must be served through HTTPS. Install a valid TLS certificate on the public web server or reverse proxy, set `APP_URL` to the public `https://` URL, and set these production variables:

```dotenv
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
HTTPS_HSTS_MAX_AGE=31536000
TRUSTED_PROXIES=PROXY_IP_OR_CIDR
```

Use the actual reverse-proxy address or CIDR for `TRUSTED_PROXIES`; leave it empty when TLS terminates directly in PHP/Nginx. The app redirects insecure page requests before the login form is shown and rejects insecure form submissions. Local development on `http://localhost` keeps working when `APP_FORCE_HTTPS=false`.

After updating, run `php artisan migrate`. Existing and seeded users have no password until one is explicitly assigned; no shared default password is created. Set the first user's password from the terminal:

```sh
php artisan support-user:password USERNAME
```

The command prompts privately for the new password and its confirmation and also works for password recovery. Once signed in, assign passwords to the remaining users under **کاربران → ویرایش**. New users require a confirmed password of at least 8 characters (at most 72 UTF-8 bytes). Leaving the password blank while editing a user with an existing password preserves it. `php artisan support-user:add` also asks for a password. Passwords are hashed and never displayed in the panel; repeated failed login attempts are limited. Disabling a user blocks both login and subsequent authenticated requests; changing a password invalidates their other panel sessions on their next request.

## Assignees & the second (ticket delivery) bot

- Team members live in `support_users` (seed via `php artisan db:seed --class=SupportUserSeeder`, add more via `php artisan support-user:add`). Each user carries the problem categories they cover (`categories_covered`), whether they can be an assignee (`can_be_assignee`), and `auto_assign_on_mention` (report mentions → auto-assign, used for the project manager).
- The AI analysis returns a problem `category` plus `responsible_side` (`client`/`backend`/`null`). When a category/side maps to an assignable user, that user is pre-selected; if nothing matches, the operator must pick an assignee before the ticket can be approved.
- Finished tickets are delivered to the assigned user through a **second Telegram bot** (`ASSIGNEE_BOT_TOKEN`), long-polled the same way as the main bot via `php artisan telegram:poll-assignee`. To receive tickets, each assignee must `/start` that bot; the poll loop captures their numeric chat id onto `support_users.assignee_chat_id`, then tickets are sent there with the full analysis and sample data.
- Tickets sent to the assignee bot carry an inline workflow: **🔄 در حال بررسی** sets the ticket to `in_progress`, **✅ بررسی و رفع شد** asks the assignee for a resolution note, and **📌 ثبت توضیحات و بستن تیکت** stores the note on `incident_tickets.resolution_note` and closes the ticket (`status=closed`, `resolved_at`, `root_cause_author_id`). **↪️ انتقال به مسئول دیگر** shows the other assignable users; choosing one reassigns the ticket (`assignee_id`), deletes the message from the current chat, and delivers a fresh copy marked "ارجاع شد" to the new assignee's bot chat.
