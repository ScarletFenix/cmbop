# Admin → Campaigns

Bulk marketing / platform-update email from **Admin → Updates & Campaigns**
(`/admin/campaigns`). This is **not** advertiser `/campaigns` (orphaned project
UI). Recipients are marketplace advertisers and publishers only — never admins.

## Send path

1. Compose subject, HTML body, optional CTA, audience, and the two checkboxes
   (`respect_preferences`, `include_unverified`).
2. Confirm uses `GET /admin/campaigns/recipient-count` so the dialog shows the
   live count (and how many unverified were excluded).
3. `POST /admin/campaigns/send` creates an `email_campaigns` row (`queued`),
   inserts `email_campaign_recipients` (`pending`) in one transaction, logs
   `campaign.queued`, then dispatches `SendEmailCampaignJob` on the **`emails`**
   queue. Flash: **Campaign queued for N recipient(s).**
4. The job marks the campaign `sending`, preference-skips or queues
   `AudienceCampaignMail`, then finalizes `sent` (if any mail left the job) or
   `failed`. A thrown handle (or timeout) fails leftover **pending** rows,
   recounts, and marks the campaign `failed` (not stuck `sending`). Already
   queued mail can still deliver afterward.
5. Individual `AudienceCampaignMail` failures mark that recipient `failed`
   (`error`) and recount. A late `marketing_emails` opt-out, before the queued
   mail actually sends, is honored when `respect_preferences` is on. If Email
   Center disables the `audience_campaign` type, pending rows are skipped
   (`disabled`) and the campaign ends `failed`.

Throttle: preview `20/min`, send `6/min`, recipient-count `30/min`.

## HTML and targeting

- Body is sanitized with `CampaignHtml` (allowlist `p, br, strong, b, em, i, u,
  ul, ol, li, a, h1–h3, blockquote`). Event handlers and `javascript:` / `data:`
  hrefs are dropped. CTA URLs must be `http` or `https`.
- Campaign `collect()` / `count()` default **`includeUnverified = false`**.
  Audience Inventory census (`paginate` / `export` / `stats()`) still includes
  unverified unless asked otherwise. Inventory cards show **all** plus
  **emailable (verified)** so the compose count matches the subtitle.
- `selected` accepts advertiser/publisher IDs only. Admin IDs are dropped.
- Custom picker is capped at 200 users per role (`AudienceInventoryService::PICKER_LIMIT`).
- `advertisers_no_orders` is an alias of `advertisers_never_checked_out` (no
  order row). `advertisers_no_paid_orders` is anyone without a **paid or
  refunded** order (abandoned checkout stays in; a later refund is still a
  customer). `advertisers_paid_orders` is the inverse.
- Extra inventory / campaign keys: `both`, `advertisers_deposited_no_orders`
  (credited deposit, no order row), `publishers_no_active_sites` (no
  `active=1` site). Tab slugs (`no_orders`, `paid_orders`, …) normalize
  through `AudienceInventoryService::normalizeAudienceKey()`.
- Inventory search / filters apply to the table and CSV only. **Email this
  audience** still sends the full segment (verified by default).
- Audience CSV is streamed (`chunkById`), UTF-8 BOM, formula-safe cells,
  capped at 10_000 rows, throttled `12/min`, and logged as `audience.exported`.

Do **not** change `queryForRole()` default (still includes unverified). Digests
and add-site / deposit reminders keep their own queries.

## Preferences and stale mail

- `respect_preferences` is a real checkbox (hidden `0` + checkbox `1`). The
  controller uses `$request->boolean('respect_preferences')` with no default
  `true`.
- Preference gate is `marketing_emails` only. Order, payment, and security
  mail stay on. The job pre-checks before queueing; the mailable checks again
  at send time so an unsubscribe that lands in between is still honored.
- Transactional `PlatformMailable` drops after `MAIL_MAX_AGE_HOURS` (24).
  Campaign mail uses `MAIL_CAMPAIGN_MAX_AGE_HOURS` (72). A dropped send marks
  the recipient `skipped` (`stale`, `preference`, or `disabled`).

## Signed unsubscribe

- `GET|POST /email/unsubscribe/{user}` (`email.unsubscribe`), `throttle:30,1`.
- HMAC is signed against the **path only** (`absolute: false`) and prefixed with
  `app_public_url()`, same as verify / deposit-approve links. Default expiry
  `MAIL_UNSUBSCRIBE_EXPIRE_DAYS` (30).
- GET shows a confirm page. POST sets **only** `marketing_emails=false`.
- One-click (`List-Unsubscribe=One-Click` or JSON) returns empty **200**.
- CSRF is excepted for `email/unsubscribe/*` (Gmail POSTs have no token).
- Campaign markdown footer + `List-Unsubscribe` / `List-Unsubscribe-Post`
  headers share one cached signed URL. Order receipts do **not** get this footer.

## Queue / ops

Campaign jobs and `AudienceCampaignMail` ride the `emails` queue. Same rules as
other platform mail — see [`ops-mail-reminders.md`](ops-mail-reminders.md):

```
php artisan queue:work --queue=default,emails
```

or leave `MAIL_QUEUE_AUTO_DRAIN=true` (default). After deploy, migrate so
`email_campaign_recipients` exists (`ops:production-ready --repair` / first
production page view). `LogSentEmail` will not break other mail if that table
is missing; campaign delivery status just will not sync until migrate runs.

## Tests

```
php artisan test tests/Unit/CampaignHtmlTest.php
php artisan test tests/Feature/AdminCampaignsTest.php
php artisan test tests/Feature/EmailUnsubscribeTest.php
php artisan test tests/Feature/AdminCampaignsDocsTest.php
```
