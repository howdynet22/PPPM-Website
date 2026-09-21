-- Peer nomination workflow v2
-- Participants must secure the cycle's minimum number of approved/assigned
-- peer reviewers before HR can open the peer-review stage. Managers may suggest
-- a replacement when rejecting a nomination. Peer response count remains an
-- anonymity threshold only and does not block manager review.

ALTER TABLE peer_nominations
  ADD COLUMN suggested_peer_id INT NULL AFTER decision_reason,
  ADD COLUMN suggestion_reason TEXT NULL AFTER suggested_peer_id,
  ADD CONSTRAINT fk_pn_suggested_peer
    FOREIGN KEY (suggested_peer_id) REFERENCES users(id);
