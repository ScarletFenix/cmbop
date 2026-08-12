# Hostinger deploy checklist (pinned)

Use this on **every** code update. Full media background:
[`hostinger-media.md`](hostinger-media.md).

## Do not touch

- `/home/USER/persistent/media/**` (all `/storage/...` uploads)
- Live `.env` (unless you intend to change secrets / `MEDIA_PATH`)
- Do not “replace all” in a way that deletes `public/storage` without recreating it

## After upload / sync

1. `grep '^MEDIA_PATH=' .env` — must be absolute path outside `public_html`
2. `ls -la public/storage` — must symlink to that path; if not:
   `rm -f public/storage && php artisan storage:link`
3. `php artisan config:clear` (and `config:cache` if you normally cache)
4. Open 2 known image URLs (`/storage/sites/...`, `/storage/site-screenshots/...`)
5. Confirm a new upload lands under `persistent/media`, not a wiped folder

## Weekly

Back up `/home/USER/persistent/media` (zip / Hostinger backup / `tar`).
