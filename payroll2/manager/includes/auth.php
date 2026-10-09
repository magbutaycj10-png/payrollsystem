<?php
require_once __DIR__ . '/../../includes/helpers.php';

function requireManager(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['mgr_logged_in']) || !sessionStillActive()) {   /* signed in, and not idle too long */
        header('Location: /index.php');
        exit;
    }
    applySchemaPatches();
}

function mgr(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return [
        'id'     => $_SESSION['mgr_id']     ?? 0,
        'name'   => $_SESSION['mgr_name']   ?? '',
        'branch' => $_SESSION['mgr_branch'] ?? '',
    ];
}

/*
 * mgrEmpIds()
 * Resolves the exact set of employees this manager is responsible for.
 *
 *   scope_type = 'custom'  -> the rows picked in manager_employees
 *   scope_type = 'branch'  -> everyone in the manager's branch
 *   branch is blank        -> unscoped, the whole company
 *
 * Returns NULL for "no restriction", or an array of emp_id strings
 * (possibly empty, meaning this manager can see nobody).
 *
 * Read from the database rather than the session so that an admin
 * changing an assignment takes effect without the manager re-logging in.
 */
function mgrEmpIds(): ?array {
    static $resolved = false;
    static $cache    = null;
    if ($resolved) return $cache;
    $resolved = true;

    $m = mgr();
    if (!$m['id']) return $cache = [];

    $db  = getDB();
    $row = $db->prepare("SELECT branch, scope_type FROM users WHERE id = ? AND role = 'manager'");
    $row->execute([$m['id']]);
    $row = $row->fetch();
    if (!$row) return $cache = [];

    if (($row['scope_type'] ?? 'branch') === 'custom') {
        $st = $db->prepare("SELECT emp_id FROM manager_employees WHERE manager_id = ?");
        $st->execute([$m['id']]);
        return $cache = $st->fetchAll(PDO::FETCH_COLUMN);
    }

    $branch = trim((string)($row['branch'] ?? ''));
    if ($branch === '') return $cache = null;   /* all employees */

    $st = $db->prepare("SELECT emp_id FROM employees WHERE branch = ?");
    $st->execute([$branch]);
    return $cache = $st->fetchAll(PDO::FETCH_COLUMN);
}

/*
 * mgrScopeWhere()
 * SQL fragment + bind params restricting a query to this manager's employees.
 * Filters on emp_id, so it works against any table that carries one
 * (employees, payroll, attendance, leave_requests) - pass the table alias.
 */
function mgrScopeWhere(string $alias = 'e'): array {
    $ids = mgrEmpIds();
    if ($ids === null) return ['', []];              /* unscoped */
    if (!$ids)         return [' AND 1=0 ', []];     /* assigned nobody */
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return [" AND {$alias}.emp_id IN ($placeholders) ", array_values($ids)];
}

/*
 * Human-readable description of a manager's scope, for page subtitles.
 */
function mgrScopeLabel(): string {
    $ids = mgrEmpIds();
    if ($ids === null) return 'All employees';
    $m = mgr();
    $db  = getDB();
    $row = $db->prepare("SELECT scope_type FROM users WHERE id = ?");
    $row->execute([$m['id']]);
    if ($row->fetchColumn() === 'custom') {
        return count($ids) . ' assigned employee(s)';
    }
    return $m['branch'] !== '' ? $m['branch'] . ' branch' : 'All employees';
}
