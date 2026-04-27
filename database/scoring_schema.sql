-- Optional schema extension for the scorer-run competition scoring section.
-- These tables are intentionally separate from rankings_competitions and
-- rankings_competition_results so scored club/open events never influence the
-- Dutch ranking unless their results are manually uploaded through the existing
-- admin CSV workflow.

CREATE TABLE IF NOT EXISTS rankings_scorers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(160) DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rankings_scorers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rankings_scorer_login_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scorer_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rankings_scorer_login_tokens_hash (token_hash),
  KEY idx_rankings_scorer_login_tokens_scorer (scorer_id),
  CONSTRAINT fk_rankings_scorer_login_tokens_scorer
    FOREIGN KEY (scorer_id) REFERENCES rankings_scorers(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rankings_scoring_competitions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scorer_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  class VARCHAR(40) NOT NULL DEFAULT 'Klasse 1',
  scope VARCHAR(40) NOT NULL DEFAULT 'open',
  location VARCHAR(190) DEFAULT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  waypoints_original_name VARCHAR(255) DEFAULT NULL,
  waypoints_path VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rankings_scoring_competitions_scorer (scorer_id),
  KEY idx_rankings_scoring_competitions_public (is_public, status),
  CONSTRAINT fk_rankings_scoring_competitions_scorer
    FOREIGN KEY (scorer_id) REFERENCES rankings_scorers(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rankings_scoring_competition_scorers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  competition_id INT UNSIGNED NOT NULL,
  scorer_id INT UNSIGNED NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'buddy',
  invited_by_scorer_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rankings_scoring_competition_scorers (competition_id, scorer_id),
  KEY idx_rankings_scoring_competition_scorers_scorer (scorer_id),
  KEY idx_rankings_scoring_competition_scorers_inviter (invited_by_scorer_id),
  CONSTRAINT fk_rankings_scoring_competition_scorers_competition
    FOREIGN KEY (competition_id) REFERENCES rankings_scoring_competitions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_rankings_scoring_competition_scorers_scorer
    FOREIGN KEY (scorer_id) REFERENCES rankings_scorers(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_rankings_scoring_competition_scorers_inviter
    FOREIGN KEY (invited_by_scorer_id) REFERENCES rankings_scorers(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rankings_scoring_waypoints (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  competition_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(40) DEFAULT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  elevation_m DECIMAL(8,1) DEFAULT NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'file',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rankings_scoring_waypoints_competition (competition_id),
  CONSTRAINT fk_rankings_scoring_waypoints_competition
    FOREIGN KEY (competition_id) REFERENCES rankings_scoring_competitions(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rankings_scoring_tasks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  competition_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  task_date DATE NOT NULL,
  window_open_at DATETIME NOT NULL,
  window_close_at DATETIME NOT NULL,
  task_type VARCHAR(30) NOT NULL DEFAULT 'race',
  formula_version VARCHAR(40) NOT NULL DEFAULT 'GAP2025',
  minimum_distance_km DECIMAL(8,3) NOT NULL DEFAULT 5.000,
  nominal_distance_km DECIMAL(8,3) NOT NULL DEFAULT 50.000,
  nominal_time_minutes INT UNSIGNED NOT NULL DEFAULT 90,
  use_distance_points TINYINT(1) NOT NULL DEFAULT 1,
  use_time_points TINYINT(1) NOT NULL DEFAULT 1,
  use_departure_points TINYINT(1) NOT NULL DEFAULT 0,
  use_leading_points TINYINT(1) NOT NULL DEFAULT 1,
  use_arrival_position_points TINYINT(1) NOT NULL DEFAULT 0,
  use_arrival_time_points TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  task_distance_km DECIMAL(8,3) DEFAULT NULL,
  scoring_summary_json MEDIUMTEXT DEFAULT NULL,
  scored_at DATETIME DEFAULT NULL,
  published_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rankings_scoring_tasks_competition (competition_id),
  KEY idx_rankings_scoring_tasks_public (status, published_at),
  CONSTRAINT fk_rankings_scoring_tasks_competition
    FOREIGN KEY (competition_id) REFERENCES rankings_scoring_competitions(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rankings_scoring_task_start_gates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id INT UNSIGNED NOT NULL,
  gate_time_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rankings_scoring_task_start_gates_task (task_id),
  CONSTRAINT fk_rankings_scoring_task_start_gates_task
    FOREIGN KEY (task_id) REFERENCES rankings_scoring_tasks(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rankings_scoring_task_turnpoints (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id INT UNSIGNED NOT NULL,
  waypoint_id INT UNSIGNED NOT NULL,
  sequence_no INT UNSIGNED NOT NULL,
  radius_m INT UNSIGNED NOT NULL DEFAULT 400,
  is_speed_section_start TINYINT(1) NOT NULL DEFAULT 0,
  is_speed_section_end TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rankings_scoring_task_turnpoints_sequence (task_id, sequence_no),
  KEY idx_rankings_scoring_task_turnpoints_waypoint (waypoint_id),
  CONSTRAINT fk_rankings_scoring_task_turnpoints_task
    FOREIGN KEY (task_id) REFERENCES rankings_scoring_tasks(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_rankings_scoring_task_turnpoints_waypoint
    FOREIGN KEY (waypoint_id) REFERENCES rankings_scoring_waypoints(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rankings_scoring_tracklogs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pilot_name VARCHAR(160) NOT NULL,
  pilot_email VARCHAR(190) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  first_fix_at DATETIME NOT NULL,
  last_fix_at DATETIME NOT NULL,
  min_lat DECIMAL(10,7) NOT NULL,
  max_lat DECIMAL(10,7) NOT NULL,
  min_lon DECIMAL(10,7) NOT NULL,
  max_lon DECIMAL(10,7) NOT NULL,
  fix_count INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rankings_scoring_tracklogs_hash_email (file_hash, pilot_email),
  KEY idx_rankings_scoring_tracklogs_time (first_fix_at, last_fix_at),
  KEY idx_rankings_scoring_tracklogs_email (pilot_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rankings_scoring_task_flights (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id INT UNSIGNED NOT NULL,
  tracklog_id INT UNSIGNED NOT NULL,
  pilot_name VARCHAR(160) NOT NULL,
  pilot_email VARCHAR(190) NOT NULL,
  is_excluded TINYINT(1) NOT NULL DEFAULT 0,
  exclude_reason VARCHAR(255) DEFAULT NULL,
  distance_km DECIMAL(9,3) DEFAULT NULL,
  start_time_at DATETIME DEFAULT NULL,
  ess_time_at DATETIME DEFAULT NULL,
  goal_time_at DATETIME DEFAULT NULL,
  time_seconds INT UNSIGNED DEFAULT NULL,
  reached_ess TINYINT(1) NOT NULL DEFAULT 0,
  reached_goal TINYINT(1) NOT NULL DEFAULT 0,
  distance_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
  time_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
  departure_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
  leading_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
  arrival_position_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
  arrival_time_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
  total_points DECIMAL(8,1) NOT NULL DEFAULT 0.0,
  rank_no INT UNSIGNED DEFAULT NULL,
  evaluation_json MEDIUMTEXT DEFAULT NULL,
  scored_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rankings_scoring_task_flights_track (task_id, tracklog_id),
  KEY idx_rankings_scoring_task_flights_task (task_id),
  KEY idx_rankings_scoring_task_flights_tracklog (tracklog_id),
  CONSTRAINT fk_rankings_scoring_task_flights_task
    FOREIGN KEY (task_id) REFERENCES rankings_scoring_tasks(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_rankings_scoring_task_flights_tracklog
    FOREIGN KEY (tracklog_id) REFERENCES rankings_scoring_tracklogs(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
