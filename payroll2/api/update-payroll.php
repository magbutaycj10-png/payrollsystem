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
