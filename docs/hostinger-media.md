# Durable media on Hostinger (MEDIA_PATH)

Public uploads (`sites/`, `site-screenshots/`, `blogs/`, `banners/`) use Laravel’s
`public` disk and are served at `/storage/...` via `public/storage`.

**Problem:** replacing `public_html` during a Hostinger update can wipe
`storage/app/public` (or break the symlink), so catalog images disappear even
though DB paths are unchanged.

**Fix:** keep URLs the same; point the disk root at a folder **outside**
`public_html`.

---

## Phase 1 — App config (already in code)

| Knob | Meaning |
|------|---------|
| `MEDIA_PATH` unset / empty | `storage/app/public` (local + CI) |
| `MEDIA_PATH=/absolute/path` | that directory is the public disk root |

`config/filesystems.php` uses the same root for:

- `disks.public.root`
- `links` → `php artisan storage:link` creates `public/storage` → that root

Controllers already use `Storage::disk('public')` / `->store(..., 'public')` —
no path rewrites needed.

If `MEDIA_PATH` is set but missing/unwritable, the app **logs critical and
throws on boot** so misconfiguration is obvious.

---

## Phase 2 — One-time Hostinger setup (ops)

This agent cannot SSH to live Hostinger. The first production page view (or
`php artisan ops:production-ready --repair`) creates
`/home/USER/persistent/media`, writes `MEDIA_PATH` in `.env`, and repairs
`public/storage`. Still run the rsync below if images already live under
`public_html` and you need to keep them.

Do this once on live, during a short maintenance window. Replace `USER` with
the Hostinger account user.

### 2.1 Create persistent folder

```bash
mkdir -p /home/USER/persistent/media
# Ownership: same user PHP runs as (typically the hosting user)
```

### 2.2 Move existing files

Prefer rsync (verify before deleting old copies):

```bash
# Adjust source if your layout differs (some installs use public_html/storage/...)
rsync -av ~/domains/YOURDOMAIN/public_html/storage/app/public/ /home/USER/persistent/media/
```

Confirm counts for at least:

- `sites/`
- `site-screenshots/`
- `banners/`, `blogs/` if present

### 2.3 Wire Laravel

In live `.env`:

```env
MEDIA_PATH=/home/USER/persistent/media
```

Use an **absolute** path (relative paths break when cwd differs for cron/queue).

```bash
cd /path/to/app   # Laravel root (often public_html or one level above public/)
php artisan config:clear
php artisan config:cache   # if you use config cache in production
rm -f public/storage       # or public_html/public/storage if docroot is public/
php artisan storage:link
ls -la public/storage
# should show → /home/USER/persistent/media
```

If the document root is only `public/`, the symlink is
`…/public/storage` → `/home/USER/persistent/media`.

### 2.4 Smoke test

- Open 3–5 known image URLs (`/storage/sites/...`, `/storage/site-screenshots/...`)
- Upload one new admin site image → file under `persistent/media/sites/`
- Refresh one screenshot → new file under `persistent/media/site-screenshots/`

### 2.5 Keep an old backup briefly

Leave a backup of the previous `storage/app/public` for about a week; empty it
only after verification (keep the directory for Laravel if needed).

---

## Phase 3 — Deploy SOP (every website update)

**Pinned rule for whoever updates the site:** media lives outside `public_html`.
Code deploys must never wipe it.

### Allowed to overwrite

App code under `public_html` (or the Laravel root), including:

- `app/`, `bootstrap/`, `config/`, `database/`, `resources/`, `routes/`, `vendor/`
- `public/assets/` (CSS/JS), compiled views, etc.

### Never overwrite / never delete

| Path | Why |
|------|-----|
| `/home/USER/persistent/media/**` | All site images / screenshots / blogs / banners |
| Live `.env` | Contains `MEDIA_PATH`, DB, Stripe, mail secrets |
| `public/storage` symlink | Web entry to durable media — recreate if lost |

### After each deploy checklist

Copy/paste for the person doing the update:

```bash
# 1) MEDIA_PATH still set
grep '^MEDIA_PATH=' .env

# 2) Symlink exists and points at persistent media
ls -la public/storage
# expect → /home/USER/persistent/media
# if missing or wrong:
rm -f public/storage && php artisan storage:link

# 3) Config picks up .env (when you use config cache)
php artisan config:clear
# php artisan config:cache   # only if prod normally caches config

# 4) Spot-check two known /storage/... image URLs in the browser

# 5) Optional: touch a new upload and confirm it lands under persistent/media
```

### FTP / File Manager tip

- Sync/upload into `public_html` only
- If using “replace all files”, exclude anything that would delete `public/storage`
  without recreating it
- Never upload into `/persistent/media` as part of a code deploy

---

## Phase 4 — Hardening

### Weekly backup of durable media

At least weekly, back up `/home/USER/persistent/media` (zip download, Hostinger
backup that includes that path, or `rsync`/`tar` to another disk):

```bash
tar -czf ~/backups/media-$(date +%F).tar.gz -C /home/USER/persistent media
```

Keep several recent archives. DB dumps alone are not enough — image files are
not in MySQL.

### Safer screenshot delete-on-success (in app)

`ScreenshotCaptureService` only deletes previous screenshot files **after** a
successful new WebP save. A failed refresh keeps the prior preview on disk and
in the DB (placeholder is used only when the site had no prior capture).

---

## Success criteria

- Replacing `public_html` code does not remove catalog/site images
- New uploads still appear at `/storage/...` with unchanged DB paths
- `ls public/storage` points at `/home/USER/persistent/media`
- After a full code redeploy, old images still load
- A failed screenshot refresh does not wipe a good existing preview

## Hostinger gotchas

- Symlinks must be allowed (usually yes; if `storage:link` fails, ask support)
- Absolute `MEDIA_PATH` only
- Do **not** put persistent media inside `public_html` — still in the deploy blast radius
