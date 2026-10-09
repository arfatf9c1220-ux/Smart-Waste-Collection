-- =============================================================================
--  SMART WASTE COLLECTION OPTIMIZER  ::  MySQL / MariaDB schema
--  Import this file in phpMyAdmin (XAMPP) to create the database.
--      CREATE DATABASE smart_waste_optimizer;   is executed by this script too.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `smart_waste_optimizer`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `smart_waste_optimizer`;

-- Make the script safely re-runnable: drop every table with FK checks off,
-- then recreate from scratch.
SET FOREIGN_KEY_CHECKS = 0;

-- Drop in dependency order so the script can be re-run safely.
-- (Children first: waste_requests references collection_plans, so it must go first.)
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `worker_locations`;
DROP TABLE IF EXISTS `collection_plan_stops`;
DROP TABLE IF EXISTS `assignments`;
DROP TABLE IF EXISTS `request_images`;
DROP TABLE IF EXISTS `waste_requests`;
DROP TABLE IF EXISTS `collection_plans`;
DROP TABLE IF EXISTS `hotspots`;
DROP TABLE IF EXISTS `workers`;
DROP TABLE IF EXISTS `vehicles`;
DROP TABLE IF EXISTS `teams`;
DROP TABLE IF EXISTS `citizens`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- 1. users  —  single authentication table for all three roles
-- =============================================================================
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(120)  NOT NULL,
  `email`         VARCHAR(150)  NOT NULL,
  `password_hash` VARCHAR(255)  NOT NULL,          -- bcrypt via password_hash()
  `phone`         VARCHAR(20)   DEFAULT NULL,
  `role`          ENUM('citizen','admin','worker') NOT NULL DEFAULT 'citizen',
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `avatar`        VARCHAR(255)  DEFAULT NULL,
  `last_login_at` DATETIME      DEFAULT NULL,
  `password_reset_token`   VARCHAR(255) DEFAULT NULL, -- sha256 hash of a one-time setup/reset token
  `password_reset_expires` DATETIME     DEFAULT NULL, -- when the setup link stops working
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. citizens  —  extra profile data for users with role = 'citizen'
-- =============================================================================
CREATE TABLE `citizens` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `citizen_code` VARCHAR(20)  NOT NULL,
  `address`      VARCHAR(255) DEFAULT NULL,
  `city`         VARCHAR(80)  DEFAULT 'Mumbai',
  `locality`     VARCHAR(120) DEFAULT NULL,
  `latitude`     DECIMAL(10,7) DEFAULT NULL,
  `longitude`    DECIMAL(10,7) DEFAULT NULL,
  `total_reports` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_citizens_user` (`user_id`),
  UNIQUE KEY `uq_citizens_code` (`citizen_code`),
  CONSTRAINT `fk_citizens_user` FOREIGN KEY (`user_id`)
      REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. teams  —  collection teams (each team has a waste-type capability list)
-- =============================================================================
CREATE TABLE `teams` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `team_name`   VARCHAR(80)  NOT NULL,
  `team_code`   VARCHAR(20)  NOT NULL,
  `supervisor`  VARCHAR(120) DEFAULT NULL,
  `phone`       VARCHAR(20)  DEFAULT NULL,
  `depot_address` VARCHAR(255) DEFAULT NULL,
  `latitude`    DECIMAL(10,7) DEFAULT NULL,
  `longitude`   DECIMAL(10,7) DEFAULT NULL,
  `waste_types` VARCHAR(255) DEFAULT 'all',   -- CSV, e.g. "general,plastic,organic"
  `max_load`    INT UNSIGNED NOT NULL DEFAULT 12,
  `status`      ENUM('active','maintenance','inactive') NOT NULL DEFAULT 'active',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teams_code` (`team_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. vehicles
-- =============================================================================
CREATE TABLE `vehicles` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_number`  VARCHAR(30)  NOT NULL,
  `vehicle_type`    ENUM('truck','tipper','van','tempo','three_wheeler','e_waste_van') NOT NULL DEFAULT 'van',
  `capacity_kg`     INT UNSIGNED NOT NULL DEFAULT 1500,
  `team_id`         INT UNSIGNED DEFAULT NULL,
  `vehicle_status`  ENUM('in_service','maintenance','retired') NOT NULL DEFAULT 'in_service',
  `last_service_at` DATE DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicles_number` (`vehicle_number`),
  KEY `idx_vehicles_team` (`team_id`),
  CONSTRAINT `fk_vehicles_team` FOREIGN KEY (`team_id`)
      REFERENCES `teams` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. workers  —  collection staff (linked 1:1 to a users row)
-- =============================================================================
CREATE TABLE `workers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `worker_code`   VARCHAR(20)  NOT NULL,
  `team_id`       INT UNSIGNED DEFAULT NULL,
  `vehicle_id`    INT UNSIGNED DEFAULT NULL,
  `phone`         VARCHAR(20)  DEFAULT NULL,
  `latitude`      DECIMAL(10,7) DEFAULT NULL,
  `longitude`     DECIMAL(10,7) DEFAULT NULL,
  `availability`  ENUM('available','busy','offline') NOT NULL DEFAULT 'offline',
  `status`        ENUM('available','busy','offline','removed') NOT NULL DEFAULT 'offline',
  `current_load`  INT UNSIGNED NOT NULL DEFAULT 0,
  `max_load`      INT UNSIGNED NOT NULL DEFAULT 6,
  `total_collected` INT UNSIGNED NOT NULL DEFAULT 0,
  `rating`        DECIMAL(3,2) NOT NULL DEFAULT 4.50,
  `skills`        VARCHAR(255) DEFAULT NULL,
  `last_seen_at`  DATETIME DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_workers_user` (`user_id`),
  UNIQUE KEY `uq_workers_code` (`worker_code`),
  KEY `idx_workers_team` (`team_id`),
  KEY `idx_workers_status` (`status`,`availability`),
  CONSTRAINT `fk_workers_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_workers_team`    FOREIGN KEY (`team_id`)    REFERENCES `teams` (`id`)    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_workers_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. waste_requests  —  the heart of the system
-- =============================================================================
CREATE TABLE `waste_requests` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_code`        VARCHAR(24)  NOT NULL,       -- WR-2026-000123 (human readable)
  `citizen_id`          INT UNSIGNED DEFAULT NULL,
  `user_id`             INT UNSIGNED DEFAULT NULL,   -- account that submitted it
  `waste_type`          ENUM('general','plastic','organic','e_waste','construction','hazardous','other')
                        NOT NULL DEFAULT 'general',
  `quantity`            ENUM('small','medium','large','bulk') NOT NULL DEFAULT 'small',
  `description`         TEXT DEFAULT NULL,
  `address`             VARCHAR(255) NOT NULL,
  `landmark`            VARCHAR(160) DEFAULT NULL,
  `latitude`            DECIMAL(10,7) DEFAULT NULL,
  `longitude`           DECIMAL(10,7) DEFAULT NULL,
  `gps_accuracy_m`      DECIMAL(8,2) DEFAULT NULL,
  `image_path`          VARCHAR(255) DEFAULT NULL,     -- citizen photo at report time
  `status`              ENUM('pending','prioritized','assigned','scheduled','in_progress',
                             'collected','verified','cancelled') NOT NULL DEFAULT 'pending',
  `priority_score`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `priority_level`      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  `priority_factors`    JSON DEFAULT NULL,             -- full score breakdown (audit)
  `assigned_worker_id`  INT UNSIGNED DEFAULT NULL,
  `assigned_team_id`    INT UNSIGNED DEFAULT NULL,
  `vehicle_id`          INT UNSIGNED DEFAULT NULL,
  `collection_plan_id`  INT UNSIGNED DEFAULT NULL,
  `plan_stop_number`    INT UNSIGNED DEFAULT NULL,
  `estimated_visit_time` DATETIME DEFAULT NULL,
  `assigned_at`        DATETIME DEFAULT NULL,
  `scheduled_at`        DATETIME DEFAULT NULL,
  `arrived_at`          DATETIME DEFAULT NULL,
  `started_at`          DATETIME DEFAULT NULL,
  `collected_at`        DATETIME DEFAULT NULL,
  `verified_at`         DATETIME DEFAULT NULL,
  `verified_by`         INT UNSIGNED DEFAULT NULL,
  `before_image`        VARCHAR(255) DEFAULT NULL,
  `after_image`         VARCHAR(255) DEFAULT NULL,
  `collection_lat`      DECIMAL(10,7) DEFAULT NULL,
  `collection_lng`      DECIMAL(10,7) DEFAULT NULL,
  `is_rejected`         TINYINT(1) NOT NULL DEFAULT 0,
  `reject_reason`       VARCHAR(255) DEFAULT NULL,
  `citizen_rating`      TINYINT UNSIGNED DEFAULT NULL,
  `citizen_feedback`    VARCHAR(255) DEFAULT NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_req_code` (`request_code`),
  KEY `idx_req_status` (`status`),
  KEY `idx_req_priority` (`priority_score`),
  KEY `idx_req_created` (`created_at`),
  KEY `idx_req_geo` (`latitude`,`longitude`),
  KEY `idx_req_citizen` (`citizen_id`),
  KEY `idx_req_worker` (`assigned_worker_id`),
  CONSTRAINT `fk_req_citizen`  FOREIGN KEY (`citizen_id`)         REFERENCES `citizens` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_req_user`     FOREIGN KEY (`user_id`)            REFERENCES `users` (`id`)    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_req_worker`   FOREIGN KEY (`assigned_worker_id`) REFERENCES `workers` (`id`)  ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_req_team`     FOREIGN KEY (`assigned_team_id`)   REFERENCES `teams` (`id`)    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_req_vehicle`  FOREIGN KEY (`vehicle_id`)         REFERENCES `vehicles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. request_images  —  waste photo + before/after proof photos
-- =============================================================================
CREATE TABLE `request_images` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`  INT UNSIGNED NOT NULL,
  `image_type`  ENUM('waste','before','after') NOT NULL DEFAULT 'waste',
  `file_path`   VARCHAR(255) NOT NULL,
  `mime_type`   VARCHAR(80)  DEFAULT NULL,
  `file_size`   INT UNSIGNED DEFAULT NULL,
  `captured_lat` DECIMAL(10,7) DEFAULT NULL,
  `captured_lng` DECIMAL(10,7) DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_img_request` (`request_id`,`image_type`),
  CONSTRAINT `fk_img_request` FOREIGN KEY (`request_id`) REFERENCES `waste_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_img_user`    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 8. assignments  —  who is collecting what, and why
-- =============================================================================
CREATE TABLE `assignments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`  INT UNSIGNED NOT NULL,
  `worker_id`   INT UNSIGNED NOT NULL,
  `team_id`     INT UNSIGNED DEFAULT NULL,
  `vehicle_id`  INT UNSIGNED DEFAULT NULL,
  `assigned_by` INT UNSIGNED DEFAULT NULL,
  `distance_km` DECIMAL(6,2) DEFAULT NULL,
  `match_score` DECIMAL(5,2) DEFAULT NULL,   -- recommendation score that was used
  `reason`      VARCHAR(255) DEFAULT NULL,
  `status`      ENUM('assigned','accepted','on_the_way','completed','cancelled') NOT NULL DEFAULT 'assigned',
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accepted_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asg_request` (`request_id`),
  KEY `idx_asg_worker` (`worker_id`),
  CONSTRAINT `fk_asg_request` FOREIGN KEY (`request_id`) REFERENCES `waste_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asg_worker`  FOREIGN KEY (`worker_id`)  REFERENCES `workers` (`id`)         ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asg_team`    FOREIGN KEY (`team_id`)    REFERENCES `teams` (`id`)           ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_asg_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)        ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_asg_user`    FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)          ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 9. collection_plans
-- =============================================================================
CREATE TABLE `collection_plans` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_code`    VARCHAR(30)  NOT NULL,
  `team_id`      INT UNSIGNED DEFAULT NULL,
  `vehicle_id`   INT UNSIGNED DEFAULT NULL,
  `worker_id`    INT UNSIGNED DEFAULT NULL,
  `plan_date`    DATE NOT NULL,
  `status`       ENUM('draft','assigned','active','completed','cancelled') NOT NULL DEFAULT 'draft',
  `total_stops`  INT UNSIGNED NOT NULL DEFAULT 0,
  `total_distance_km` DECIMAL(7,2) NOT NULL DEFAULT 0,
  `estimated_duration_min` INT UNSIGNED NOT NULL DEFAULT 0,
  `notes`        VARCHAR(255) DEFAULT NULL,
  `started_at`   DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_code` (`plan_code`),
  KEY `idx_plan_status` (`status`),
  KEY `idx_plan_date` (`plan_date`),
  KEY `idx_plan_worker` (`worker_id`),
  CONSTRAINT `fk_plan_team`    FOREIGN KEY (`team_id`)    REFERENCES `teams` (`id`)    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_plan_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_plan_worker`  FOREIGN KEY (`worker_id`)  REFERENCES `workers` (`id`)  ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_plan_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 10. collection_plan_stops
