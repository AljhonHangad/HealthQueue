<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

// Physicians must keep a contact number on file.
$profileContactRequired = true;
require __DIR__ . '/../includes/profile-page.php';
