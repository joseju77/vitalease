-- Stock invariants after the concurrency experiments.
--
-- Run against the application database (make swarm-sql-check feeds it to psql with
-- ON_ERROR_STOP=1, so a failed invariant gives a non-zero exit). Variables come from
-- k6's sql-check-vars.txt (written by handleSummary):
--   run_id              RUN_ID of the experiment (finds the baseline movement)
--   initial_stock       STOCK the experiment set in setup()
--   dispense_accepted   consultations accepted (HTTP 302)
--   dispense_rejected   consultations rejected for insufficient stock (HTTP 422)
--   dispense_quantity   units dispensed per accepted consultation
--   adjust_added / adjust_removed   units added / removed by accepted adjustments
--   adjust_accepted     accepted adjustments
-- Read-only: it never writes.

\set ON_ERROR_STOP on
\pset format aligned
\pset pager off

CREATE TEMP TABLE check_scope AS
SELECT m.id AS medication_id, m.uuid, m.name, m.current_stock, b.id AS baseline_id
FROM inventory_movements b
JOIN medications m ON m.id = b.medication_id
WHERE b.notes = 'k6 setup ' || :'run_id'
ORDER BY b.id DESC
LIMIT 1;

CREATE TEMP TABLE results (check_name text, passed boolean, detail text);

-- Fail clearly when the run id matches nothing.
INSERT INTO results
SELECT 'baseline movement found', EXISTS (SELECT 1 FROM check_scope),
       COALESCE((SELECT 'medication ' || name || ' (' || uuid || ')' FROM check_scope), 'no movement with note "k6 setup ' || :'run_id' || '"');

-- 1. The stock never went negative (table constraint plus current value).
INSERT INTO results
SELECT 'current_stock >= 0', s.current_stock >= 0, 'current_stock=' || s.current_stock
FROM check_scope s;

INSERT INTO results
SELECT 'no movement with negative stock_after', NOT EXISTS (
    SELECT 1 FROM inventory_movements im JOIN check_scope s ON s.medication_id = im.medication_id WHERE im.stock_after < 0
), 'across the whole movement history of the medication';

-- 2. current_stock equals the sum of all of its movements.
INSERT INTO results
SELECT 'current_stock = sum(movements)', s.current_stock = COALESCE(t.total, 0),
       'current_stock=' || s.current_stock || ' sum=' || COALESCE(t.total, 0)
FROM check_scope s
LEFT JOIN LATERAL (SELECT sum(quantity) AS total FROM inventory_movements WHERE medication_id = s.medication_id) t ON true;

-- 3. The stock_after chain is consistent: each movement equals the previous
--    stock_after plus its quantity, and the last one is current_stock.
WITH ordered AS (
    SELECT im.id, im.quantity, im.stock_after,
           lag(im.stock_after, 1, 0) OVER (ORDER BY im.id) AS previous_after
    FROM inventory_movements im JOIN check_scope s ON s.medication_id = im.medication_id
)
INSERT INTO results
SELECT 'stock_after chain consistent', count(*) FILTER (WHERE previous_after + quantity <> stock_after) = 0,
       count(*) FILTER (WHERE previous_after + quantity <> stock_after) || ' inconsistent of ' || count(*) || ' movements'
FROM ordered;

INSERT INTO results
SELECT 'last stock_after = current_stock', s.current_stock = (
    SELECT stock_after FROM inventory_movements WHERE medication_id = s.medication_id ORDER BY id DESC LIMIT 1
), 'current_stock=' || s.current_stock
FROM check_scope s;

-- 4. Accounting: every accepted dispensation/adjustment is one movement after
--    the baseline, and the final stock equals initial stock minus dispensed
--    plus net adjustments. Rejected (422) requests wrote nothing.
INSERT INTO results
SELECT 'accepted dispensations = dispensation movements',
       (SELECT count(*) FROM inventory_movements im WHERE im.medication_id = s.medication_id AND im.id > s.baseline_id AND im.type = 2) = :dispense_accepted,
       'movements=' || (SELECT count(*) FROM inventory_movements im WHERE im.medication_id = s.medication_id AND im.id > s.baseline_id AND im.type = 2)
       || ' accepted=' || :dispense_accepted || ' rejected(422)=' || :dispense_rejected
FROM check_scope s;

INSERT INTO results
SELECT 'accepted adjustments = adjustment movements',
       (SELECT count(*) FROM inventory_movements im WHERE im.medication_id = s.medication_id AND im.id > s.baseline_id AND im.type = 3) = :adjust_accepted,
       'movements=' || (SELECT count(*) FROM inventory_movements im WHERE im.medication_id = s.medication_id AND im.id > s.baseline_id AND im.type = 3)
       || ' accepted=' || :adjust_accepted
FROM check_scope s;

INSERT INTO results
SELECT 'initial - dispensed + net adjustments = current_stock',
       s.current_stock = :initial_stock - :dispense_accepted * :dispense_quantity + :adjust_added - :adjust_removed,
       'current_stock=' || s.current_stock || ' expected=' || (:initial_stock - :dispense_accepted * :dispense_quantity + :adjust_added - :adjust_removed)
       || ' (initial=' || :initial_stock || ' dispensed_units=' || (:dispense_accepted * :dispense_quantity) || ' added=' || :adjust_added || ' removed=' || :adjust_removed || ')'
FROM check_scope s;

-- 5. Whole ledger (every medication): the same sum and chain invariants.
INSERT INTO results
SELECT 'all medications: current_stock = sum(movements)', count(*) = 0, count(*) || ' mismatching medications'
FROM medications m
WHERE m.current_stock <> COALESCE((SELECT sum(quantity) FROM inventory_movements WHERE medication_id = m.id), 0);

INSERT INTO results
SELECT 'all medications: no negative stock', count(*) = 0, count(*) || ' medications below zero'
FROM medications WHERE current_stock < 0;

SELECT check_name, CASE WHEN passed THEN 'PASS' ELSE 'FAIL' END AS result, detail FROM results ORDER BY ctid;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM results WHERE NOT passed) THEN
        RAISE EXCEPTION 'stock consistency check FAILED';
    END IF;
END
$$;
