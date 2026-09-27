-- =====================================================================
-- OPTIONAL (recommended): database-level immutability guards.
-- Blocks UPDATE/DELETE on bids, audit_logs, ranking_snapshots and consents
-- for every database user, including the application user.
--
-- Import AFTER schema.sql. On some shared hosts the database user needs the
-- TRIGGER privilege (cPanel "ALL PRIVILEGES" includes it). If binary logging
-- is enabled on the server you may need your host to allow trigger creation.
--
-- phpMyAdmin: paste this file in the SQL tab and set the "Delimiter" box to $$
-- CLI:        mysql -u USER -p DBNAME < triggers.sql
-- =====================================================================

DELIMITER $$

DROP TRIGGER IF EXISTS trg_bids_no_update$$
CREATE TRIGGER trg_bids_no_update BEFORE UPDATE ON bids FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bid records are immutable';
END$$

DROP TRIGGER IF EXISTS trg_bids_no_delete$$
CREATE TRIGGER trg_bids_no_delete BEFORE DELETE ON bids FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bid records are immutable';
END$$

DROP TRIGGER IF EXISTS trg_audit_no_update$$
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit records are immutable';
END$$

DROP TRIGGER IF EXISTS trg_audit_no_delete$$
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit records are immutable';
END$$

DROP TRIGGER IF EXISTS trg_rank_no_update$$
CREATE TRIGGER trg_rank_no_update BEFORE UPDATE ON ranking_snapshots FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Locked rankings are immutable';
END$$

DROP TRIGGER IF EXISTS trg_rank_no_delete$$
CREATE TRIGGER trg_rank_no_delete BEFORE DELETE ON ranking_snapshots FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Locked rankings are immutable';
END$$

DROP TRIGGER IF EXISTS trg_consent_no_update$$
CREATE TRIGGER trg_consent_no_update BEFORE UPDATE ON consents FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consent records are immutable';
END$$

DROP TRIGGER IF EXISTS trg_consent_no_delete$$
CREATE TRIGGER trg_consent_no_delete BEFORE DELETE ON consents FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consent records are immutable';
END$$

DELIMITER ;
