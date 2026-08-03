ALTER TABLE games
    ADD COLUMN allow_gpx_export TINYINT(1) NOT NULL DEFAULT 0 AFTER public_results_enabled,
    ADD COLUMN show_traffic_restrictions TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_gpx_export,
    ADD COLUMN duration_minutes INT UNSIGNED NULL AFTER show_traffic_restrictions,
    ADD COLUMN start_window_from DATETIME NULL AFTER duration_minutes,
    ADD COLUMN start_window_to DATETIME NULL AFTER start_window_from,
    ADD COLUMN speeding_penalty INT UNSIGNED NOT NULL DEFAULT 7 AFTER start_window_to;

ALTER TABLE teams
    ADD COLUMN play_started_at DATETIME NULL AFTER excluded_from_results,
    ADD COLUMN paused_at DATETIME NULL AFTER play_started_at,
    ADD COLUMN pause_lat DECIMAL(10,7) NULL AFTER paused_at,
    ADD COLUMN pause_lng DECIMAL(10,7) NULL AFTER pause_lat,
    ADD COLUMN paused_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER pause_lng;

ALTER TABLE location_logs
    ADD COLUMN filtered_speed_kmh DECIMAL(8,2) NULL AFTER accuracy_m,
    ADD COLUMN speed_limit_kmh SMALLINT UNSIGNED NULL AFTER filtered_speed_kmh,
    ADD COLUMN ignored_reason VARCHAR(40) NULL AFTER speed_limit_kmh;

CREATE TABLE speed_zones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    source ENUM('admin', 'overpass', 'tarktee') NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    speed_limit_kmh SMALLINT UNSIGNED NOT NULL,
    geometry_type ENUM('circle', 'polyline') NOT NULL,
    center_lat DECIMAL(10,7) NULL,
    center_lng DECIMAL(10,7) NULL,
    radius_m INT UNSIGNED NULL,
    geometry_json JSON NULL,
    valid_from DATETIME NULL,
    valid_to DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_speed_zone_source (game_id, source, source_id),
    KEY idx_speed_zone_game (game_id),
    CONSTRAINT fk_speed_zone_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE speeding_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    speed_zone_id BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    max_speed_kmh DECIMAL(8,2) NOT NULL,
    limit_kmh SMALLINT UNSIGNED NOT NULL,
    penalty_points INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('pending', 'confirmed', 'dismissed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_speeding_team_status (team_id, status, ended_at),
    CONSTRAINT fk_speeding_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_speeding_zone FOREIGN KEY (speed_zone_id) REFERENCES speed_zones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
