<?php
/* Loaded by tests/run-tests.bat (php -r "require ...") — keeps the whole suite in library files. */
foreach (['T', 'Ledger', 'TestDb', 'AppCopy', 'Http', 'BrowserSim', 'Fixtures', 'Scenario', 'Cases', 'Edge', 'Defects', 'Mutations', 'Runner'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
exit(Runner::main($GLOBALS['argv'] ?? []));
