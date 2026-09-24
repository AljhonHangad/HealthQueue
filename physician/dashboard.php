<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/ai-transcription.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'update_appointment_status') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
        $newStatus = $_POST['status'] ?? '';
        if (!$appointmentId || !in_array($newStatus, ['Cancelled', 'NoShow'], true)) {
            $errors[] = 'Invalid appointment update.';
        } else {
            try {
                // Scoped to this physician's own UserID so one physician can never touch another's appointment.
                $stmt = $pdo->prepare('UPDATE Appointments SET Status = ? WHERE AppointmentID = ? AND PhysicianID = ?');
                $stmt->execute([$newStatus, $appointmentId, $user['UserID']]);
                if ($stmt->rowCount()) {
                    logActivity($pdo, $user['UserID'], $user['ClinicID'], 'Updated appointment status', "Appointment #{$appointmentId} set to {$newStatus}.");
                    $flash = 'Appointment updated.';
                } else {
                    $errors[] = 'That appointment could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Appointment status update failed: ' . $e->getMessage());
                $errors[] = 'We could not update this appointment.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'toggle_availability') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($pdo) {
        try {
            $currentStmt = $pdo->prepare('SELECT AvailabilityStatus FROM Users WHERE UserID = ?');
            $currentStmt->execute([$user['UserID']]);
            $availabilityCycle = ['Available' => 'On Break', 'On Break' => 'Unavailable', 'Unavailable' => 'Available'];
            $newAvailability = $availabilityCycle[$currentStmt->fetchColumn()] ?? 'Available';
            $pdo->prepare('UPDATE Users SET AvailabilityStatus = ? WHERE UserID = ?')->execute([$newAvailability, $user['UserID']]);
            logActivity($pdo, $user['UserID'], $user['ClinicID'], 'Updated availability status', "Set to {$newAvailability}.");
            $flash = "You're now marked as {$newAvailability}.";
        } catch (PDOException $e) {
            error_log('Availability toggle failed: ' . $e->getMessage());
            $errors[] = 'We could not update your availability status.';
        }
    }
}

$stats = ['earnings' => 0, 'today_earnings' => 0, 'paid_count' => 0, 'upcoming' => 0, 'total' => 0];
$upcoming = [];
$recentEarnings = [];
$nextPatient = null;
$availabilityStatus = 'Available';
$dataError = null;

if ($pdo) {
    try {
        $availabilityStmt = $pdo->prepare('SELECT AvailabilityStatus FROM Users WHERE UserID = ?');
        $availabilityStmt->execute([$user['UserID']]);
        $availabilityStatus = $availabilityStmt->fetchColumn() ?: 'Available';

        $earningsStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(p.PhysicianRevenue), 0) AS total, COUNT(*) AS paid_count
             FROM AppointmentPayments p
             JOIN Appointments a ON a.AppointmentID = p.AppointmentID
             WHERE a.PhysicianID = ? AND p.PaymentStatus = 'Paid'"
        );
        $earningsStmt->execute([$user['UserID']]);
        $earningsRow = $earningsStmt->fetch();
        $stats['earnings'] = (float) $earningsRow['total'];
        $stats['paid_count'] = (int) $earningsRow['paid_count'];

        $todayEarningsStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(p.PhysicianRevenue), 0)
             FROM AppointmentPayments p
             JOIN Appointments a ON a.AppointmentID = p.AppointmentID
             WHERE a.PhysicianID = ? AND p.PaymentStatus = 'Paid' AND DATE(p.PaidAt) = CURDATE()"
        );
        $todayEarningsStmt->execute([$user['UserID']]);
        $stats['today_earnings'] = (float) $todayEarningsStmt->fetchColumn();

        $upcomingCountStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM Appointments WHERE PhysicianID = ? AND AppointmentDate >= CURDATE() AND Status NOT IN ('Cancelled', 'NoShow')"
        );
        $upcomingCountStmt->execute([$user['UserID']]);
        $stats['upcoming'] = (int) $upcomingCountStmt->fetchColumn();

        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM Appointments WHERE PhysicianID = ?');
        $totalStmt->execute([$user['UserID']]);
        $stats['total'] = (int) $totalStmt->fetchColumn();

        $upcomingStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Status, a.Concern,
                    c.ClinicName, pat.FirstName, pat.LastName
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users pat ON pat.UserID = a.PatientID
             WHERE a.PhysicianID = ? AND a.AppointmentDate >= CURDATE()
             ORDER BY a.AppointmentDate, a.AppointmentTime
             LIMIT 8"
        );
        $upcomingStmt->execute([$user['UserID']]);
        $upcoming = $upcomingStmt->fetchAll();

        // The soonest Confirmed visit is what "Call Next Patient" jumps straight into.
        foreach ($upcoming as $candidate) {
            if ($candidate['Status'] === 'Confirmed') {
                $nextPatient = $candidate;
                break;
            }
        }

        $earningsListStmt = $pdo->prepare(
            "SELECT p.PhysicianRevenue, p.PaidAt, c.ClinicName, pat.FirstName, pat.LastName
             FROM AppointmentPayments p
             JOIN Appointments a ON a.AppointmentID = p.AppointmentID
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users pat ON pat.UserID = a.PatientID
             WHERE a.PhysicianID = ? AND p.PaymentStatus = 'Paid'
             ORDER BY p.PaidAt DESC
             LIMIT 8"
        );
        $earningsListStmt->execute([$user['UserID']]);
        $recentEarnings = $earningsListStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Physician dashboard failed: ' . $e->getMessage());
        $dataError = 'Your appointment and earnings information is temporarily unavailable.';
    }
} else {
    $dataError = 'Your appointment and earnings information is temporarily unavailable.';
}

