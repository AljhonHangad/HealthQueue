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
    } elseif (($_POST['form_type'] ?? '') === 'review_inquiry') {
        $inquiryId = filter_input(INPUT_POST, 'inquiry_id', FILTER_VALIDATE_INT);
        $decision = $_POST['decision'] ?? '';
        if (!$inquiryId || !in_array($decision, ['approve', 'reject'], true)) {
            $errors[] = 'Invalid registration action.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM ClinicRegistrationInquiry WHERE InquiryID = ? FOR UPDATE');
                $stmt->execute([$inquiryId]);
                $inquiry = $stmt->fetch();
                $newClinicId = null;
                if (!$inquiry || $inquiry['Status'] !== 'Pending') {
                    $errors[] = 'This registration inquiry has already been reviewed.';
                } elseif ($decision === 'reject') {
                    $pdo->prepare("UPDATE ClinicRegistrationInquiry SET Status = 'Rejected' WHERE InquiryID = ?")->execute([$inquiryId]);
                    $flash = 'The clinic registration inquiry was declined.';
                } else {
                    $clinicStmt = $pdo->prepare('SELECT ClinicID FROM Clinic WHERE ClinicName = ? AND archived = 0 LIMIT 1');
                    $clinicStmt->execute([$inquiry['ClinicName']]);
                    $existingClinic = $clinicStmt->fetch();
                    if ($existingClinic) {
                        $newClinicId = (int) $existingClinic['ClinicID'];
                    } else {
                        $pdo->prepare('INSERT INTO Clinic (ClinicName, Address, ContactNumber) VALUES (?, ?, ?)')->execute([$inquiry['ClinicName'], $inquiry['City'], $inquiry['Phone']]);
                        $newClinicId = (int) $pdo->lastInsertId();
                    }
                    $pdo->prepare("UPDATE ClinicRegistrationInquiry SET Status = 'Approved' WHERE InquiryID = ?")->execute([$inquiryId]);
                    $flash = 'Clinic approved and added to the directory.';
                }
                if ($errors) {
                    $pdo->rollBack();
                } else {
                    $pdo->commit();
                    logActivity($pdo, $user['UserID'], $newClinicId, 'Reviewed clinic registration', $inquiry['ClinicName'] . ' — ' . ucfirst($decision) . 'd');
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Inquiry review failed: ' . $e->getMessage());
                $errors[] = 'We could not update this registration inquiry.';
            }
        }
    }
}

