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
3. `php artisan migrate --force` (or run pending SQL under `database/sql/`, e.g. `add_homepage_social_placement.sql` so catalog Site Details can show Homepage promotions + Social, and `restrict_order_items_site_id_on_delete.sql` so deleting a site cannot cascade-wipe orders)
4. `php artisan config:clear` (and `config:cache` if you normally cache)
5. Open 2 known image URLs (`/storage/sites/...`, `/storage/site-screenshots/...`)
6. Confirm a new upload lands under `persistent/media`, not a wiped folder
7. Article .docx uploads need 10 MB. In hPanel → Advanced → PHP Configuration set
   `upload_max_filesize=16M` and `post_max_size=64M`. Confirm with
   `php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("post_max_size"), "\n";'`
   from `public_html` (or `public/`). `public/.htaccess` and `public/.user.ini`
   already request 16M/64M; Hostinger LiteSpeed often ignores `php_value` until
   the same numbers are saved in hPanel. A 5 MB Word file is rejected as
   `UPLOAD_ERR_INI_SIZE` while PHP stays at the default 2M.

## Weekly

Back up `/home/USER/persistent/media` (zip / Hostinger backup / `tar`).
