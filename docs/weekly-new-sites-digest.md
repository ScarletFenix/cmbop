# Weekly personalised new-site digest

Internal Laravel scheduled mail. It is **not** a Grok Bot routine and it is **not** an Admin → Campaigns blast.

## What it sends

Advertisers who previously **paid** for a placement in a niche + country pair receive a weekly HTML+text email of **newly catalogued** publisher listings in those same pairs.

- Pairs are inferred from paid, non-cancelled, **non-refunded** order items (including Managed by SLB). A Finance/UK buy plus a Tech/US buy yields those two pairs — never the independent cross-product.
- Advertisers can edit pairs and the topic toggle at `/advertiser/new-site-digest` (also linked from notification preferences and from the email).
- Eligible listings are `catalogVisible` (active, not archived, not cancelled-bulk), with `first_catalogued_at` **after** the activation watermark. Updates and reapprovals do not change `first_catalogued_at`.
- Previously ordered sites/domains and already-emailed sites/domains are excluded. Domain is deduped.
- Minimum **two** matches, maximum **five**. Fewer than two waits; extras carry forward because they are only recorded as sent when included in a delivered digest.
- One email per advertiser per ISO week (Asia/Karachi). Prices, metrics, visibility, and permission are rechecked at dispatch.

## Timing

Default: **Monday 10:00 Asia/Karachi** (`WEEKLY_NEW_SITES_TZ`, `WEEKLY_NEW_SITES_TIME`, `WEEKLY_NEW_SITES_WEEKDAY`). Karachi has no DST, so that is **Monday 05:00 UTC**. The schedule entry uses `->timezone('Asia/Karachi')` and does not depend on `APP_TIMEZONE`.

Command: `php artisan emails:send-weekly-new-sites-digest` (`--dry-run`, `--limit`, `--user=`).

Existing Hostinger cron already runs `schedule:run` every minute. Mail still needs `queue:work --queue=default,emails` **or** `MAIL_QUEUE_AUTO_DRAIN=true`.

## Enabled by default (this type only)

`email_notifications.types.weekly_new_sites.default_enabled = true`. Turning it off in Admin → Email Center or Admin → Weekly new sites does **not** enable unrelated reminders. Global off also drops already-queued digest jobs at `send()` time.

This default is for **eventual deployment**. Shipping the PR is not permission to migrate production, send real mail, or run live campaigns.

## Watermark / SQL

Migration `2026_11_08_100000_add_weekly_new_sites_digest` and phpMyAdmin-safe `database/sql/add_weekly_new_sites_digest.sql`:

- `sites.first_catalogued_at` backfilled from `created_at` for existing rows
- `weekly_new_sites_settings.activated_at` set once to now

Listings first catalogued at or before the watermark are never emailed as new.

`php artisan ops:production-ready --repair` runs migrations; it does not send mail.

## Consent

Fail closed. A paid order is never written as consent.

| Basis | When |
|---|---|
| Recorded consent | Latest `user_consents.marketing_consent = true` |
| Topic opt-in | Stored `weekly_new_sites` preference row enabled |
| PECR existing-customer | Qualifying paid placement **and** a `user_consents` row (registration checkbox was shown) **and** no later opt-out |

Unknown (no consent row, no topic opt-in) is **not** granted, even with a paid order. Global `marketing_emails = false`, topic off, invalid address, and permanent SMTP failures already on `email_logs` all block. Signed unsubscribe uses an opaque token (no user id in the URL) and does not open-redirect.

The footer is the SEOLinkBuildings brand, a support line, preference link, and unsubscribe. Invoice issuer copy (Teqno Ltd trading name, company number, registered office) stays on invoices only.

## What the email may not include

Publisher contacts or identities beyond the public listing, buying rates, markup, advertiser counts/order volume, private wallet, internal notes. Missing DA/DR/traffic/price/screenshot are omitted, not invented. Homepage images use the existing screenshot pipeline only; otherwise a labelled preview card (not claimed as a screenshot). Invoice legal-entity copy is not included.

Click/order reporting is observational (“paid order for the same site after a click”) and is not causal attribution.
