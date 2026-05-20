-- 001_add_batch_columns.sql
-- Adds fan-out batch support to email_log. Each row remains one recipient;
-- a multi-recipient request now produces N rows sharing the same batch_id.
--
-- All columns are nullable. Existing single-recipient rows leave them NULL.
-- Apply with:
--   mysql -u CPANELUSER_mailuser -p CPANELUSER_maildb < migrations/001_add_batch_columns.sql

ALTER TABLE email_log
    ADD COLUMN batch_id    VARCHAR(36) NULL AFTER id,
    ADD COLUMN batch_size  INT         NULL AFTER batch_id,
    ADD COLUMN batch_index INT         NULL AFTER batch_size,
    ADD INDEX  idx_batch_id (batch_id);
