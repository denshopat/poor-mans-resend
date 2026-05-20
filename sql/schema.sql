-- Email relay log table.
-- Run once per deployment: mysql -u USER -p DBNAME < schema.sql

CREATE TABLE IF NOT EXISTS email_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Fan-out batch fields. NULL for single-recipient sends; populated for
    -- multi-recipient requests so all N rows share one batch_id with their
    -- position (0..batch_size-1) recorded in batch_index. See send.php.
    batch_id        VARCHAR(36)     NULL,
    batch_size      INT             NULL,
    batch_index     INT             NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    sent_at         DATETIME        NULL,
    source_app      VARCHAR(64)     NOT NULL,
    template        VARCHAR(64)     NOT NULL,
    from_email      VARCHAR(255)    NOT NULL,
    to_email        VARCHAR(255)    NOT NULL,
    reply_to_email  VARCHAR(255)    NULL,
    subject         VARCHAR(512)    NOT NULL,
    status          ENUM('queued','sending','sent','failed')
                                    NOT NULL DEFAULT 'queued',
    error_message   TEXT            NULL,
    smtp_response   TEXT            NULL,
    request_json    MEDIUMTEXT      NULL,
    message_id      VARCHAR(255)    NULL,
    remote_ip       VARCHAR(45)     NULL,
    PRIMARY KEY (id),
    KEY idx_created_at (created_at),
    KEY idx_source_app (source_app),
    KEY idx_status     (status),
    KEY idx_to_email   (to_email),
    KEY idx_batch_id   (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
