-- Production helper (Hostinger): add per-marketer activate permission.
-- Safe if column already exists — run once after deploy if migrate is not used.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS can_activate_sites TINYINT(1) NOT NULL DEFAULT 0
  AFTER active_role_id;
