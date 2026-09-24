<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'record_payment') {
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
        if (!$appointmentId) {
            $errors[] = 'Invalid appointment.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT a.AppointmentID, a.PhysicianID, a.Status, c.BaseConsultationFee, p.PaymentID FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID LEFT JOIN AppointmentPayments p ON p.AppointmentID = a.AppointmentID WHERE a.AppointmentID = ? FOR UPDATE");
                $stmt->execute([$appointmentId]);
                $appointment = $stmt->fetch();
                if (!$appointment) {
                    $errors[] = 'The appointment could not be found.';
                } elseif ($appointment['PaymentID']) {
                    $errors[] = 'A payment has already been recorded for this appointment.';
                } elseif (!$appointment['PhysicianID']) {
                    $errors[] = 'Assign a physician before recording payment and revenue.';
                } elseif ($appointment['Status'] === 'Cancelled') {
                    $errors[] = 'Cancelled appointments cannot receive a payment.';
                } elseif ($appointment['Status'] === 'NoShow') {
                    $errors[] = 'No-show appointments cannot receive a payment.';
                } elseif ($appointment['Status'] !== 'Completed') {
                    $errors[] = 'The physician must mark this consultation complete before payment can be recorded.';
                } elseif ((float) $appointment['BaseConsultationFee'] <= 0) {
                    $errors[] = 'This clinic needs a consultation fee before payment can be recorded.';
                } else {
                    $gross = round((float) $appointment['BaseConsultationFee'], 2);
                    $physicianRevenue = round($gross * 0.90, 2);
                    $commission = round($gross - $physicianRevenue, 2);
                    $insert = $pdo->prepare("INSERT INTO AppointmentPayments (AppointmentID, GrossAmount, PhysicianRevenue, PlatformCommission, PaymentStatus) VALUES (?, ?, ?, ?, 'Paid')");
                    $insert->execute([$appointmentId, $gross, $physicianRevenue, $commission]);
                    logActivity($pdo, $user['UserID'], null, 'Recorded payment', "Appointment #{$appointmentId}: PHP {$gross} gross, PHP {$commission} commission.");
                    $flash = 'Payment recorded: physician receives PHP ' . number_format($physicianRevenue, 2) . '; HealthQueue commission is PHP ' . number_format($commission, 2) . '.';
                }
                if ($errors) $pdo->rollBack(); else $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Payment recording failed: ' . $e->getMessage());
                $errors[] = 'We could not record this payment.';
            }
        }
    }
}

