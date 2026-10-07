<?php
/*
 * api/update-payroll.php
 * The two period-state actions triggered by assets/js/payroll.js:
 *
 *   'finalize' — lock the period so no further money can be recorded
 *   'unlock'   — re-open a locked period so it can be corrected
 *
 * Money itself is never written here. Bonuses and deductions go through
 * adjustments.php, which writes the payroll row and the history row in one
 * transaction — a second, untransacted write path is exactly how the payroll
 * columns and the adjustment history drift apart.
 *
 * Both actions append to period_audit, so "finalized, re-opened, corrected,
 * finalized again" is a fact in the database and not just a screen state.
 */

require '../includes/helpers.php';
session_start();
if (empty($_SESSION['logged_in'])) { jsonResponse(['error' => 'Unauthorized'], 401); }
applySchemaPatches();

$db   = getDB();
$body = json_decode(file_get_contents('php://input'), true) ?: [];

$action    = $body['action'] ?? '';
$period_id = (int)($body['period_id'] ?? 0);

if (!$period_id) jsonResponse(['error' => 'Missing period_id'], 400);

$pst = $db->prepare('SELECT period_label, status, finalize_count, reopen_count
                       FROM payroll_periods WHERE id = ?');
$pst->execute([$period_id]);
$per = $pst->fetch();
if (!$per) jsonResponse(['error' => 'Period not found'], 404);

try {
    /* ---------------------------------------------------------------
     * Finalize — lock the period and stamp every payroll row Finalized.
     * finalize_count tells a first finalize from a re-finalize after a
     * correction, which is what drives the "Revised" indicators.
     * ------------------------------------------------------------- */
    if ($action === 'finalize') {
        if ($per['status'] !== 'Open') {
            jsonResponse(['error' => $per['period_label'] . ' is already finalized.'], 409);
        }

        $cnt = $db->prepare('SELECT COUNT(*) FROM payroll WHERE period_id = ?');
        $cnt->execute([$period_id]);
        $n = (int)$cnt->fetchColumn();
        if ($n === 0) {
            jsonResponse(['error' => 'Nothing to finalize — this period has no payroll records.'], 400);
        }

        /* Lines whose SSS / PhilHealth / Pag-IBIG / tax no longer settle the month (an earlier cut-off was
           corrected after this one was last computed, or Settings changed): refresh them first */
        if (empty($body['ignore_drift'])) {
            $drift = settlementDrift($db, $period_id);
            if ($drift) {
                jsonResponse([
                    'error'   => 'stale',
                    'count'   => count($drift),
                    'message' => count($drift) . ' line(s) in ' . $per['period_label'] . ' were computed against figures that have since changed '
                               . '(an earlier cut-off of the month was corrected, or Settings changed), so their contributions or tax are out of date. '
                               . 'Press "Recompute" first, then finalize.',
                ], 409);
            }
        }

        /* Net pay below zero (the statutory minimums or a deduction exceed what was earned) is not locked in unnoticed */
        if (empty($body['allow_negative'])) {
            $neg = negativeNetLines($db, $period_id);
            if ($neg) {
                jsonResponse([
                    'error'   => 'negative_net',
                    'lines'   => $neg,
                    'message' => count($neg) . ' employee(s) have a NEGATIVE net pay: ' . implode(', ', array_map(
                                     fn($l) => $l['emp_name'] . ' (₱' . number_format((float)$l['net_pay'], 2) . ')', array_slice($neg, 0, 5)))
                               . (count($neg) > 5 ? ' …' : '') . '. Check their days, rate and deductions before finalizing.',
                ], 409);
            }
        }

        /* How many rows were corrected during the cycle now closing. */
        $rev = $db->prepare('SELECT COUNT(*) FROM payroll
                              WHERE period_id = ? AND revised_after_finalize = 1');
        $rev->execute([$period_id]);
        $revised = (int)$rev->fetchColumn();

        $cycle = (int)$per['finalize_count'] + 1;

        $db->beginTransaction();
        $db->prepare("UPDATE payroll SET status = 'Finalized' WHERE period_id = ?")
           ->execute([$period_id]);
        $db->prepare("UPDATE payroll_periods
                         SET status = 'Locked', finalized_at = NOW(),
                             finalize_count = finalize_count + 1
                       WHERE id = ?")->execute([$period_id]);
        $db->commit();

        logPeriodAudit(
            $period_id,
            'Finalized',
            $cycle,
            $cycle > 1
                ? "Re-finalized after correction — $revised employee row(s) revised, $n row(s) locked."
                : "$n employee row(s) locked."
        );

        jsonResponse([
            'success'   => true,
            'finalized' => $n,
            'cycle'     => $cycle,
            'revised'   => $revised,
        ]);
    }

    /* ---------------------------------------------------------------
     * Recompute — rebuild an OPEN period (and the open cut-offs after it in the same month) from the days
     * saved for it, with the current rules and Settings. Used after unlocking a period to correct it, or after
     * an earlier cut-off changed. A period built from a totals file has no saved days: upload it again instead.
     * ------------------------------------------------------------- */
    if ($action === 'recompute') {
        if ($per['status'] !== 'Open') {
            jsonResponse(['error' => $per['period_label'] . ' is finalized. Unlock it first, then recompute.'], 409);
        }
        $days = $db->prepare('SELECT COUNT(*) FROM biometric_daily WHERE period_id = ?');
        $days->execute([$period_id]);
        if ((int)$days->fetchColumn() === 0) {
            jsonResponse(['error' => $per['period_label'] . ' has no day-by-day records to recompute from (it was built from a totals file). Upload the file again instead.'], 400);
        }
        $n = recomputeMonthFrom($db, $period_id, ['role' => 'admin', 'scope' => null]);
        logPeriodAudit($period_id, 'Revised', (int)$per['finalize_count'], "Recomputed from the saved days — $n employee line(s).");
        jsonResponse(['success' => true, 'recomputed' => $n]);
    }

    /* ---------------------------------------------------------------
     * Unlock — re-open a locked period. Payroll rows go back to Draft
     * but every bonus / deduction already recorded stays exactly as it
     * is: re-opening corrects a period, it does not wipe it.
     * ------------------------------------------------------------- */
    if ($action === 'unlock') {
        if ($per['status'] === 'Open') {
            jsonResponse(['error' => $per['period_label'] . ' is already open.'], 409);
        }

        $reopen = (int)$per['reopen_count'] + 1;

        $db->beginTransaction();
        $db->prepare("UPDATE payroll SET status = 'Draft' WHERE period_id = ?")
           ->execute([$period_id]);
        $db->prepare("UPDATE payroll_periods
                         SET status = 'Open', reopen_count = reopen_count + 1, reopened_at = NOW()
                       WHERE id = ?")->execute([$period_id]);
        $db->commit();

        logPeriodAudit(
            $period_id,
            'Reopened',
            (int)$per['finalize_count'],
            'Re-opened for correction (re-open #' . $reopen . ').'
        );

        jsonResponse([
            'success'      => true,
            'reopen_count' => $reopen,
        ]);
    }

    jsonResponse(['error' => 'Unknown action'], 400);

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(['error' => friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'], 500);
}
