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

The MTProxy values are recorded in `.env` for infrastructure use. Telegram MTProxy is an MTProto transport, not an HTTP/SOCKS proxy, so it cannot be passed directly to Laravel's HTTP Bot API client. To route Bot API calls through it, install a local MTProxy-to-HTTP/SOCKS bridge and set `TELEGRAM_HTTP_PROXY` (for example, `socks5h://127.0.0.1:1080`).

The `incident_tickets` table contains the RCA fields (`root_cause_category`, `root_cause_description`, `resolution_action`, `root_cause_author_id`, `resolved_at`) for post-resolution workflows and monthly reporting.

## Panel login

The panel at `/panel` requires an active support user to sign in at `/panel/login` with their Telegram username and password. Usernames are case-insensitive and an optional leading `@` is accepted. All active users retain access to the existing panel features.

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