$totals = ['gross' => 0, 'physician' => 0, 'commission' => 0, 'payments' => 0];
$commissionByPeriod = ['week_commission' => 0, 'week_count' => 0, 'month_commission' => 0, 'month_count' => 0, 'year_commission' => 0, 'year_count' => 0];
$physicianRevenue = [];
$paymentQueue = [];
$recentPayments = [];
if ($pdo) {
    try {
        $totals = $pdo->query("SELECT COALESCE(SUM(GrossAmount), 0) AS gross, COALESCE(SUM(PhysicianRevenue), 0) AS physician, COALESCE(SUM(PlatformCommission), 0) AS commission, COUNT(*) AS payments FROM AppointmentPayments WHERE PaymentStatus = 'Paid'")->fetch();
        // YEARWEEK(..., 1) uses Monday-start, ISO-8601-style weeks so "this week" lines up with a normal calendar week.
        $commissionByPeriod = $pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN YEARWEEK(PaidAt, 1) = YEARWEEK(CURDATE(), 1) THEN PlatformCommission ELSE 0 END), 0) AS week_commission,
                COUNT(CASE WHEN YEARWEEK(PaidAt, 1) = YEARWEEK(CURDATE(), 1) THEN 1 END) AS week_count,
                COALESCE(SUM(CASE WHEN YEAR(PaidAt) = YEAR(CURDATE()) AND MONTH(PaidAt) = MONTH(CURDATE()) THEN PlatformCommission ELSE 0 END), 0) AS month_commission,
                COUNT(CASE WHEN YEAR(PaidAt) = YEAR(CURDATE()) AND MONTH(PaidAt) = MONTH(CURDATE()) THEN 1 END) AS month_count,
                COALESCE(SUM(CASE WHEN YEAR(PaidAt) = YEAR(CURDATE()) THEN PlatformCommission ELSE 0 END), 0) AS year_commission,
                COUNT(CASE WHEN YEAR(PaidAt) = YEAR(CURDATE()) THEN 1 END) AS year_count
             FROM AppointmentPayments
             WHERE PaymentStatus = 'Paid'"
        )->fetch();
        $physicianRevenue = $pdo->query("SELECT u.FirstName, u.LastName, c.ClinicName, COUNT(p.PaymentID) AS paid_appointments, COALESCE(SUM(p.PhysicianRevenue), 0) AS revenue FROM AppointmentPayments p JOIN Appointments a ON a.AppointmentID = p.AppointmentID JOIN Users u ON u.UserID = a.PhysicianID JOIN Clinic c ON c.ClinicID = a.ClinicID WHERE p.PaymentStatus = 'Paid' GROUP BY u.UserID, c.ClinicID ORDER BY revenue DESC")->fetchAll();
        $paymentQueue = $pdo->query("SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Status, c.ClinicName, c.BaseConsultationFee, patient.FirstName AS PatientFirstName, patient.LastName AS PatientLastName, physician.FirstName AS PhysicianFirstName, physician.LastName AS PhysicianLastName, p.PaymentID FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID JOIN Users patient ON patient.UserID = a.PatientID LEFT JOIN Users physician ON physician.UserID = a.PhysicianID LEFT JOIN AppointmentPayments p ON p.AppointmentID = a.AppointmentID WHERE p.PaymentID IS NULL ORDER BY a.CreatedAt DESC LIMIT 20")->fetchAll();
        $recentPayments = $pdo->query("SELECT p.GrossAmount, p.PhysicianRevenue, p.PlatformCommission, p.PaidAt, c.ClinicName, u.FirstName, u.LastName FROM AppointmentPayments p JOIN Appointments a ON a.AppointmentID = p.AppointmentID JOIN Clinic c ON c.ClinicID = a.ClinicID JOIN Users u ON u.UserID = a.PhysicianID WHERE p.PaymentStatus = 'Paid' ORDER BY p.PaidAt DESC LIMIT 8")->fetchAll();
    } catch (PDOException $e) {
        error_log('Revenue dashboard failed: ' . $e->getMessage());
        $errors[] = 'Revenue information is temporarily unavailable.';
    }
}

$pageTitle = 'Revenue & Commission — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell"><div class="container"><section class="admin-hero revenue-hero"><div><span class="eyebrow">Revenue management</span><h1>Payments and commission</h1><p>Each successful payment allocates 90% to the physician and 10% to HealthQueue.</p></div><div style="display:flex;gap:10px;flex-wrap:wrap;"><a href="<?= HQ_BASE_URL ?>/admin/export.php?type=payments" class="btn btn-outline">Export CSV</a><a href="#payment-queue" class="btn btn-primary">Record a payment</a></div></section>
<?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?><?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="admin-stats revenue-stats"><article><span>Total paid bookings</span><strong><?= (int) $totals['payments'] ?></strong></article><article><span>Gross booking revenue</span><strong>PHP <?= number_format((float) $totals['gross'], 2) ?></strong></article><article><span>Physician earnings (90%)</span><strong>PHP <?= number_format((float) $totals['physician'], 2) ?></strong></article><article><span>HealthQueue commission (10%)</span><strong>PHP <?= number_format((float) $totals['commission'], 2) ?></strong></article></section>

