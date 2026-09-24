<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

$user = currentUser();
$pdo = getDbConnection();
$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT);

if (!$appointmentId || !$pdo) {
    http_response_code(404);
    exit;
}

// Scoped to this physician's own UserID -- one physician can never stream
// back another physician's recording, even by guessing the appointment ID.
$stmt = $pdo->prepare(
    "SELECT cons.AudioFilePath
     FROM Consultations cons
     JOIN Appointments a ON a.AppointmentID = cons.AppointmentID
     WHERE cons.AppointmentID = ? AND a.PhysicianID = ?"
);
$stmt->execute([$appointmentId, $user['UserID']]);
$row = $stmt->fetch();

if (!$row || !$row['AudioFilePath']) {
    http_response_code(404);
    exit;
}

$fullPath = __DIR__ . '/../storage/audio/' . basename($row['AudioFilePath']);

if (!is_file($fullPath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: audio/webm');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, no-store');
readfile($fullPath);
