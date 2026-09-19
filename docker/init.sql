CREATE DATABASE IF NOT EXISTS simple_api_db;
USE simple_api_db;

CREATE TABLE IF NOT EXISTS tbl_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    project_token VARCHAR(64) NOT NULL UNIQUE,
    contact_link VARCHAR(255) DEFAULT '',
    is_maintenance TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tbl_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    token_code VARCHAR(255) NOT NULL UNIQUE,
    type ENUM('static', 'dynamic') NOT NULL,
    duration INT DEFAULT 0,
    expire_date DATETIME DEFAULT NULL,
    max_devices INT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES tbl_projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tbl_device_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_id INT NOT NULL,
    device_uuid VARCHAR(255) NOT NULL,
    first_used DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (token_id) REFERENCES tbl_tokens(id) ON DELETE CASCADE
);

-- Seed sample project and keys for immediate testing
INSERT INTO tbl_projects (id, name, project_token, contact_link, is_maintenance) 
VALUES (1, 'Free Fire Mod Menu', 'demo_token_123456', 'https://zalo.me/g/wgyxdes3oqxcxdu5gbxe', 0)
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO tbl_tokens (id, project_id, token_code, type, duration, expire_date, max_devices)
VALUES (1, 1, 'VIP-STATIC-2026', 'static', 0, '2026-12-31 23:59:59', 2)
ON DUPLICATE KEY UPDATE token_code=VALUES(token_code);

INSERT INTO tbl_tokens (id, project_id, token_code, type, duration, expire_date, max_devices)
VALUES (2, 1, 'VIP-DYNAMIC-30D', 'dynamic', 30, NULL, 1)
ON DUPLICATE KEY UPDATE token_code=VALUES(token_code);