<section class="admin-section"><div class="portal-heading"><div><span class="section-kicker">Commission by period</span><h2>How much HealthQueue has earned</h2></div></div><section class="period-stats"><article><span>This week</span><strong>PHP <?= number_format((float) $commissionByPeriod['week_commission'], 2) ?></strong><span class="unassigned"><?= (int) $commissionByPeriod['week_count'] ?> paid booking(s)</span></article><article><span>This month</span><strong>PHP <?= number_format((float) $commissionByPeriod['month_commission'], 2) ?></strong><span class="unassigned"><?= (int) $commissionByPeriod['month_count'] ?> paid booking(s)</span></article><article><span>This year</span><strong>PHP <?= number_format((float) $commissionByPeriod['year_commission'], 2) ?></strong><span class="unassigned"><?= (int) $commissionByPeriod['year_count'] ?> paid booking(s)</span></article></section></section>
<section class="admin-section" id="payment-queue"><div class="portal-heading"><div><span class="section-kicker">Payment queue</span><h2>Unpaid appointment requests</h2></div></div><div class="admin-table-wrap"><table class="admin-table financial-table"><thead><tr><th>Patient</th><th>Clinic</th><th>Physician</th><th>Schedule</th><th>Status</th><th>Fee</th><th>Action</th></tr></thead><tbody><?php foreach ($paymentQueue as $appointment): ?><tr><td><strong><?= htmlspecialchars($appointment['PatientFirstName'] . ' ' . $appointment['PatientLastName']) ?></strong></td><td><?= htmlspecialchars($appointment['ClinicName']) ?></td><td><?= $appointment['PhysicianFirstName'] ? htmlspecialchars('Dr. ' . $appointment['PhysicianFirstName'] . ' ' . $appointment['PhysicianLastName']) : '<span class="unassigned">Not assigned</span>' ?></td><td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($appointment['AppointmentDate'] . ' ' . $appointment['AppointmentTime']))) ?></td><td><span class="status-badge status-<?= strtolower(htmlspecialchars($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span></td><td>PHP <?= number_format((float) $appointment['BaseConsultationFee'], 2) ?></td><td><?php if ($appointment['PhysicianFirstName'] && (float) $appointment['BaseConsultationFee'] > 0 && $appointment['Status'] === 'Completed'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="record_payment"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['AppointmentID'] ?>"><button class="btn btn-primary btn-sm" type="submit" onclick="return confirm('Record this as a successful payment? Revenue will be split 90% to the physician and 10% to HealthQueue.');">Mark paid</button></form><?php elseif (!$appointment['PhysicianFirstName'] || (float) $appointment['BaseConsultationFee'] <= 0): ?><span class="unassigned">Assign physician / set fee first</span><?php elseif ($appointment['Status'] === 'Cancelled'): ?><span class="unassigned">Cancelled</span><?php elseif ($appointment['Status'] === 'NoShow'): ?><span class="unassigned">No-show — not billable</span><?php else: ?><span class="unassigned">Awaiting staff to confirm consultation complete</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="admin-section admin-overview-grid"><div><div class="portal-heading"><div><span class="section-kicker">Physician earnings</span><h2>Revenue by physician</h2></div></div><div class="compact-list"><?php if ($physicianRevenue): ?><?php foreach ($physicianRevenue as $row): ?><article><div><strong>Dr. <?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></strong><span><?= htmlspecialchars($row['ClinicName']) ?> · <?= (int) $row['paid_appointments'] ?> paid booking(s)</span></div><strong class="money">PHP <?= number_format((float) $row['revenue'], 2) ?></strong></article><?php endforeach; ?><?php else: ?><p class="admin-empty">No physician earnings have been recorded yet.</p><?php endif; ?></div></div><div><div class="portal-heading"><div><span class="section-kicker">Latest payments</span><h2>Commission activity</h2></div></div><div class="compact-list"><?php if ($recentPayments): ?><?php foreach ($recentPayments as $payment): ?><article><div><strong><?= htmlspecialchars($payment['ClinicName']) ?></strong><span>Dr. <?= htmlspecialchars($payment['FirstName'] . ' ' . $payment['LastName']) ?> · <?= htmlspecialchars(date('M j, Y', strtotime($payment['PaidAt']))) ?></span></div><strong class="money">PHP <?= number_format((float) $payment['PlatformCommission'], 2) ?></strong></article><?php endforeach; ?><?php else: ?><p class="admin-empty">No commission has been recorded yet.</p><?php endif; ?></div></div></section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
