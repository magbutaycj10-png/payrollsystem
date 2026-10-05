<?php
require_once __DIR__ . '/../../includes/helpers.php';

function requireEmployee(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['emp_logged_in']) || !sessionStillActive()) {   /* signed in, and not idle too long */
        header('Location: /index.php');
        exit;
    }
    applySchemaPatches();
}

function emp(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return [
        'id'   => $_SESSION['emp_id']   ?? '',
        'name' => $_SESSION['emp_name'] ?? '',
    ];
}
