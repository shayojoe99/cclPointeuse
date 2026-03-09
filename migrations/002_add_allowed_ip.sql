-- ZKTeco Middleware — Add allowed_ip to devices
-- Adds an optional IP allowlist per device.
-- NULL = accept from any IP (backward-compatible default).
-- A non-null value restricts the ADMS endpoint to that exact IP only.

ALTER TABLE devices
    ADD COLUMN allowed_ip VARCHAR(45) DEFAULT NULL
        COMMENT 'Optional source-IP restriction for ADMS push. NULL = allow all.'
    AFTER is_active;
