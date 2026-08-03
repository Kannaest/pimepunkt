CREATE TABLE nutilogi_events (
    event_id VARCHAR(190) PRIMARY KEY,
    game_id INT NOT NULL UNIQUE,
    event_name VARCHAR(190) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    event_start_ms BIGINT UNSIGNED NULL,
    event_end_ms BIGINT UNSIGNED NULL,
    source_url VARCHAR(500) NOT NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_nutilogi_event_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
