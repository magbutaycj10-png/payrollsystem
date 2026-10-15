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
 * mgrGrants()
 * What this manager has been given to handle - the rows of user_scopes:
 *   Global      everybody
 *   Branch      every employee of a branch
 *   Department  every employee whose position is in a department
 *   Employee    one picked employee
 * A manager with no row handles nobody. Read from the database every time (once a request) so that an admin
 * changing an assignment takes effect without the manager signing in again.
 * Returns [] when the account is not a manager.
 */
function mgrGrants(): array {
    static $grants = null;
    if ($grants !== null) return $grants;

    $m = mgr();
    if (!$m['id']) return $grants = [];

    $db = getDB();
    $ok = $db->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'Manager'");
    $ok->execute([$m['id']]);
    if (!$ok->fetchColumn()) return $grants = [];

    $st = $db->prepare("SELECT s.scope_type, s.branch_id, s.department_id, s.employee_id,
                               b.name AS branch_name, d.name AS department_name
                          FROM user_scopes s
                     LEFT JOIN branches b    ON b.id = s.branch_id
                     LEFT JOIN departments d ON d.id = s.department_id
                         WHERE s.user_id = ? ORDER BY s.id");
    $st->execute([$m['id']]);
    return $grants = $st->fetchAll();
}

/*
 * mgrEmpIds()
 * Resolves the exact set of employees this manager is responsible for from mgrGrants().
 *
 * Returns NULL for "no restriction" (a Global grant), or an array of emp_id strings
 * (possibly empty, meaning this manager can see nobody).
 */
function mgrEmpIds(): ?array {
    static $resolved = false;
    static $cache    = null;
    if ($resolved) return $cache;
    $resolved = true;

    $grants = mgrGrants();
    foreach ($grants as $g) {
        if ($g['scope_type'] === 'Global') return $cache = null;
    }

    $branches = $departments = $employees = [];
    foreach ($grants as $g) {
        if ($g['scope_type'] === 'Branch'     && $g['branch_id'])     $branches[]    = (int)$g['branch_id'];
        if ($g['scope_type'] === 'Department' && $g['department_id']) $departments[] = (int)$g['department_id'];
        if ($g['scope_type'] === 'Employee'   && $g['employee_id'])   $employees[]   = (int)$g['employee_id'];
    }
    if (!$branches && !$departments && !$employees) return $cache = [];

    $where = $args = [];
    if ($branches)    { $where[] = 'e.branch_id IN (' . implode(',', array_fill(0, count($branches), '?')) . ')';        $args = array_merge($args, $branches); }
    if ($departments){ $where[] = 'pos.department_id IN (' . implode(',', array_fill(0, count($departments), '?')) . ')'; $args = array_merge($args, $departments); }
    if ($employees)   { $where[] = 'e.id IN (' . implode(',', array_fill(0, count($employees), '?')) . ')';              $args = array_merge($args, $employees); }

    $st = getDB()->prepare("SELECT e.employee_code FROM employees e LEFT JOIN positions pos ON pos.id = e.position_id
                             WHERE " . implode(' OR ', $where));
    $st->execute($args);
    return $cache = $st->fetchAll(PDO::FETCH_COLUMN);
}

/*
 * mgrScopeWhere()
 * SQL fragment + bind params restricting a query to this manager's employees.
 * Filters on employee_code, which only the employees table carries - pass the alias
 * of employees (payroll, attendance_summary, leave_requests … join it on employee_id).
 */
function mgrScopeWhere(string $alias = 'e'): array {
    $ids = mgrEmpIds();
    if ($ids === null) return ['', []];              /* unscoped */
    if (!$ids)         return [' AND 1=0 ', []];     /* assigned nobody */
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return [" AND {$alias}.employee_code IN ($placeholders) ", array_values($ids)];
}

/*
 * Human-readable description of a manager's scope, for page subtitles.
 */
function mgrScopeLabel(): string {
    $grants = mgrGrants();
    $parts  = [];
    $picked = 0;
    foreach ($grants as $g) {
        if ($g['scope_type'] === 'Global') return 'All employees';
        if ($g['scope_type'] === 'Branch')     $parts[] = $g['branch_name'] . ' branch';
        if ($g['scope_type'] === 'Department') $parts[] = $g['department_name'] . ' department';
        if ($g['scope_type'] === 'Employee')   $picked++;
    }
    if ($picked) $parts[] = $picked . ' assigned employee(s)';
    return $parts ? implode(' + ', $parts) : 'No employees assigned';
}
