-- ============================================================================
-- KitaKits database schema (fresh install + safe re-run)
-- ============================================================================
-- Every statement is additive / idempotent:
--   * CREATE TABLE IF NOT EXISTS  -> creates only when missing (fresh install)
--   * ALTER TABLE ... ADD COLUMN IF NOT EXISTS -> adds missing columns on
--     existing tables (upgrade path) without touching existing data.
-- No DROP, TRUNCATE, DELETE or data-altering statements are present, so
-- re-running this file never resets or destroys production records.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS kitakits_db;
USE kitakits_db;

-- ---------------------------------------------------------------------------
-- users  (rental accounts: student / student-admin / school-management)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  user_id              INT AUTO_INCREMENT PRIMARY KEY,
  student_number       VARCHAR(50) UNIQUE,
  first_name           VARCHAR(100),
  last_name            VARCHAR(100),
  email                VARCHAR(150) UNIQUE,
  password             VARCHAR(255),
  status               ENUM('active','inactive') DEFAULT 'active',
  program              VARCHAR(100) DEFAULT 'BSIT',
  contact_number       VARCHAR(50) DEFAULT '09123456789',
  role                 VARCHAR(20) DEFAULT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------------------
-- equipment  (owner-posted listings; high-value = requires_school_approval=1)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equipment (
  equipment_id        INT AUTO_INCREMENT PRIMARY KEY,
  owner_id            INT NOT NULL,
  equipment_name      VARCHAR(150),
  category            VARCHAR(100),
  description         TEXT,
  rental_price        DECIMAL(10,2),
  available_quantity  INT,
  status              VARCHAR(50) DEFAULT 'available',
  equipment_image     VARCHAR(255) DEFAULT NULL,
  equipment_condition VARCHAR(50) DEFAULT NULL,
  requires_school_approval TINYINT(1) NOT NULL DEFAULT 0,
  declared_value      DECIMAL(12,2) DEFAULT NULL,
  asset_code          VARCHAR(50) DEFAULT NULL,
  accessories         TEXT DEFAULT NULL,
  is_archived         TINYINT(1) NOT NULL DEFAULT 0,
  archived_at         DATETIME DEFAULT NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(owner_id) REFERENCES users(user_id)
);

-- Workflow columns on equipment (added via ALTER so older installs upgrade too).
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS equipment_image VARCHAR(255) DEFAULT NULL;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS equipment_condition VARCHAR(50) DEFAULT NULL;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS requires_school_approval TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS declared_value DECIMAL(12,2) DEFAULT NULL;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS asset_code VARCHAR(50) DEFAULT NULL;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS accessories TEXT DEFAULT NULL;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS archived_at DATETIME DEFAULT NULL;
ALTER TABLE equipment ADD INDEX IF NOT EXISTS idx_equipment_archived (owner_id, is_archived);

-- ---------------------------------------------------------------------------
-- equipment_archive_audit (tracks when equipment is archived or restored)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equipment_archive_audit (
  audit_id     INT AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT NOT NULL,
  action       ENUM('archived', 'restored') NOT NULL,
  admin_id     INT NOT NULL,
  notes        TEXT DEFAULT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_eaa_eq (equipment_id),
  KEY idx_eaa_admin (admin_id),
  FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES users(user_id)
);

