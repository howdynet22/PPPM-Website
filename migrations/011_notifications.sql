-- Persistent notifications and reminder/transition history.
-- Numbered 011 because current main already contains migrations through 010.
CREATE TABLE notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  notification_type VARCHAR(80) NOT NULL,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id INT NULL,
  action_url VARCHAR(255) NULL,
  dedupe_key VARCHAR(160) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at TIMESTAMP NULL,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_notification_dedupe (user_id,dedupe_key),
  KEY idx_notifications_inbox (user_id,is_read,created_at)
);

CREATE TABLE reminder_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  reminder_type VARCHAR(80) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id INT NOT NULL,
  reminder_date DATE NOT NULL,
  sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reminder_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_reminder_delivery (user_id,reminder_type,entity_type,entity_id,reminder_date)
);

CREATE TABLE review_cycle_transitions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  cycle_id INT NOT NULL,
  from_status VARCHAR(30) NOT NULL,
  to_status VARCHAR(30) NOT NULL,
  transitioned_by INT NOT NULL,
  transitioned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(1000) NULL,
  CONSTRAINT fk_cycle_transition_cycle FOREIGN KEY (cycle_id) REFERENCES review_cycles(id) ON DELETE CASCADE,
  CONSTRAINT fk_cycle_transition_actor FOREIGN KEY (transitioned_by) REFERENCES users(id),
  KEY idx_cycle_transition_history (cycle_id,transitioned_at)
);
