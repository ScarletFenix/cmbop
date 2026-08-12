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

## Phase 3 — Deploy rules (every update)

**Allowed to overwrite:** app code under `public_html` (`app/`, `config/`,
`resources/`, `vendor/`, `public/assets`, etc.).

**Never overwrite / never delete:**

- `/home/USER/persistent/media/**`
- Live `.env` (unless intentional)
- The `public/storage` symlink (recreate with `storage:link` if lost)

**After each deploy:**

1. Confirm `.env` still has `MEDIA_PATH=...`
2. `php artisan storage:link` if `public/storage` is missing
3. Spot-check 2 image URLs
4. Confirm new uploads land in `persistent/media`, not a wiped folder

FTP / File Manager: sync into `public_html` only. Never upload into
`/persistent/media` as part of a code deploy.

---

## Success criteria

- Replacing `public_html` code does not remove catalog/site images
- New uploads still appear at `/storage/...` with unchanged DB paths
- `ls public/storage` points at `/home/USER/persistent/media`
- After a full code redeploy, old images still load

## Hostinger gotchas

- Symlinks must be allowed (usually yes; if `storage:link` fails, ask support)
- Absolute `MEDIA_PATH` only
- Do **not** put persistent media inside `public_html` — still in the deploy blast radius
