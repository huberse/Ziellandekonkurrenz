-- Segelflug-Wettbewerb: Datenbankschema (MySQL 5.7+ / MariaDB 10.3+)
-- Import z.B. via phpMyAdmin oder:  mysql -u user -p datenbank < schema.sql
--
-- Reihenfolge: jede Tabelle steht nach den Tabellen, auf die sie verweist.
-- Bis 1.9.19 stand competitions vor clubs, obwohl competitions.club_id auf
-- clubs verweist. Das liess sich nicht importieren: MySQL und MariaDB lehnen
-- einen Fremdschluessel auf eine noch nicht vorhandene Tabelle ab
-- ("errno: 150 Foreign key constraint is incorrectly formed"). Ein frisch
-- eingerichteter Verein haette mit install.php genau daran scheitern muessen -
-- es ist nie aufgefallen, weil bestehende Installationen das Schema ueber die
-- Migrationen bekommen haben und nicht ueber diese Datei.

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

CREATE TABLE IF NOT EXISTS clubs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  short_name VARCHAR(30)  NULL,
  place      VARCHAR(120) NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_club_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_types (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80)  NOT NULL,
  info       VARCHAR(200) NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(120) NULL,
  club_id       INT          NULL, -- Vereinszugehörigkeit; NULL = keinem Verein zugeordnet
  is_superadmin TINYINT(1)   NOT NULL DEFAULT 0, -- darf die Benutzer verwalten
  active        TINYINT(1)   NOT NULL DEFAULT 1, -- 0 = Anmeldung gesperrt
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username),
  KEY idx_user_club (club_id),
  CONSTRAINT fk_user_club FOREIGN KEY (club_id)
    REFERENCES clubs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competitions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160) NOT NULL,
  club_id    INT          NULL, -- Vereinszugehörigkeit; NULL = Altbestand ohne Zuordnung
  region     TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = zählt zum Regiocup dieses Jahres
  is_current TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME   NULL, -- NULL = offen, gesetzter Zeitpunkt = abgeschlossen
    -- Seit 2.0.1: der Wettbewerb fiel aus, etwa wegen Wetter. Er ist trotzdem
    -- abgeschlossen - gesperrt wird er wie ein beendeter -, aber die
    -- Ergebnisse sind unvollstaendig. Was schon geflogen ist, zaehlt in der
    -- Regiowertung weiter, sonst waere die bis dahin geleistete Arbeit umsonst.
    --
    -- Beide Spalten gehoeren zusammen: cancelled_at ist gesetzt UND
    -- completed_at auch. Eine eigene "geschlossen"-Regel neben completed_at
    -- waere an dreissig Stellen zu pflegen, und an der ersten, die man
    -- vergisst, waere ein abgesagter Wettbewerb wieder bearbeitbar.
    cancelled_at DATETIME   NULL, -- gesetzt = fand nicht statt
  UNIQUE KEY uq_competition_name (name),
  KEY idx_competition_club (club_id),
  CONSTRAINT fk_competition_club FOREIGN KEY (club_id)
    REFERENCES clubs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competition_settings (
  competition_id INT         NOT NULL,
  skey           VARCHAR(64) NOT NULL,
  svalue         TEXT        NOT NULL,
  PRIMARY KEY (competition_id, skey),
  CONSTRAINT fk_competition_settings_competition FOREIGN KEY (competition_id)
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

-- Die Stammdaten eines Piloten: SMV-Nummer und Name. Genau ein Satz je Person,
-- ueber alle Wettbewerbe hinweg. Ohne SMV-Nummer bleibt smv_number NULL --
-- MySQL laesst in einem eindeutigen Index mehrere NULL zu, "999999" als
-- gespeicherten Wert dagegen nicht: zwei Piloten ohne Nummer waeren sonst
-- derselbe. Angezeigt wird "999999" trotzdem, siehe pilot_smv_anzeige().
--
-- Die Nummer ist Text und keine Zahl, weil sie mit Null beginnen darf.
CREATE TABLE IF NOT EXISTS pilot_profiles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    smv_number  VARCHAR(6)   NULL,
    first_name  VARCHAR(80)  NOT NULL,
    last_name   VARCHAR(80)  NOT NULL,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_profile_smv (smv_number),
    KEY idx_profile_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Der Eintrag eines Piloten in der Startliste eines Wettbewerbs: Startnummer,
-- Verein, Modell, Modelltyp. Alles, was je Wettbewerb verschieden sein kann.
--
-- Name und SMV-Nummer stehen nicht hier, sondern in pilot_profiles. Wer in
-- drei Jahren dreimal fliegt, hat drei Eintraege und einen Stammsatz. Der Verein
-- steht bewusst hier und nicht am Stamm: wer den Verein wechselt, behaelt die
-- Historie beim alten.
CREATE TABLE IF NOT EXISTS pilots (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    competition_id INT         NOT NULL,
    profile_id  INT          NOT NULL,
    bib_number     VARCHAR(10) NULL,
    club_id     INT          NULL,
    model_type_id    INT          NULL,
    model_name  VARCHAR(120) NULL,
    notes       VARCHAR(255) NULL,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pilot_type (model_type_id),
    KEY idx_pilot_club (club_id),
    KEY idx_pilot_competition (competition_id),
    KEY idx_pilot_profile (profile_id),
    UNIQUE KEY uq_pilot_id_competition (id, competition_id),
    UNIQUE KEY uq_pilot_competition_bib (competition_id, bib_number),
    CONSTRAINT fk_pilot_type FOREIGN KEY (model_type_id)
      REFERENCES model_types(id) ON DELETE SET NULL,
    CONSTRAINT fk_pilot_club FOREIGN KEY (club_id)
      REFERENCES clubs(id) ON DELETE SET NULL,
    CONSTRAINT fk_pilot_profile FOREIGN KEY (profile_id)
      REFERENCES pilot_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pilot_competition FOREIGN KEY (competition_id)
      REFERENCES competitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scores (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  pilot_id            INT            NOT NULL,
  round_id            INT            NOT NULL,
  competition_id      INT            NOT NULL,
  not_started        TINYINT(1)   NOT NULL DEFAULT 0, -- nicht angetreten, schliesst die anderen aus
  outlanding         TINYINT(1)   NOT NULL DEFAULT 0, -- Aussenlandung, mit der Bruchlandung kombinierbar
  crash              TINYINT(1)   NOT NULL DEFAULT 0, -- Bruchlandung, mit der Aussenlandung kombinierbar
  motor              TINYINT(1)   NOT NULL DEFAULT 0, -- Motor angelassen, unabhängig vom Ausgang
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
    -- Die SMV-Nummer, wie sie im Formular stand. Beim Freigeben wandert sie an
    -- den Stammdatensatz, hier steht sie nur als Protokoll des Eingangs. Wer
    -- sich ohne Nummer meldet, hat hier NULL.
    smv_number VARCHAR(6)   NULL,
  first_name     VARCHAR(80) NOT NULL,
  last_name  VARCHAR(80)  NOT NULL,
  club_id    INT          NULL,
  club       VARCHAR(120) NULL,
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