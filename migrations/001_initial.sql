-- ZKTeco Middleware — Initial Schema
-- Run once on first deploy: docker exec -i zk_mysql mysql -u zk_user -p zk_attendance < migrations/001_initial.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------
-- devices: one row per registered ZKTeco biometric unit
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS devices (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    serial_number   VARCHAR(50)     NOT NULL,
    name            VARCHAR(100)    NOT NULL,
    department      VARCHAR(100)    DEFAULT NULL,
    location        VARCHAR(255)    DEFAULT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    last_seen_at    DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_serial (serial_number),
    INDEX idx_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- attendance_logs: every ATTLOG record pushed by devices
-- status: 0 = check-in, 1 = check-out, 4 = overtime-in, 5 = overtime-out
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id       INT UNSIGNED    NOT NULL,
    device_sn       VARCHAR(50)     NOT NULL,
    user_id         VARCHAR(50)     NOT NULL,
    punch_time      DATETIME        NOT NULL,
    status          TINYINT         NOT NULL DEFAULT 0,
    verify_type     TINYINT         NOT NULL DEFAULT 0,
    work_code       VARCHAR(20)     NOT NULL DEFAULT '0',
    raw_line        TEXT            DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_id  (device_id),
    INDEX idx_user_id    (user_id),
    INDEX idx_punch_time (punch_time),
    INDEX idx_device_sn  (device_sn),
    CONSTRAINT fk_log_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- api_keys: one row per consuming project/application
-- The actual key is never stored — only its SHA-256 hash.
-- permissions JSON example: {"attendance":"read","devices":"read"}
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100)    NOT NULL,
    project_name    VARCHAR(100)    DEFAULT NULL,
    api_key_hash    CHAR(64)        NOT NULL,
    permissions     JSON            NOT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    last_used_at    DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_key_hash (api_key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Seed: pre-register the existing K40 Pro (HR dept)
-- Update serial_number / name / department as needed.
-- -------------------------------------------------------
INSERT IGNORE INTO devices (serial_number, name, department, location)
VALUES ('GED7241700908', 'K40 Pro — HR', 'HR', 'Main Building Entrance');