-- ---------------------------------------------------------------------------
-- reservations  (one reservation/rental ID used for the whole lifecycle)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
  reservation_id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id                     INT,
  equipment_id                INT,
  quantity                    INT,
  start_date                  DATE,
  end_date                    DATE,
  total_price                 DECIMAL(10,2),
  purpose                     TEXT,
  status                      VARCHAR(50) DEFAULT 'pending',
  returned_at                 DATETIME DEFAULT NULL,
  return_condition            VARCHAR(50) DEFAULT NULL,
  admin_notes                 TEXT DEFAULT NULL,
  created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  fulfillment_status          VARCHAR(50) NOT NULL DEFAULT 'not_released',
  restocked_at                DATETIME DEFAULT NULL,
  requires_school_verification TINYINT(1) NULL DEFAULT NULL,
  pickup_location             VARCHAR(255) DEFAULT NULL,
  pickup_schedule             DATETIME DEFAULT NULL,
  pickup_instructions         TEXT DEFAULT NULL,
  coordination_phone          VARCHAR(50) DEFAULT NULL,
  coordination_social         VARCHAR(150) DEFAULT NULL,
  coordination_notes          TEXT DEFAULT NULL,
  approved_by                 INT DEFAULT NULL,
  approved_at                 DATETIME DEFAULT NULL,
  return_inspected_by         INT DEFAULT NULL,
  return_inspected_at         DATETIME DEFAULT NULL,
  return_inspection_notes     TEXT DEFAULT NULL,
  FOREIGN KEY(user_id)     REFERENCES users(user_id),
  FOREIGN KEY(equipment_id) REFERENCES equipment(equipment_id),
  KEY user_id (user_id),
  KEY equipment_id (equipment_id),
  KEY idx_res_user_state (user_id, status, fulfillment_status),
  KEY idx_res_school_snapshot (requires_school_verification, fulfillment_status)
);

-- Workflow columns on reservations (upgrade path for pre-workflow installs).
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS fulfillment_status VARCHAR(30) NOT NULL DEFAULT 'not_released';
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS restocked_at DATETIME DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS requires_school_verification TINYINT(1) NULL DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS pickup_location VARCHAR(255) DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS pickup_schedule DATETIME DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS pickup_instructions TEXT DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS coordination_phone VARCHAR(50) DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS coordination_social VARCHAR(150) DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS coordination_notes TEXT DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS approved_by INT DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS return_inspected_by INT DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS return_inspected_at DATETIME DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS return_inspection_notes TEXT DEFAULT NULL;
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_res_user_state (user_id, status, fulfillment_status);
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_res_school_snapshot (requires_school_verification, fulfillment_status);
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_reservations_restocked_at (restocked_at);
-- Widen fulfillment_status on upgrade (older installs may have VARCHAR(30)).
ALTER TABLE reservations MODIFY COLUMN fulfillment_status VARCHAR(50) NOT NULL DEFAULT 'not_released';

-- ---------------------------------------------------------------------------
-- notifications  (in-app notices reused by all three dashboards)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  notification_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  message         TEXT,
  is_read         BOOLEAN DEFAULT FALSE,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(user_id)
);

-- ---------------------------------------------------------------------------
-- payments  (legacy payment ledger; kept for backward compatibility)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  payment_id       INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id   INT NOT NULL,
  amount           DECIMAL(10,2),
  payment_status   VARCHAR(50) DEFAULT 'pending',
  payment_date     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id)
);

-- ---------------------------------------------------------------------------
-- transactions  (payment attempts; authoritative payment record per rental)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
  transaction_id        INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id        INT NOT NULL,
  user_id               INT,
  amount                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency              CHAR(3) NOT NULL DEFAULT 'PHP',
  payment_status        VARCHAR(50) DEFAULT 'pending',
  payment_method        VARCHAR(50) DEFAULT 'cash',
  payment_reference     VARCHAR(120) DEFAULT NULL,
  payment_date          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  attempt_no            INT NOT NULL DEFAULT 0,
  submitted_by          INT DEFAULT NULL,
  submitted_at          DATETIME DEFAULT NULL,
  verified_by           INT DEFAULT NULL,
  verified_at           DATETIME DEFAULT NULL,
  rejection_reason      TEXT DEFAULT NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id) ON DELETE RESTRICT,
  FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  KEY reservation_id (reservation_id),
  KEY user_id (user_id),
  KEY idx_tx_res_status (reservation_id, payment_status, transaction_id),
  KEY idx_tx_pending_owner (payment_status, reservation_id)
);

-- Workflow columns on transactions (upgrade path for pre-workflow installs).

-- ---------------------------------------------------------------------------
-- reports  (institutional issue/dispute reports; reused for flags)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reports (
  report_id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  reservation_id    INT DEFAULT NULL,
  subject           VARCHAR(150),
  issue_type        VARCHAR(100),
  description       TEXT,
  attachment        VARCHAR(255),
  status            VARCHAR(50) DEFAULT 'pending',
  returned_at       DATETIME DEFAULT NULL,
  return_condition  VARCHAR(50) DEFAULT NULL,
  admin_notes       TEXT DEFAULT NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(user_id),
  FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id)
);

