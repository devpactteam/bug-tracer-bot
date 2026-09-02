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
