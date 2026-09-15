-- Apply once after 003_employee_workspaces.sql, in the selected database.
-- Adds employee nomination evidence, manager decision reasons, and a durable
-- HR escalation record without changing existing review or feedback rows.

ALTER TABLE peer_nominations
  ADD COLUMN shared_work VARCHAR(255) NULL AFTER peer_id,
  ADD COLUMN collaboration_details TEXT NULL AFTER shared_work,
  ADD COLUMN reviewer_justification TEXT NULL AFTER collaboration_details,
  ADD COLUMN direct_knowledge_confirmed TINYINT(1) NOT NULL DEFAULT 1 AFTER reviewer_justification,
  ADD COLUMN decision_reason TEXT NULL AFTER decided_by,
  ADD COLUMN decided_at DATETIME NULL AFTER decision_reason,
  ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

UPDATE peer_nominations
JOIN review_participants ON review_participants.id = peer_nominations.participant_id
SET nominated_by = COALESCE(peer_nominations.nominated_by, review_participants.employee_id),
    shared_work = COALESCE(NULLIF(shared_work, ''), 'Previous shared work'),
    collaboration_details = COALESCE(NULLIF(collaboration_details, ''), 'This nomination was created before collaboration evidence was required.'),
    reviewer_justification = COALESCE(NULLIF(reviewer_justification, ''), 'This nomination was created before reviewer justification was required.'),
    decision_reason = CASE WHEN status = 'rejected' THEN COALESCE(NULLIF(decision_reason, ''), 'Rejected before decision reasons were required.') ELSE decision_reason END,
    decided_at = CASE WHEN status IN ('approved','rejected') THEN COALESCE(decided_at, created_at) ELSE decided_at END;

ALTER TABLE peer_nominations
  MODIFY shared_work VARCHAR(255) NOT NULL,
  MODIFY collaboration_details TEXT NOT NULL,
  MODIFY reviewer_justification TEXT NOT NULL,
  MODIFY nominated_by INT NOT NULL,
  ADD KEY idx_peer_nomination_status (status, created_at);

-- Older manager decisions changed only the nomination status. Ensure every
-- existing approval now has the peer's real review request as well.
INSERT INTO feedback_requests (participant_id, respondent_id, type, status)
SELECT participant_id, peer_id, 'peer', 'pending'
FROM peer_nominations
WHERE status = 'approved'
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE peer_nomination_escalations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomination_id INT NOT NULL,
  employee_id INT NOT NULL,
  escalation_reason TEXT NOT NULL,
  status ENUM('pending_hr', 'resolved_upheld', 'resolved_overturned') NOT NULL DEFAULT 'pending_hr',
  escalated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_by INT NULL,
  resolution_note TEXT NULL,
  resolved_at DATETIME NULL,
  CONSTRAINT fk_pne_nomination FOREIGN KEY (nomination_id) REFERENCES peer_nominations (id),
  CONSTRAINT fk_pne_employee FOREIGN KEY (employee_id) REFERENCES users (id),
  CONSTRAINT fk_pne_resolver FOREIGN KEY (resolved_by) REFERENCES users (id),
  UNIQUE KEY uq_peer_nomination_escalation (nomination_id),
  KEY idx_peer_escalation_status (status, escalated_at)
);
