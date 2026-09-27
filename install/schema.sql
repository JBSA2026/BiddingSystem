-- =====================================================================
-- Cityland Online Property Bidding System — Database Schema
-- Compatible with MySQL 5.7+ / 8.x and MariaDB 10.3+
-- Character set: utf8mb4
--
-- Import via phpMyAdmin (cPanel) or:  mysql -u USER -p DBNAME < schema.sql
-- Then (optional, recommended) import install/triggers.sql to enforce
-- database-level immutability of bids and audit logs.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- System settings (key/value)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  skey        VARCHAR(100) NOT NULL PRIMARY KEY,
  svalue      TEXT NULL,
  updated_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Administrators (Cityland personnel)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(150) NOT NULL,
  email             VARCHAR(190) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  role              ENUM('super_admin','bidding_admin','approving_officer','auditor') NOT NULL DEFAULT 'auditor',
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  totp_secret       VARCHAR(64) NULL,
  totp_enabled      TINYINT(1) NOT NULL DEFAULT 0,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at     DATETIME NULL,
  last_login_ip     VARCHAR(45) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NULL,
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Bidders (registered interested parties)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bidder_no           VARCHAR(30) NOT NULL,
  full_name           VARCHAR(150) NOT NULL,
  company_name        VARCHAR(190) NULL,
  address_line        VARCHAR(255) NOT NULL,
  barangay            VARCHAR(120) NULL,
  city                VARCHAR(120) NOT NULL,
  province            VARCHAR(120) NOT NULL,
  postal_code         VARCHAR(10) NULL,
  email               VARCHAR(190) NOT NULL,
  mobile              VARCHAR(20) NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  id_type             VARCHAR(80) NULL,
  id_number_hash      CHAR(64) NULL,
  id_number_last4     VARCHAR(4) NULL,
  email_verified_at   DATETIME NULL,
  mobile_verified_at  DATETIME NULL,
  verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  verification_remarks VARCHAR(500) NULL,
  verified_by         INT UNSIGNED NULL,
  verified_at         DATETIME NULL,
  is_flagged          TINYINT(1) NOT NULL DEFAULT 0,
  is_blacklisted      TINYINT(1) NOT NULL DEFAULT 0,
  blacklist_reason    VARCHAR(500) NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  registration_ip     VARCHAR(45) NULL,
  device_hash         CHAR(64) NULL,
  last_login_at       DATETIME NULL,
  last_login_ip       VARCHAR(45) NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NULL,
  UNIQUE KEY uq_user_email (email),
  UNIQUE KEY uq_user_mobile (mobile),
  UNIQUE KEY uq_bidder_no (bidder_no),
  KEY idx_user_idhash (id_number_hash),
  KEY idx_user_status (verification_status),
  KEY idx_user_device (device_hash),
  KEY idx_user_regip (registration_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email/mobile OTPs and verification links (only hashes stored)
CREATE TABLE IF NOT EXISTS verification_codes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  channel     ENUM('email','mobile') NOT NULL,
  code_hash   CHAR(64) NOT NULL,
  token_hash  CHAR(64) NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_vc_user (user_id, channel),
  KEY idx_vc_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_type   ENUM('bidder','admin') NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY uq_pr_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic throttle / rate-limit events (login, OTP, registration...)
CREATE TABLE IF NOT EXISTS throttle_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action      VARCHAR(40) NOT NULL,
  tkey        VARCHAR(190) NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_throttle (action, tkey, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Property catalog
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS property_types (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ptype (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS properties (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref_no                VARCHAR(40) NOT NULL,
  name                  VARCHAR(190) NOT NULL,
  property_type_id      INT UNSIGNED NOT NULL,
  location              VARCHAR(255) NOT NULL,
  city                  VARCHAR(120) NULL,
  description           TEXT NULL,
  floor_area            DECIMAL(12,2) NULL,
  specifications        TEXT NULL,
  starting_price        DECIMAL(15,2) NOT NULL,
  min_increment         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  opening_at            DATETIME NOT NULL,
  closing_at            DATETIME NOT NULL,
  original_closing_at   DATETIME NOT NULL,
  status                ENUM('upcoming','open','closed','under_evaluation','awarded','cancelled') NOT NULL DEFAULT 'upcoming',
  is_published          TINYINT(1) NOT NULL DEFAULT 0,
  is_archived           TINYINT(1) NOT NULL DEFAULT 0,
  contact_info          TEXT NULL,
  terms                 MEDIUMTEXT NULL,
  terms_version         INT UNSIGNED NOT NULL DEFAULT 1,
  -- Bid rules
  bid_mode              ENUM('single','multiple') NOT NULL DEFAULT 'multiple',
  higher_only           TINYINT(1) NOT NULL DEFAULT 1,
  allow_withdrawal      TINYINT(1) NOT NULL DEFAULT 0,
  show_ranking          TINYINT(1) NOT NULL DEFAULT 0,
  show_highest          TINYINT(1) NOT NULL DEFAULT 0,
  show_bidder_count     TINYINT(1) NOT NULL DEFAULT 0,
  gps_mode              ENUM('optional','required') NOT NULL DEFAULT 'optional',
  require_approved_account TINYINT(1) NOT NULL DEFAULT 1,
  require_prequalification TINYINT(1) NOT NULL DEFAULT 0,
  -- Anti-sniping
  antisnipe_enabled     TINYINT(1) NOT NULL DEFAULT 0,
  antisnipe_trigger_min INT UNSIGNED NOT NULL DEFAULT 5,
  antisnipe_extend_min  INT UNSIGNED NOT NULL DEFAULT 5,
  antisnipe_max_ext     INT UNSIGNED NOT NULL DEFAULT 3,
  extensions_used       INT UNSIGNED NOT NULL DEFAULT 0,
  -- Bid security / reservation deposit (future-ready)
  deposit_required      TINYINT(1) NOT NULL DEFAULT 0,
  deposit_amount        DECIMAL(15,2) NULL,
  deposit_refundable    TINYINT(1) NOT NULL DEFAULT 1,
  -- Lifecycle
  closed_at             DATETIME NULL,
  cancelled_reason      VARCHAR(500) NULL,
  reminder_sent_at      DATETIME NULL,
  created_by            INT UNSIGNED NULL,
  updated_by            INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL,
  updated_at            DATETIME NULL,
  UNIQUE KEY uq_prop_ref (ref_no),
  KEY idx_prop_status (status, is_published, is_archived),
  KEY idx_prop_type (property_type_id),
  KEY idx_prop_closing (closing_at),
  KEY idx_prop_opening (opening_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_images (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id   INT UNSIGNED NOT NULL,
  file_path     VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  caption       VARCHAR(255) NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL,
  KEY idx_pi_prop (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_documents (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id   INT UNSIGNED NOT NULL,
  title         VARCHAR(190) NOT NULL,
  file_path     VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime          VARCHAR(100) NULL,
  file_size     INT UNSIGNED NULL,
  is_public     TINYINT(1) NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL,
  KEY idx_pd_prop (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Configurable bidder requirements / qualification documents
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS requirement_types (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  description   VARCHAR(500) NULL,
  scope         ENUM('registration','property') NOT NULL DEFAULT 'registration',
  is_required   TINYINT(1) NOT NULL DEFAULT 1,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_requirements (
  property_id         INT UNSIGNED NOT NULL,
  requirement_type_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (property_id, requirement_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bidder_documents (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NOT NULL,
  requirement_type_id INT UNSIGNED NOT NULL,
  file_path           VARCHAR(255) NOT NULL,
  original_name       VARCHAR(255) NULL,
  mime                VARCHAR(100) NULL,
  file_size           INT UNSIGNED NULL,
  status              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  remarks             VARCHAR(500) NULL,
  reviewed_by         INT UNSIGNED NULL,
  reviewed_at         DATETIME NULL,
  created_at          DATETIME NOT NULL,
  KEY idx_bd_user (user_id, requirement_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Participation, bids, rankings, awards
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS property_bidders (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id     INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  access_status   ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required',
  eval_status     ENUM('under_review','qualified','disqualified','winning','backup','not_awarded') NOT NULL DEFAULT 'under_review',
  eval_remarks    VARCHAR(1000) NULL,
  evaluated_by    INT UNSIGNED NULL,
  evaluated_at    DATETIME NULL,
  joined_at       DATETIME NOT NULL,
  UNIQUE KEY uq_pb (property_id, user_id),
  KEY idx_pb_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- APPEND-ONLY. Revisions and withdrawals are new rows; nothing is overwritten.
CREATE TABLE IF NOT EXISTS bids (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bid_ref             VARCHAR(40) NOT NULL,
  property_id         INT UNSIGNED NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  bid_type            ENUM('initial','revision','withdrawal') NOT NULL DEFAULT 'initial',
  amount              DECIMAL(15,2) NOT NULL,
  previous_bid_id     BIGINT UNSIGNED NULL,
  submitted_at        DATETIME(6) NOT NULL,
  ip_address          VARCHAR(45) NULL,
  user_agent          VARCHAR(500) NULL,
  device_info         VARCHAR(500) NULL,
  gps_status          ENUM('granted','denied','unavailable','not_requested') NOT NULL DEFAULT 'not_requested',
  gps_lat             DECIMAL(10,7) NULL,
  gps_lng             DECIMAL(10,7) NULL,
  gps_accuracy        DECIMAL(10,2) NULL,
  gps_captured_at     DATETIME NULL,
  gps_note            VARCHAR(255) NULL,
  terms_document_id   INT UNSIGNED NULL,
  terms_version       VARCHAR(20) NULL,
  property_terms_version INT UNSIGNED NULL,
  privacy_version     VARCHAR(20) NULL,
  triggered_extension TINYINT(1) NOT NULL DEFAULT 0,
  prev_hash           CHAR(64) NULL,
  hash                CHAR(64) NOT NULL,
  UNIQUE KEY uq_bid_ref (bid_ref),
  KEY idx_bid_prop (property_id, user_id, id),
  KEY idx_bid_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rankings frozen at the official close of bidding
CREATE TABLE IF NOT EXISTS ranking_snapshots (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id  INT UNSIGNED NOT NULL,
  rank_no      INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  bid_id       BIGINT UNSIGNED NOT NULL,
  amount       DECIMAL(15,2) NOT NULL,
  bid_time     DATETIME(6) NOT NULL,
  locked_at    DATETIME NOT NULL,
  KEY idx_rs_prop (property_id, rank_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS awards (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id         INT UNSIGNED NOT NULL,
  winning_user_id     INT UNSIGNED NOT NULL,
  winning_bid_id      BIGINT UNSIGNED NOT NULL,
  backup_user_ids     TEXT NULL,
  status              ENUM('pending_approval','approved','rejected','cancelled') NOT NULL DEFAULT 'pending_approval',
  recommended_by      INT UNSIGNED NOT NULL,
  recommended_at      DATETIME NOT NULL,
  recommend_remarks   TEXT NULL,
  approved_by         INT UNSIGNED NULL,
  approved_at         DATETIME NULL,
  approval_reference  VARCHAR(100) NULL,
  approval_remarks    TEXT NULL,
  supporting_doc_path VARCHAR(255) NULL,
  supporting_doc_name VARCHAR(255) NULL,
  KEY idx_award_prop (property_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dual authorization queue for critical actions (e.g. schedule changes)
CREATE TABLE IF NOT EXISTS approval_requests (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action_type     VARCHAR(50) NOT NULL,
  property_id     INT UNSIGNED NULL,
  payload         TEXT NOT NULL,
  reason          VARCHAR(1000) NOT NULL,
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_by    INT UNSIGNED NOT NULL,
  requested_at    DATETIME NOT NULL,
  decided_by      INT UNSIGNED NULL,
  decided_at      DATETIME NULL,
  decision_remarks VARCHAR(1000) NULL,
  KEY idx_ar_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Legal documents (versioned) and consent records
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS legal_documents (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_type    ENUM('terms','privacy','gps_consent') NOT NULL,
  version     VARCHAR(20) NOT NULL,
  title       VARCHAR(190) NOT NULL,
  content     MEDIUMTEXT NOT NULL,
  is_current  TINYINT(1) NOT NULL DEFAULT 0,
  published_by INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY uq_legal (doc_type, version),
  KEY idx_legal_current (doc_type, is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consents (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  consent_type      ENUM('terms','privacy','data_processing','gps','property_terms') NOT NULL,
  legal_document_id INT UNSIGNED NULL,
  version           VARCHAR(20) NOT NULL,
  context           VARCHAR(100) NULL,
  granted           TINYINT(1) NOT NULL DEFAULT 1,
  ip_address        VARCHAR(45) NULL,
  user_agent        VARCHAR(500) NULL,
  accepted_at       DATETIME NOT NULL,
  KEY idx_consent_user (user_id, consent_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Bid security / reservation deposit records (gateway-ready; manual by default)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  property_id     INT UNSIGNED NOT NULL,
  purpose         ENUM('bid_security','reservation') NOT NULL DEFAULT 'bid_security',
  amount          DECIMAL(15,2) NOT NULL,
  method          ENUM('bank_transfer','online_banking','gcash','maya','qrph','cash','other') NOT NULL,
  reference_no    VARCHAR(100) NOT NULL,
  proof_path      VARCHAR(255) NULL,
  proof_name      VARCHAR(255) NULL,
  gateway         VARCHAR(40) NULL,
  gateway_txn_id  VARCHAR(100) NULL,
  status          ENUM('pending','verified','rejected','refunded','forfeited') NOT NULL DEFAULT 'pending',
  remarks         VARCHAR(500) NULL,
  reconciled_by   INT UNSIGNED NULL,
  reconciled_at   DATETIME NULL,
  created_at      DATETIME NOT NULL,
  KEY idx_pay_user (user_id, property_id),
  KEY idx_pay_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Notifications, email queue, notes, flags
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient_type  ENUM('bidder','admin') NOT NULL,
  recipient_id    INT UNSIGNED NOT NULL,
  title           VARCHAR(190) NOT NULL,
  body            TEXT NULL,
  link            VARCHAR(255) NULL,
  is_read         TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  KEY idx_notif (recipient_type, recipient_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_queue (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email    VARCHAR(190) NOT NULL,
  to_name     VARCHAR(150) NULL,
  subject     VARCHAR(255) NOT NULL,
  body_html   MEDIUMTEXT NOT NULL,
  body_text   MEDIUMTEXT NULL,
  template    VARCHAR(60) NULL,
  status      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL,
  sent_at     DATETIME NULL,
  KEY idx_eq_status (status, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Internal notes are append-only
CREATE TABLE IF NOT EXISTS admin_notes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('bidder','property','bid') NOT NULL,
  entity_id   BIGINT UNSIGNED NOT NULL,
  admin_id    INT UNSIGNED NOT NULL,
  note        TEXT NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_note_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bidder_flags (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  flag_type   ENUM('duplicate','suspicious','watchlist','blacklist','manual') NOT NULL,
  details     VARCHAR(1000) NOT NULL,
  source      ENUM('system','admin') NOT NULL DEFAULT 'system',
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL,
  resolved_by INT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  resolution  VARCHAR(500) NULL,
  KEY idx_flag_user (user_id),
  KEY idx_flag_open (resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permanent audit trail (append-only, hash-chained)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_type  ENUM('admin','bidder','system','guest') NOT NULL,
  actor_id    INT UNSIGNED NULL,
  actor_name  VARCHAR(190) NULL,
  action      VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id   BIGINT UNSIGNED NULL,
  old_value   MEDIUMTEXT NULL,
  new_value   MEDIUMTEXT NULL,
  ip_address  VARCHAR(45) NULL,
  user_agent  VARCHAR(500) NULL,
  created_at  DATETIME(6) NOT NULL,
  prev_hash   CHAR(64) NULL,
  hash        CHAR(64) NOT NULL,
  KEY idx_audit_action (action),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_actor (actor_type, actor_id),
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Seed data
-- =====================================================================
INSERT IGNORE INTO property_types (name, sort_order) VALUES
 ('Condominium Unit', 1), ('Parking Slot', 2), ('Office Unit', 3),
 ('Commercial Unit', 4), ('House and Lot', 5), ('Lot', 6), ('Other Asset', 7);

INSERT IGNORE INTO settings (skey, svalue) VALUES
 ('site_name', 'Cityland Online Property Bidding'),
 ('company_name', 'Cityland'),
 ('contact_email', 'bidding@example.com'),
 ('contact_phone', '+63 2 0000 0000'),
 ('contact_address', 'Makati City, Metro Manila, Philippines'),
 ('dpo_name', 'Data Protection Officer'),
 ('dpo_email', 'dpo@example.com'),
 ('dpo_phone', '+63 2 0000 0000'),
 ('require_admin_approval', '1'),
 ('require_mobile_otp', '0'),
 ('require_docs_approved', '1'),
 ('dual_auth_award', '1'),
 ('dual_auth_schedule', '1'),
 ('admin_2fa_required', '0'),
 ('admin_notification_emails', ''),
 ('closing_reminder_hours', '24'),
 ('data_retention_years', '10'),
 ('session_idle_minutes', '30'),
 ('bid_security_enabled', '0'),
 ('payment_gateway', 'none'),
 ('dup_ip_threshold', '3');

INSERT IGNORE INTO requirement_types (id, name, description, scope, is_required, sort_order, created_at) VALUES
 (1, 'Government-issued ID', 'Clear copy of a valid government-issued ID (e.g., Passport, Driver''s License, UMID, PhilSys ID). Front and back if applicable.', 'registration', 1, 1, NOW()),
 (2, 'Proof of Billing / Address', 'Recent utility bill or bank statement (not older than 3 months).', 'registration', 0, 2, NOW()),
 (3, 'SEC/DTI Registration (Companies)', 'For corporate bidders: SEC/DTI registration and Secretary''s Certificate/Board Resolution authorizing the representative.', 'property', 0, 3, NOW()),
 (4, 'Proof of Financial Capacity', 'Bank certificate, pre-approved loan letter, or similar proof of capacity to pay.', 'property', 0, 4, NOW());

INSERT IGNORE INTO legal_documents (doc_type, version, title, content, is_current, created_at) VALUES
('terms', '1.0', 'Bidding Terms and Conditions',
'<h3>1. Eligibility</h3><p>Only registered, verified and qualified bidders may submit bids. Cityland reserves the right to require additional documents to establish identity, eligibility and financial capacity.</p>
<h3>2. Bids</h3><p>All bids are binding offers to purchase the property at the stated amount, subject to these terms and to the specific terms of each property. Bids are timestamped by the Cityland server; the bidder''s device clock is not used.</p>
<h3>3. Revisions and Withdrawals</h3><p>Where allowed by the property''s bid rules, a bidder may revise a bid. All previous bids are permanently retained. Withdrawal is only permitted where the property''s rules expressly allow it.</p>
<h3>4. Closing and Automatic Extension</h3><p>Bidding closes at the official closing time shown on the property page. Where enabled, a valid bid received within the configured final minutes automatically extends the closing time as disclosed on the property page.</p>
<h3>5. Evaluation and Award</h3><p>Ranking is for evaluation purposes only. The highest bid does not automatically win. Cityland will evaluate bidder identity, documents, eligibility, payment capability, compliance and other qualification requirements. Awards are made only upon approval by authorized Cityland officers. Cityland may designate backup bidders.</p>
<h3>6. Reservation of Rights</h3><p>Cityland reserves the right to reject any or all bids, to disqualify bidders for misrepresentation or non-compliance, and to cancel or suspend bidding for any property, without liability.</p>
<h3>7. Confidentiality</h3><p>Identities of bidders are never disclosed to other bidders. Competing bid amounts are only displayed where a property''s rules expressly allow.</p>
<h3>8. Governing Law</h3><p>These terms are governed by the laws of the Republic of the Philippines.</p>', 1, NOW()),
('privacy', '1.0', 'Privacy Notice',
'<h3>Who we are</h3><p>Cityland (the "Company") operates this Online Property Bidding System. The Company is the personal information controller of the data you provide here.</p>
<h3>What we collect</h3><ul><li>Identity and contact details: full name, company, address, mobile number, email address</li><li>Government-issued ID and qualification documents you upload</li><li>Bid records: amounts, server timestamps, bid references</li><li>Technical data: IP address, browser/device information</li><li>Location (latitude, longitude, accuracy) <strong>only if you give permission</strong> at the time of bidding</li></ul>
<h3>Why we collect it (purpose)</h3><ul><li>To verify your identity and prevent fraud, duplicate or fictitious accounts</li><li>To evaluate eligibility and qualification to bid and to be awarded</li><li>To maintain a secure, auditable, tamper-evident bidding record</li><li>To communicate with you about your registration, bids and results</li><li>To comply with legal and regulatory obligations</li></ul>
<h3>Legal basis</h3><p>Your consent, the performance of pre-contractual steps you request, and compliance with legal obligations, pursuant to Republic Act No. 10173 (Data Privacy Act of 2012) and its Implementing Rules and Regulations.</p>
<h3>Sharing</h3><p>Your data is accessible only to authorized Cityland personnel on a need-to-know basis, and to service providers (e.g., hosting, email, SMS) bound by confidentiality. Your identity is never shown to other bidders.</p>
<h3>Retention</h3><p>Bidding and audit records are retained for the period configured by the Company (default: ten (10) years from the close of the bidding event) to meet legal, audit and dispute-resolution requirements, after which they are securely disposed of or anonymized.</p>
<h3>Your rights</h3><p>You have the right to be informed, to access, to object, to erasure or blocking (subject to legal retention), to rectify, to data portability, to damages, and to file a complaint with the National Privacy Commission.</p>
<h3>Security</h3><p>We use encryption in transit (HTTPS), hashed passwords, access controls, audit logging and other organizational, physical and technical measures.</p>', 1, NOW()),
('gps_consent', '1.0', 'Location Consent',
'<p>When you submit a bid, we may ask your permission to record your device''s location (latitude, longitude, accuracy and time). This is used solely to strengthen the integrity of the bidding record, help detect fraudulent or automated bidding, and support dispute resolution. Location is captured <strong>only when you click the button to share it</strong> and only at the time of bid submission; we do not track you. If you decline, your bid record will state "Location permission not granted." Some bidding events may require location to submit a bid; this is disclosed on the property page.</p>', 1, NOW());