-- ============================================================================
-- WORKFLOW TABLES (rental lifecycle evidence / verification / audit)
-- All foreign keys on financial, proof and audit tables use RESTRICT so that
-- deleting a reservation/handover can never silently erase history.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- equipment_handover_confirmations  (owner-recorded physical release + receipt)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equipment_handover_confirmations (
  handover_id            INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id         INT NOT NULL,
  owner_id               INT NOT NULL,
  borrower_id            INT NOT NULL,
  released_at            DATETIME DEFAULT NULL,
  condition_at_handover  VARCHAR(100) DEFAULT NULL,
  accessories            TEXT DEFAULT NULL,
  owner_confirmed_at     DATETIME DEFAULT NULL,
  borrower_confirmed_at  DATETIME DEFAULT NULL,
  notes                  TEXT DEFAULT NULL,
  created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_handover_res (reservation_id),
  KEY fk_hnd_own (owner_id),
  KEY fk_hnd_bor (borrower_id),
  FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id) ON DELETE RESTRICT,
  FOREIGN KEY(owner_id) REFERENCES users(user_id),
  FOREIGN KEY(borrower_id) REFERENCES users(user_id)
);
-- ---------------------------------------------------------------------------
-- equipment_handover_evidence  (release proof images)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equipment_handover_evidence (
  evidence_id   INT AUTO_INCREMENT PRIMARY KEY,
  handover_id   INT NOT NULL,
  file_name     VARCHAR(255) NOT NULL,
  caption       VARCHAR(150) DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  uploaded_by   INT DEFAULT NULL,
  original_name VARCHAR(255) DEFAULT NULL,
  mime_type     VARCHAR(80) DEFAULT NULL,
  file_size     INT DEFAULT NULL,
  KEY idx_hde_hnd (handover_id),
  FOREIGN KEY(handover_id)   REFERENCES equipment_handover_confirmations(handover_id) ON DELETE RESTRICT,
  FOREIGN KEY(uploaded_by)   REFERENCES users(user_id) ON DELETE SET NULL
);

-- Workflow metadata columns on equipment_handover_evidence (upgrade path).
ALTER TABLE equipment_handover_evidence ADD COLUMN IF NOT EXISTS uploaded_by INT DEFAULT NULL;
ALTER TABLE equipment_handover_evidence ADD COLUMN IF NOT EXISTS original_name VARCHAR(255) DEFAULT NULL;
ALTER TABLE equipment_handover_evidence ADD COLUMN IF NOT EXISTS mime_type VARCHAR(80) DEFAULT NULL;
ALTER TABLE equipment_handover_evidence ADD COLUMN IF NOT EXISTS file_size INT DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- school_approval_requests  (School Management initial witness, high-value only)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_approval_requests (
  approval_id       INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id    INT NOT NULL,
  requested_by      INT NOT NULL,
  request_type      VARCHAR(30) NOT NULL DEFAULT 'approval',
  status            VARCHAR(30) NOT NULL DEFAULT 'pending',
  reviewed_by       INT DEFAULT NULL,
  requested_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at       DATETIME DEFAULT NULL,
  decision_notes    TEXT DEFAULT NULL,
  UNIQUE KEY uq_reservation_request (reservation_id, request_type),
  KEY idx_status (status),
  KEY fk_sar_req (requested_by),
  KEY fk_sar_rev (reviewed_by),
  FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id) ON DELETE RESTRICT,
  FOREIGN KEY(requested_by) REFERENCES users(user_id),
  FOREIGN KEY(reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- school_handover_verifications  (School Management handover verification)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_handover_verifications (
  verification_id      INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id       INT NOT NULL UNIQUE,
  handover_id          INT NOT NULL,
  condition_report     TEXT DEFAULT NULL,
  accessories          TEXT DEFAULT NULL,
  status               VARCHAR(30) NOT NULL DEFAULT 'pending',
  verified_by          INT DEFAULT NULL,
  verified_at          DATETIME DEFAULT NULL,
  verification_notes   TEXT DEFAULT NULL,
  disputed             TINYINT(1) NOT NULL DEFAULT 0,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_shv_verified_by (verified_by),
  FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id),
  FOREIGN KEY(handover_id) REFERENCES equipment_handover_confirmations(handover_id)
);
-- ---------------------------------------------------------------------------
-- school_return_verifications  (School Management return verification)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_return_verifications (
  verification_id       INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id        INT NOT NULL UNIQUE,
  condition_report      TEXT DEFAULT NULL,
  status                VARCHAR(30) NOT NULL DEFAULT 'pending',
  verified_by           INT DEFAULT NULL,
  verified_at           DATETIME DEFAULT NULL,
  verification_notes    TEXT DEFAULT NULL,
  accessories           TEXT DEFAULT NULL,
  inspected_by          INT DEFAULT NULL,
  inspected_at          DATETIME DEFAULT NULL,
  disputed              TINYINT(1) NOT NULL DEFAULT 0,
  KEY fk_srv_by (verified_by),
  KEY fk_srv_insp (inspected_by),
  KEY uq_verification_res (reservation_id),
  FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id),
  FOREIGN KEY(verified_by) REFERENCES users(user_id),
  FOREIGN KEY(inspected_by) REFERENCES users(user_id)
);