$pageTitle = 'Physician Dashboard — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero">
    <div>
      <span class="eyebrow">Physician portal</span>
      <h1>Welcome, Dr. <?= htmlspecialchars($user['LastName']) ?>.</h1>
      <p>Your assigned appointments and the revenue you've earned through HealthQueue, in one place.</p>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:12px;">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="form_type" value="toggle_availability">
        <?php $hqAvailabilityDot = ['Available' => '#22c55e', 'On Break' => '#0077b3', 'Unavailable' => '#94a3b8'][$availabilityStatus] ?? '#94a3b8'; ?>
        <button type="submit" class="btn btn-sm <?= $availabilityStatus === 'Available' ? 'btn-primary' : 'btn-outline' ?>" title="Click to cycle: Available &rarr; On Break &rarr; Unavailable">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $hqAvailabilityDot ?>;"></span>
          <?= htmlspecialchars($availabilityStatus) ?>
        </button>
      </form>
      <?php if ($nextPatient): ?>
        <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $nextPatient['AppointmentID'] ?>" class="btn btn-primary btn-sm">Call Next Patient</a>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section" style="margin-top:28px;">
    <div class="portal-heading"><div><span class="section-kicker">Revenue</span><h2>Your earnings</h2></div></div>
    <div class="admin-stats">
      <article><span>Today's earnings</span><strong class="money">PHP <?= number_format($stats['today_earnings'], 2) ?></strong></article>
      <article><span>Total earnings (90% share)</span><strong class="money">PHP <?= number_format($stats['earnings'], 2) ?></strong></article>
    </div>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Activity</span><h2>Appointment overview</h2></div></div>
    <div class="admin-stats">
      <article><span>Paid consultations</span><strong><?= $stats['paid_count'] ?></strong></article>
      <article><span>Upcoming appointments</span><strong><?= $stats['upcoming'] ?></strong></article>
      <article><span>Total appointments assigned</span><strong><?= $stats['total'] ?></strong></article>
    </div>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">My schedule</span><h2>Upcoming appointments</h2></div></div>
    <?php if ($upcoming): ?>
      <div class="appointment-list">
        <?php foreach ($upcoming as $appointment): ?>
          <article class="appointment-card">
            <div class="appointment-date">
              <strong><?= htmlspecialchars(date('d', strtotime($appointment['AppointmentDate']))) ?></strong>
              <span><?= htmlspecialchars(date('M', strtotime($appointment['AppointmentDate']))) ?></span>
            </div>
            <div class="appointment-info">
              <h3><?= htmlspecialchars($appointment['FirstName'] . ' ' . $appointment['LastName']) ?></h3>
              <p><?= htmlspecialchars(date('l, F j', strtotime($appointment['AppointmentDate']))) ?> &middot; <?= htmlspecialchars(date('g:i A', strtotime($appointment['AppointmentTime']))) ?></p>
              <span><?= htmlspecialchars($appointment['ClinicName']) ?><?= $appointment['Concern'] ? ' — ' . htmlspecialchars($appointment['Concern']) : '' ?></span>
              <?php if ($appointment['Concern']): ?>
                <button type="button" class="btn btn-ghost btn-sm view-concern-btn" data-target="concern-<?= (int) $appointment['AppointmentID'] ?>" style="padding-left:0;">View Concern Summary</button>
                <div id="concern-<?= (int) $appointment['AppointmentID'] ?>" class="dev-note" style="display:none;margin-top:8px;">
                  <strong>AI pre-consult summary (stubbed):</strong> <?= htmlspecialchars(summarizeTranscript($appointment['Concern'])) ?>
                </div>
              <?php endif; ?>
            </div>
            <span class="status-badge status-<?= strtolower(htmlspecialchars($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span>
            <?php if (in_array($appointment['Status'], ['Pending', 'Confirmed'], true)): ?>
              <div style="display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;">
                <?php if ($appointment['Status'] === 'Pending'): ?>
                  <span class="admin-empty" style="text-align:left;">Awaiting front-desk confirmation</span>
                <?php else: ?>
                  <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $appointment['AppointmentID'] ?>" class="btn btn-primary btn-sm">Open consultation</a>
                  <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $appointment['AppointmentID'] ?>#ai-transcriber" class="btn btn-outline btn-sm">AI transcriber</a>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_appointment_status"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['AppointmentID'] ?>"><input type="hidden" name="status" value="NoShow"><button class="btn btn-outline btn-sm" onclick="return confirm('Mark this patient as a no-show?');">Mark No-show</button></form>
                <?php endif; ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_appointment_status"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['AppointmentID'] ?>"><input type="hidden" name="status" value="Cancelled"><button class="btn btn-outline btn-sm" onclick="return confirm('Cancel this appointment?');">Cancel</button></form>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>No upcoming appointments</h3><p>New requests assigned to you by clinic staff will appear here.</p></div>
    <?php endif; ?>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Earnings</span><h2>Recent payments</h2></div></div>
    <?php if ($recentEarnings): ?>
      <div class="compact-list">
        <?php foreach ($recentEarnings as $earning): ?>
          <article>
            <div>
              <strong><?= htmlspecialchars($earning['FirstName'] . ' ' . $earning['LastName']) ?></strong>
              <span><?= htmlspecialchars($earning['ClinicName']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($earning['PaidAt']))) ?></span>
            </div>
            <strong class="money">PHP <?= number_format((float) $earning['PhysicianRevenue'], 2) ?></strong>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No payments have been recorded for your consultations yet.</p>
    <?php endif; ?>
  </section>
</div></main>

<script>
(function () {
  document.querySelectorAll('.view-concern-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var panel = document.getElementById(btn.getAttribute('data-target'));
      if (!panel) return;
      panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    });
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
