<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$recordId = filter_input(INPUT_GET, 'record_id', FILTER_VALIDATE_INT);

if (!$recordId || !$pdo) {
    http_response_code(404);
    exit;
}

// Scoped to this patient's own UserID -- one patient can never stream
// back another patient's medical record, even by guessing the record ID.
$stmt = $pdo->prepare('SELECT FilePath, FileType, Title FROM MedicalRecords WHERE RecordID = ? AND PatientID = ?');
$stmt->execute([$recordId, $user['UserID']]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit;
}

$fullPath = __DIR__ . '/../storage/medical-records/' . basename($row['FilePath']);

if (!is_file($fullPath)) {
    http_response_code(404);
    exit;
}

$mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
header('Content-Type: ' . ($mimeMap[$row['FileType']] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($fullPath));
// ?download=1 saves the file under its record title; otherwise it opens inline.
$downloadName = trim(preg_replace('/[^A-Za-z0-9 _.-]+/', '', $row['Title'])) ?: 'medical-record';
$disposition = isset($_GET['download']) ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '.' . $row['FileType'] . '"');
header('Cache-Control: private, no-store');
readfile($fullPath);