$stats = ['pending' => 0, 'clinics' => 0, 'patients' => 0, 'appointments' => 0, 'physicians' => 0, 'staff' => 0];
$commissionByPeriod = ['week_commission' => 0, 'week_count' => 0, 'month_commission' => 0, 'month_count' => 0, 'year_commission' => 0, 'year_count' => 0];
$inquiries = [];
$recentAppointments = [];
$reviewedInquiries = [];
if ($pdo) {
    try {
        $stats['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM ClinicRegistrationInquiry WHERE Status = 'Pending'")->fetchColumn();
        $stats['clinics'] = (int) $pdo->query("SELECT COUNT(*) FROM Clinic WHERE archived = 0 AND Status = 'Active'")->fetchColumn();
        $stats['patients'] = (int) $pdo->query("SELECT COUNT(*) FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE r.RoleName = 'Patient' AND u.DeletedAt IS NULL")->fetchColumn();
        $stats['appointments'] = (int) $pdo->query("SELECT COUNT(*) FROM Appointments WHERE Status = 'Pending'")->fetchColumn();
        $stats['physicians'] = (int) $pdo->query("SELECT COUNT(*) FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE r.RoleName = 'Physician' AND u.DeletedAt IS NULL")->fetchColumn();
        $stats['staff'] = (int) $pdo->query("SELECT COUNT(*) FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE r.RoleName = 'Staff' AND u.DeletedAt IS NULL")->fetchColumn();
        // Same period bucketing as admin/revenue.php: YEARWEEK(..., 1) is Monday-start so "this week" lines up with a normal calendar week.
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
        $inquiries = $pdo->query("SELECT InquiryID, ClinicName, ContactName, Email, Phone, City, PhysiciansCount, SubmittedAt FROM ClinicRegistrationInquiry WHERE Status = 'Pending' ORDER BY SubmittedAt ASC LIMIT 10")->fetchAll();
        $recentAppointments = $pdo->query("SELECT a.AppointmentDate, a.AppointmentTime, a.Status, c.ClinicName, u.FirstName, u.LastName FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID JOIN Users u ON u.UserID = a.PatientID ORDER BY a.CreatedAt DESC LIMIT 10")->fetchAll();
        $reviewedInquiries = $pdo->query("SELECT ClinicName, Status, SubmittedAt FROM ClinicRegistrationInquiry WHERE Status <> 'Pending' ORDER BY SubmittedAt DESC LIMIT 5")->fetchAll();
    } catch (PDOException $e) {
        error_log('Admin dashboard read failed: ' . $e->getMessage());
        $errors[] = 'Some dashboard information is temporarily unavailable.';
    }
}
$pageTitle = 'Admin Dashboard — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell"><div class="container"><section class="admin-hero"><div><span class="eyebrow">System administrator</span><h1>Welcome back, <?= htmlspecialchars($user['FirstName']) ?>.</h1><p>Review clinic registrations and keep the platform running smoothly.</p></div><a href="<?= HQ_BASE_URL ?>/admin/announcements.php" class="btn btn-primary">Post announcement</a></section>
<?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?><?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="admin-stats"><article><span>Pending clinic reviews</span><strong><?= $stats['pending'] ?></strong></article><article><span>Active clinics</span><strong><?= $stats['clinics'] ?></strong></article><article><span>Patient accounts</span><strong><?= $stats['patients'] ?></strong></article><article><span>Pending appointments</span><strong><?= $stats['appointments'] ?></strong></article></section>

<section class="admin-section"><div class="portal-heading"><div><span class="section-kicker">Platform commission</span><h2>HealthQueue's 10% share</h2></div><a href="<?= HQ_BASE_URL ?>/admin/revenue.php" class="text-link">Full revenue report <span>&rarr;</span></a></div><section class="period-stats"><article><span>This week</span><strong>PHP <?= number_format((float) $commissionByPeriod['week_commission'], 2) ?></strong><span class="unassigned"><?= (int) $commissionByPeriod['week_count'] ?> paid booking(s)</span></article><article><span>This month</span><strong>PHP <?= number_format((float) $commissionByPeriod['month_commission'], 2) ?></strong><span class="unassigned"><?= (int) $commissionByPeriod['month_count'] ?> paid booking(s)</span></article><article><span>This year</span><strong>PHP <?= number_format((float) $commissionByPeriod['year_commission'], 2) ?></strong><span class="unassigned"><?= (int) $commissionByPeriod['year_count'] ?> paid booking(s)</span></article></section></section>

<section class="admin-section"><div class="portal-heading"><div><span class="section-kicker">Clinic onboarding</span><h2>Pending registration inquiries</h2></div></div><?php if ($inquiries): ?><div class="inquiry-list"><?php foreach ($inquiries as $inquiry): ?><article class="inquiry-card"><div><h3><?= htmlspecialchars($inquiry['ClinicName']) ?></h3><p><?= htmlspecialchars($inquiry['City']) ?> · <?= htmlspecialchars($inquiry['PhysiciansCount'] ?: 'Physician count not specified') ?></p><span><?= htmlspecialchars($inquiry['ContactName']) ?> · <?= htmlspecialchars($inquiry['Email']) ?> · <?= htmlspecialchars($inquiry['Phone']) ?></span></div><form method="post" class="inquiry-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="review_inquiry"><input type="hidden" name="inquiry_id" value="<?= (int) $inquiry['InquiryID'] ?>"><button class="btn btn-primary btn-sm" name="decision" value="approve">Approve</button><button class="btn btn-outline btn-sm" name="decision" value="reject">Decline</button></form></article><?php endforeach; ?></div><?php else: ?><div class="empty-state"><div class="empty-icon">✓</div><h3>All caught up</h3><p>There are no clinic registration inquiries waiting for review.</p></div><?php endif; ?></section>

<section class="admin-section">
  <div class="portal-heading"><div><span class="section-kicker">Manage</span><h2>Clinics &amp; team</h2></div></div>
  <div class="admin-overview-grid">
    <a href="<?= HQ_BASE_URL ?>/admin/clinics.php" class="compact-list" style="text-decoration:none;"><article><div><strong>Clinic directory</strong><span><?= $stats['clinics'] ?> active clinic(s) &middot; add, edit, or archive</span></div><span class="text-link">Manage <span>&rarr;</span></span></article></a>
    <a href="<?= HQ_BASE_URL ?>/admin/physicians.php" class="compact-list" style="text-decoration:none;"><article><div><strong>Physicians</strong><span><?= $stats['physicians'] ?> account(s)</span></div><span class="text-link">Manage <span>&rarr;</span></span></article></a>
    <a href="<?= HQ_BASE_URL ?>/admin/staff.php" class="compact-list" style="text-decoration:none;"><article><div><strong>Front-desk staff</strong><span><?= $stats['staff'] ?> account(s)</span></div><span class="text-link">Manage <span>&rarr;</span></span></article></a>
    <a href="<?= HQ_BASE_URL ?>/admin/activity-log.php" class="compact-list" style="text-decoration:none;"><article><div><strong>Activity log</strong><span>Recent administrative actions</span></div><span class="text-link">View <span>&rarr;</span></span></article></a>
  </div>
</section>

<section class="admin-section admin-overview-grid" id="appointment-overview"><div><div class="portal-heading"><div><span class="section-kicker">Appointment oversight</span><h2>Recent requests</h2></div></div><div class="compact-list"><?php if ($recentAppointments): ?><?php foreach ($recentAppointments as $appointment): ?><article><div><strong><?= htmlspecialchars($appointment['FirstName'] . ' ' . $appointment['LastName']) ?></strong><span><?= htmlspecialchars($appointment['ClinicName']) ?> &middot; <?= htmlspecialchars(date('M j, Y g:i A', strtotime($appointment['AppointmentDate'] . ' ' . $appointment['AppointmentTime']))) ?></span></div><span class="status-badge status-<?= strtolower(htmlspecialchars($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span></article><?php endforeach; ?><?php else: ?><p class="admin-empty">No appointment requests yet.</p><?php endif; ?></div></div><div><div class="portal-heading"><div><span class="section-kicker">Registration history</span><h2>Recently reviewed</h2></div></div><div class="compact-list"><?php if ($reviewedInquiries): ?><?php foreach ($reviewedInquiries as $inquiry): ?><article><div><strong><?= htmlspecialchars($inquiry['ClinicName']) ?></strong><span>Submitted <?= htmlspecialchars(date('M j, Y', strtotime($inquiry['SubmittedAt']))) ?></span></div><span class="status-badge status-<?= strtolower(htmlspecialchars($inquiry['Status'])) ?>"><?= htmlspecialchars($inquiry['Status']) ?></span></article><?php endforeach; ?><?php else: ?><p class="admin-empty">No reviewed registrations yet.</p><?php endif; ?></div></div></section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
