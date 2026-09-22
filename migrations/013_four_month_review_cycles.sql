-- Four-month review-cycle guardrails.
-- Existing cycles remain intact; this only prevents duplicate periods.
ALTER TABLE review_cycles
  ADD UNIQUE KEY uq_review_cycle_period (period_start, period_end);
