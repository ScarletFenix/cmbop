# Ops: mail, reminders, and cron

Production checklist for Seolinkbuildings email delivery and scheduled reminders.

## Must-haves

| Setting / process | Why |
|---|---|
| `APP_URL` = real public origin (`https://…`) | Named routes in mail and signed verify links use this host. |
| `PUBLIC_APP_URL` | Fallback only when `APP_ENV=production` and `APP_URL` is still loopback (misconfigured deploy). Prefer fixing `APP_URL`. |
| Queue worker **or** auto-drain | Mailables queue on `emails`. Run `php artisan queue:work --queue=default,emails`, **or** leave `MAIL_QUEUE_AUTO_DRAIN=true` (default) so web traffic + `mail:drain-queue` clear the backlog. |
| Scheduler every minute | `* * * * * cd /path/to/app && php artisan schedule:run` — drives auto-approve, nudges, digests, onboarding reminders, `mail:drain-queue`. |
| `CRON_SECRET` ≥ 32 chars **only if** using HTTP cron | Optional alternate: `GET /cron/run/{key}` when the host cannot run `schedule:run`. Short/empty secret keeps the route disabled. |

## Scheduled reminder commands (canonical list)

Defined in `bootstrap/app.php` (not `app/Console/Kernel.php`):

- `orders:auto-approve` — every 15 minutes
- `orders:nudge-publishers` / `orders:nudge-advertisers` — hourly
- `emails:send-deposit-reminders` / `emails:send-publisher-add-site-reminders` — daily
- `emails:send-digests` — weekly / monthly
- `sites:send-new-sites-digest` — daily
- `mail:drain-queue` — every minute (when no resident worker)

Verify with `php artisan schedule:list` — each command should appear once.

## Noise control

Accept / reject / live-URL flows send a **dedicated** advertiser mailable. Those paths register `OrderLifecycleMailSuppressor` so the generic `OrderStatusChanged` is not also sent to the advertiser. Admin/support status changes that skip the dedicated mail still get the generic lifecycle email.

## Quick health checks

```bash
php artisan ops:production-ready
php artisan schedule:list
php artisan queue:failed
tail -n 50 storage/logs/laravel.log
```

Confirm a test registration writes a welcome/verify link (with `MAIL_MAILER=log`, search `storage/logs/laravel.log` for `email/verify`).
