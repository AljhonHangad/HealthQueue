<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

// JSON for the booking modal:
//   ?clinic_id=&physician_id=&month=YYYY-MM  -> { dates: [open dates], full: [fully booked dates] }
//   ?clinic_id=&physician_id=&date=YYYY-MM-DD -> { times: [{ value: "08:00", label: "8:00 AM" }, ...] }
header('Content-Type: application/json');
header('Cache-Control: no-store');

$pdo = getDbConnection();
$clinicId = filter_var($_GET['clinic_id'] ?? null, FILTER_VALIDATE_INT);
$physicianId = filter_var($_GET['physician_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

if (!$pdo || !$clinicId) {
    echo json_encode(['dates' => [], 'times' => []]);
    exit;
}

try {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? ''))) {
        $times = array_map(
            static fn(string $t): array => ['value' => $t, 'label' => date('g:i A', strtotime($t))],
            bookableTimes($pdo, $clinicId, $physicianId, $_GET['date'])
        );
        echo json_encode(['times' => $times]);
    } else {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $dates = bookableDates($pdo, $clinicId, $physicianId, $from, $to);
        echo json_encode(['dates' => $dates['open'], 'full' => $dates['full']]);
    }
} catch (PDOException $e) {
    error_log('Availability API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Availability is temporarily unavailable.']);
}