-- =============================================================================
CREATE TABLE `collection_plan_stops` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id`       INT UNSIGNED NOT NULL,
  `request_id`    INT UNSIGNED NOT NULL,
  `stop_number`   INT UNSIGNED NOT NULL,
  `sequence_order` INT UNSIGNED NOT NULL,
  `eta`           DATETIME DEFAULT NULL,
  `distance_from_prev_km` DECIMAL(6,2) DEFAULT 0,
  `travel_minutes` INT UNSIGNED DEFAULT 0,
  `priority_score` TINYINT UNSIGNED DEFAULT 0,
  `status`        ENUM('pending','arrived','in_progress','collected','skipped') NOT NULL DEFAULT 'pending',
  `arrived_at`    DATETIME DEFAULT NULL,
  `completed_at`  DATETIME DEFAULT NULL,
  `notes`         VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stop_plan` (`plan_id`,`sequence_order`),
  KEY `idx_stop_request` (`request_id`),
  CONSTRAINT `fk_stop_plan`    FOREIGN KEY (`plan_id`)    REFERENCES `collection_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_stop_request` FOREIGN KEY (`request_id`) REFERENCES `waste_requests` (`id`)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 11. worker_locations  —  GPS breadcrumb trail (worker tracking)
-- =============================================================================
CREATE TABLE `worker_locations` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `worker_id`   INT UNSIGNED NOT NULL,
  `latitude`    DECIMAL(10,7) NOT NULL,
  `longitude`   DECIMAL(10,7) NOT NULL,
  `accuracy_m`  DECIMAL(8,2) DEFAULT NULL,
  `speed_kph`   DECIMAL(6,2) DEFAULT NULL,
  `heading`     DECIMAL(6,2) DEFAULT NULL,
  `status`      ENUM('available','busy','offline') NOT NULL DEFAULT 'available',
  `source`      ENUM('gps','manual','depot') NOT NULL DEFAULT 'gps',
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wl_worker_time` (`worker_id`,`recorded_at`),
  CONSTRAINT `fk_wl_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 12. hotspots  —  predicted problem areas (rule based)
