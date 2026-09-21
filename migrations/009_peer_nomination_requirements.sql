-- Adds manager replacement suggestions for rejected peer nominations.
ALTER TABLE peer_nominations
  ADD COLUMN suggested_peer_id INT NULL AFTER decision_reason,
  ADD COLUMN suggestion_reason TEXT NULL AFTER suggested_peer_id,
  ADD CONSTRAINT fk_pn_suggested_peer
    FOREIGN KEY (suggested_peer_id) REFERENCES users(id);
