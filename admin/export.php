<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

$pdo = getDbConnection();
$type = $_GET['type'] ?? '';

if (!$pdo || !in_array($type, ['clinics', 'payments'], true)) {
    http_response_code(400);
    echo 'Invalid or unavailable export requested.';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="healthqueue-' . $type . '-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');

if ($type === 'clinics') {
    fputcsv($out, ['Clinic Name', 'Address', 'Contact Number', 'Consultation Fee (PHP)', 'Status', 'Archived', 'Registered On']);
    $stmt = $pdo->query('SELECT ClinicName, Address, ContactNumber, BaseConsultationFee, Status, archived, RegistrationDate FROM Clinic ORDER BY ClinicName');
    foreach ($stmt as $row) {
        fputcsv($out, [
            $row['ClinicName'], $row['Address'], $row['ContactNumber'],
            number_format((float) $row['BaseConsultationFee'], 2, '.', ''),
            $row['Status'], $row['archived'] ? 'Yes' : 'No', $row['RegistrationDate'],
        ]);
    }
} else {
    fputcsv($out, ['Paid On', 'Clinic', 'Physician', 'Patient', 'Gross Amount (PHP)', 'Physician Revenue (PHP)', 'Platform Commission (PHP)']);
    $stmt = $pdo->query(
        "SELECT p.PaidAt, c.ClinicName, phys.FirstName AS PhysFirst, phys.LastName AS PhysLast,
                pat.FirstName AS PatFirst, pat.LastName AS PatLast,
                p.GrossAmount, p.PhysicianRevenue, p.PlatformCommission
         FROM AppointmentPayments p
         JOIN Appointments a ON a.AppointmentID = p.AppointmentID
         JOIN Clinic c ON c.ClinicID = a.ClinicID
         JOIN Users phys ON phys.UserID = a.PhysicianID
         JOIN Users pat ON pat.UserID = a.PatientID
         WHERE p.PaymentStatus = 'Paid'
         ORDER BY p.PaidAt DESC"
    );
    foreach ($stmt as $row) {
        fputcsv($out, [
            $row['PaidAt'], $row['ClinicName'], 'Dr. ' . $row['PhysFirst'] . ' ' . $row['PhysLast'],
            $row['PatFirst'] . ' ' . $row['PatLast'],
            number_format((float) $row['GrossAmount'], 2, '.', ''),
            number_format((float) $row['PhysicianRevenue'], 2, '.', ''),
            number_format((float) $row['PlatformCommission'], 2, '.', ''),
        ]);
    }
}

fclose($out);