-- =============================================================================
CREATE TABLE `hotspots` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `area_name`           VARCHAR(160) NOT NULL,
  `latitude`            DECIMAL(10,7) NOT NULL,
  `longitude`           DECIMAL(10,7) NOT NULL,
  `radius_m`            INT UNSIGNED NOT NULL DEFAULT 700,
  `report_count`        INT UNSIGNED NOT NULL DEFAULT 0,
  `open_count`          INT UNSIGNED NOT NULL DEFAULT 0,
  `avg_recurrence_days` DECIMAL(5,1) DEFAULT NULL,
  `hotspot_score`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `risk_level`          ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  `dominant_waste_type` VARCHAR(30) DEFAULT NULL,
  `trend`               ENUM('rising','stable','falling') NOT NULL DEFAULT 'stable',
  `window_days`         INT UNSIGNED NOT NULL DEFAULT 30,
  `first_report_at`     DATETIME DEFAULT NULL,
  `last_report_at`      DATETIME DEFAULT NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hotspot_risk` (`risk_level`),
  KEY `idx_hotspot_score` (`hotspot_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 13. notifications
-- =============================================================================
CREATE TABLE `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `title`      VARCHAR(160) NOT NULL,
  `message`    VARCHAR(255) DEFAULT NULL,
  `type`       ENUM('info','success','warning','danger','plan','request') NOT NULL DEFAULT 'info',
  `link`       VARCHAR(255) DEFAULT NULL,
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 14. activity_logs
-- =============================================================================
CREATE TABLE `activity_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `actor_role`  VARCHAR(20)  DEFAULT NULL,
  `action`      VARCHAR(60)  NOT NULL,
  `entity_type` VARCHAR(40)  DEFAULT NULL,
  `entity_id`   INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_created` (`created_at`),
  KEY `idx_log_entity` (`entity_type`,`entity_id`),
  KEY `idx_log_user` (`user_id`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 15. Link a request back to the plan that contains it.
--     Added at the end because collection_plans must exist first.
-- =============================================================================
ALTER TABLE `waste_requests`
  ADD CONSTRAINT `fk_req_plan` FOREIGN KEY (`collection_plan_id`)
  REFERENCES `collection_plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `waste_requests`
  ADD CONSTRAINT `fk_req_verifier` FOREIGN KEY (`verified_by`)
  REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
