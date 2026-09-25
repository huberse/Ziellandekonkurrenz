-- Segelflug-Wettbewerb: Datenbankschema (MySQL 5.7+ / MariaDB 10.3+)
-- Import z.B. via phpMyAdmin oder:  mysql -u user -p datenbank < schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(64)  NOT NULL PRIMARY KEY,
  svalue TEXT         NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version     INT          NOT NULL PRIMARY KEY,
  description VARCHAR(255) NOT NULL,
  applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competitions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160) NOT NULL,
  is_current TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME   NULL, -- NULL = offen, gesetzter Zeitpunkt = abgeschlossen
  UNIQUE KEY uq_competition_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competition_settings (
  competition_id INT         NOT NULL,
  skey           VARCHAR(64) NOT NULL,
  svalue         TEXT        NOT NULL,
  PRIMARY KEY (competition_id, skey),
  CONSTRAINT fk_competition_settings_competition FOREIGN KEY (competition_id)
    REFERENCES competitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_types (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80)  NOT NULL,
  info       VARCHAR(200) NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clubs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  short_name VARCHAR(30)  NULL,
  place      VARCHAR(120) NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_club_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pilots (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  competition_id INT         NOT NULL,
  bib_number     VARCHAR(10) NULL,
  first_name  VARCHAR(80)  NOT NULL,
  last_name   VARCHAR(80)  NOT NULL,
  club_id     INT          NULL,
  email       VARCHAR(160) NULL,
  phone       VARCHAR(40)  NULL,
  model_type_id    INT          NULL,
  model_name  VARCHAR(120) NULL,
  notes       VARCHAR(255) NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pilot_type (model_type_id),
  KEY idx_pilot_club (club_id),
  KEY idx_pilot_competition (competition_id),
  UNIQUE KEY uq_pilot_id_competition (id, competition_id),
  UNIQUE KEY uq_pilot_competition_bib (competition_id, bib_number),
  CONSTRAINT fk_pilot_type FOREIGN KEY (model_type_id)
    REFERENCES model_types(id) ON DELETE SET NULL,
  CONSTRAINT fk_pilot_club FOREIGN KEY (club_id)
    REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_pilot_competition FOREIGN KEY (competition_id)
    REFERENCES competitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounds (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  competition_id      INT          NOT NULL,
  round_number        INT          NOT NULL,
  target_time_seconds INT          NOT NULL DEFAULT 180,
  is_active           TINYINT(1)   NOT NULL DEFAULT 0,
  is_included         TINYINT(1)   NOT NULL DEFAULT 1,
  note                VARCHAR(160) NULL,
  UNIQUE KEY uq_round_number (competition_id, round_number),
  UNIQUE KEY uq_round_id_competition (id, competition_id),
  CONSTRAINT fk_round_competition FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scores (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  pilot_id            INT            NOT NULL,
  round_id            INT            NOT NULL,
  competition_id      INT            NOT NULL,
  status              ENUM('flown','dnf','dns') NOT NULL DEFAULT 'flown',
  motor               TINYINT(1)   NOT NULL DEFAULT 0, -- Motor angelassen, unabhängig vom Ausgang
  flight_time_seconds DECIMAL(7,1)   NULL,
  landing_value        DECIMAL(6,1)   NULL,
  time_penalty        DECIMAL(8,2)   NOT NULL DEFAULT 0,
  landing_penalty     DECIMAL(8,2)   NOT NULL DEFAULT 0,
  penalty             DECIMAL(8,2)   NOT NULL DEFAULT 0,
  note                VARCHAR(160)   NULL,
  updated_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pilot_round (pilot_id, round_id),
  KEY idx_score_round (round_id),
  KEY idx_score_competition (competition_id),
  KEY idx_score_pilot_competition (pilot_id, competition_id),
  KEY idx_score_round_competition (round_id, competition_id),
  CONSTRAINT fk_score_pilot_competition FOREIGN KEY (pilot_id, competition_id)
    REFERENCES pilots(id, competition_id) ON DELETE CASCADE,
  CONSTRAINT fk_score_round_competition FOREIGN KEY (round_id, competition_id)
    REFERENCES rounds(id, competition_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  competition_id INT         NULL,
  first_name     VARCHAR(80) NOT NULL,
  last_name  VARCHAR(80)  NOT NULL,
  club_id    INT          NULL,
  club       VARCHAR(120) NULL,
  email      VARCHAR(160) NULL, -- Altbestand: das Anmeldeformular speichert keine Adresse
  phone      VARCHAR(40)  NULL, -- Altbestand, wird nicht mehr erhoben
  model_type_id   INT          NULL,
  model_name VARCHAR(120) NULL,
  notes      VARCHAR(500) NULL,
  status     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  pilot_id   INT          NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME     NULL,
  KEY idx_reg_status (status),
  KEY idx_registration_competition (competition_id),
  KEY idx_registration_pilot (pilot_id),
  CONSTRAINT fk_registration_competition FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE SET NULL,
  CONSTRAINT fk_registration_pilot FOREIGN KEY (pilot_id) REFERENCES pilots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(120) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