-- ---------------------------------------------------------------------------
-- school_return_evidence  (student-submitted return proof images)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_return_evidence (
  evidence_id   INT NOT NULL AUTO_INCREMENT,
  reservation_id INT NOT NULL,
  uploaded_by   INT DEFAULT NULL,
  file_name     VARCHAR(255) NOT NULL,
  caption       VARCHAR(150) DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  original_name VARCHAR(255) DEFAULT NULL,
  mime_type     VARCHAR(80) DEFAULT NULL,
  file_size     INT DEFAULT NULL,
  purpose       VARCHAR(30) NOT NULL DEFAULT 'return',
  PRIMARY KEY (evidence_id),
  KEY idx_ev_res (reservation_id),
  KEY fk_ere_by (uploaded_by),
  FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id) ON DELETE RESTRICT,
  FOREIGN KEY(uploaded_by) REFERENCES users(user_id)
);

-- Workflow metadata columns on school_return_evidence (upgrade path).
ALTER TABLE school_return_evidence ADD COLUMN IF NOT EXISTS original_name VARCHAR(255) DEFAULT NULL;
ALTER TABLE school_return_evidence ADD COLUMN IF NOT EXISTS mime_type VARCHAR(80) DEFAULT NULL;
ALTER TABLE school_return_evidence ADD COLUMN IF NOT EXISTS file_size INT DEFAULT NULL;
ALTER TABLE school_return_evidence ADD COLUMN IF NOT EXISTS purpose VARCHAR(30) NOT NULL DEFAULT 'return';

-- ---------------------------------------------------------------------------
-- transaction_audit_logs  (append-only audit trail for every lifecycle event)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transaction_audit_logs (
  log_id          INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id  INT DEFAULT NULL,
  actor_user_id   INT DEFAULT NULL,
  action_type     VARCHAR(40) NOT NULL,
  previous_status VARCHAR(40) DEFAULT NULL,
  new_status      VARCHAR(40) DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes           TEXT DEFAULT NULL,
  actor_role      VARCHAR(20) DEFAULT NULL,
  KEY idx_res (reservation_id),
  KEY idx_action (action_type),
  KEY fk_tal_act (actor_user_id),
  FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id) ON DELETE RESTRICT,
  FOREIGN KEY(actor_user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- school_policy_config  (school-set policy, e.g. high-value threshold)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_policy_config (
  policy_id    INT AUTO_INCREMENT PRIMARY KEY,
  policy_key   VARCHAR(50) NOT NULL,
  policy_value DECIMAL(12,2) DEFAULT NULL,
  updated_bcly   INT DEFAULT NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_policy (policy_key),
  KEY fk_spc_by (updated_by),
  FOREIGN KEY(updated_by) REFERENCES users(user_id)
);