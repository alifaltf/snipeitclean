-- ERS: read-only audit of asset tag / serial / unique custom field
-- duplicates, to run BEFORE the ERS uniqueness keys are created by
-- migration 2026_10_08_000100 (see docs/ERS_ASSET_UNIQUENESS.md). Every
-- statement is a SELECT; nothing is changed. Run against a copy or a
-- read-only user first. The migration and "php artisan ers:asset-uniqueness"
-- run the same audit themselves and report counts only; this file lists the
-- affected asset ids so that a person can resolve each group.
--
-- Grouping uses the columns' own collation (utf8mb4_unicode_ci by default),
-- so "ABC", "abc", "Àbc" and "abc " fall into the same group, exactly as
-- MariaDB compares them and as a UNIQUE index would.
--
-- Usage: mariadb --default-character-set=utf8mb4 <database> < ers-asset-uniqueness-audit.sql

SET NAMES utf8mb4;

-- 0. Column definitions actually in force.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME IN ('asset_tag', 'serial');

-- 1. Live asset tags used more than once (would block a live-only unique index).
SELECT MIN(asset_tag) AS asset_tag, COUNT(*) AS live_rows, GROUP_CONCAT(id ORDER BY id) AS asset_ids
FROM assets
WHERE deleted_at IS NULL AND asset_tag IS NOT NULL
GROUP BY asset_tag
HAVING COUNT(*) > 1;

-- 2. Asset tags used more than once across live AND deleted assets (would
--    block a strict "never reuse a tag" index; informational for the ERS rule).
SELECT MIN(asset_tag) AS asset_tag, COUNT(*) AS all_rows, SUM(deleted_at IS NULL) AS live_rows, GROUP_CONCAT(id ORDER BY id) AS asset_ids
FROM assets
WHERE asset_tag IS NOT NULL
GROUP BY asset_tag
HAVING COUNT(*) > 1;

-- 3. Live assets without a usable tag (NULL, empty or spaces only).
SELECT SUM(asset_tag IS NULL) AS null_tags, SUM(asset_tag IS NOT NULL AND asset_tag = '') AS blank_tags
FROM assets
WHERE deleted_at IS NULL;

-- 4. Live non-blank serial numbers used more than once (would block a
--    live-only serial index; Snipe-IT allows these unless "unique serial"
--    is switched on in settings).
SELECT MIN(serial) AS serial, COUNT(*) AS live_rows, GROUP_CONCAT(id ORDER BY id) AS asset_ids
FROM assets
WHERE deleted_at IS NULL AND serial IS NOT NULL AND TRIM(serial) <> ''
GROUP BY serial
HAVING COUNT(*) > 1;

-- 5. Serial number shapes among live assets (NULL vs blank vs set).
SELECT SUM(serial IS NULL) AS null_serials, SUM(serial IS NOT NULL AND serial = '') AS blank_serials,
       SUM(serial IS NOT NULL AND serial <> '') AS set_serials
FROM assets
WHERE deleted_at IS NULL;

-- 6. Whether Snipe-IT currently enforces unique serials.
SELECT unique_serial FROM settings ORDER BY id LIMIT 1;

-- 7. Custom fields marked unique (their columns are TEXT on assets; see the
--    design document). For each listed db_column that is NOT encrypted (an
--    encrypted field gets no key), run query 8 with the column name
--    substituted.
SELECT id, name, db_column, field_encrypted
FROM custom_fields
WHERE is_unique = 1;

-- 8. Template (edit <db_column> before running; not executed as is):
-- SELECT MIN(<db_column>) AS value, COUNT(*) AS live_rows, GROUP_CONCAT(id ORDER BY id) AS asset_ids
-- FROM assets
-- WHERE deleted_at IS NULL AND <db_column> IS NOT NULL AND TRIM(<db_column>) <> ''
-- GROUP BY <db_column>
-- HAVING COUNT(*) > 1;
