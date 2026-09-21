-- Apply once after migration 009. The unused feedback_summary table is removed;
-- all live review, HR and admin workflow records are preserved.

DROP TABLE IF EXISTS feedback_summary;

ALTER TABLE users
  ADD COLUMN review_eligible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

-- Leadership/service accounts are opt-in; everyone else with Personal access may
-- participate when they have an active primary manager.
UPDATE users SET review_eligible=0 WHERE role='leadership';

ALTER TABLE review_cycles
  MODIFY name VARCHAR(120) NOT NULL,
  ADD COLUMN published_at DATETIME NULL AFTER created_by;
UPDATE review_cycles SET status='manager_review' WHERE status='calibration';
ALTER TABLE review_cycles MODIFY status ENUM('draft','open','peer_review','manager_review','released','closed') NOT NULL DEFAULT 'draft';

ALTER TABLE review_participants
  ADD COLUMN action_manager_id INT NULL AFTER manager_id,
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER released_at,
  ADD CONSTRAINT fk_rp_action_mgr FOREIGN KEY (action_manager_id) REFERENCES users(id);
UPDATE review_participants SET action_manager_id=manager_id WHERE action_manager_id IS NULL;
ALTER TABLE review_participants MODIFY action_manager_id INT NOT NULL;

ALTER TABLE feedback_requests
  MODIFY status ENUM('pending','submitted','expired','cancelled','waived') NOT NULL DEFAULT 'pending',
  ADD COLUMN response_deadline DATE NULL AFTER status;

-- Freeze the rubric when a draft is published. Existing cycles inherit the
-- framework that was active when this migration is applied.
CREATE TABLE review_cycle_competencies (
  cycle_id INT NOT NULL,
  competency_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  display_order SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (cycle_id,competency_id),
  UNIQUE KEY uq_cycle_comp_order (cycle_id,display_order),
  FOREIGN KEY (cycle_id) REFERENCES review_cycles(id),
  FOREIGN KEY (competency_id) REFERENCES competencies(id)
);
INSERT INTO review_cycle_competencies(cycle_id,competency_id,name,description,display_order)
SELECT rc.id,c.id,c.name,c.description,
       ROW_NUMBER() OVER (PARTITION BY rc.id ORDER BY c.id)
FROM review_cycles rc CROSS JOIN competencies c
WHERE c.is_active=1 AND rc.status<>'draft';

CREATE TABLE review_participant_exceptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  participant_id INT NOT NULL,
  exception_type ENUM('excluded','withdrawn','waive_self','waive_peer') NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  granted_by INT NOT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_by INT NULL,
  revoked_at DATETIME NULL,
  active_exception TINYINT GENERATED ALWAYS AS (CASE WHEN revoked_at IS NULL THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_active_participant_exception (participant_id,exception_type,active_exception),
  FOREIGN KEY (participant_id) REFERENCES review_participants(id),
  FOREIGN KEY (granted_by) REFERENCES users(id),
  FOREIGN KEY (revoked_by) REFERENCES users(id)
);

CREATE TABLE active_record_reassignments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  record_type ENUM('review_participant','goal','pdp','pip') NOT NULL,
  record_id INT NOT NULL,
  previous_owner_id INT NOT NULL,
  new_owner_id INT NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  reassigned_by INT NOT NULL,
  reassigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (previous_owner_id) REFERENCES users(id),
  FOREIGN KEY (new_owner_id) REFERENCES users(id),
  FOREIGN KEY (reassigned_by) REFERENCES users(id),
  KEY idx_reassignment_record (record_type,record_id)
);

ALTER TABLE pdps
  ADD COLUMN agreed_by INT NULL AFTER agreed_at,
  ADD CONSTRAINT fk_pdp_agreed_by FOREIGN KEY (agreed_by) REFERENCES users(id);
UPDATE pdps SET agreed_by=manager_id WHERE status IN ('agreed','completed') AND agreed_by IS NULL;

CREATE TABLE pdp_change_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pdp_id INT NOT NULL,
  requested_by INT NOT NULL,
  note TEXT NOT NULL,
  status ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_by INT NULL,
  resolved_at DATETIME NULL,
  active_request TINYINT GENERATED ALWAYS AS (CASE WHEN status='pending' THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY uq_active_pdp_change_request (pdp_id,active_request),
  FOREIGN KEY (pdp_id) REFERENCES pdps(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (resolved_by) REFERENCES users(id)
);

INSERT IGNORE INTO permissions(permission_code,description) VALUES
 ('hr.cycles.manage','Configure, publish and manage review cycles'),
 ('hr.directory.read','Read employee directory and reporting metadata'),
 ('hr.reviews.read','Read protected employee review summaries'),
 ('hr.competencies.manage','Manage the review competency framework'),
 ('hr.records.reassign','Reassign active performance records');

INSERT IGNORE INTO role_permissions(role_code,permission_id)
SELECT r.role_code,p.id FROM roles r CROSS JOIN permissions p
WHERE (p.permission_code IN ('hr.cycles.manage','hr.directory.read','hr.reviews.read','hr.competencies.manage','hr.records.reassign')
       AND r.role_code IN ('hr','admin'))
   OR (p.permission_code IN ('hr.directory.read','hr.reviews.read','hr.records.reassign') AND r.role_code='hr_partner')
   OR (p.permission_code='hr.directory.read' AND r.role_code='hr_coordinator');

-- Existing non-draft cycles predate the publish action.
UPDATE review_cycles SET published_at=COALESCE(published_at,CURRENT_TIMESTAMP)
WHERE status<>'draft';

CREATE OR REPLACE VIEW v_peer_feedback AS
SELECT fr.participant_id,fr.id request_id,rcc.name competency,frt.score,frt.comment
FROM feedback_requests fr
JOIN feedback_ratings frt ON frt.request_id=fr.id
JOIN review_participants rp ON rp.id=fr.participant_id
JOIN review_cycle_competencies rcc ON rcc.cycle_id=rp.cycle_id AND rcc.competency_id=frt.competency_id
WHERE fr.type='peer' AND fr.status='submitted';

CREATE OR REPLACE VIEW v_360_summary AS
SELECT fr.participant_id,rcc.name competency,fr.type,ROUND(AVG(frt.score),2) avg_score,COUNT(*) responses
FROM feedback_requests fr
JOIN feedback_ratings frt ON frt.request_id=fr.id
JOIN review_participants rp ON rp.id=fr.participant_id
JOIN review_cycle_competencies rcc ON rcc.cycle_id=rp.cycle_id AND rcc.competency_id=frt.competency_id
WHERE fr.status='submitted'
GROUP BY fr.participant_id,rcc.name,fr.type;
