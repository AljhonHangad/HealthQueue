<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

$user = currentUser();
$pdo = getDbConnection();
$stats = ['pending' => 0, 'today' => 0, 'in_queue' => 0, 'on_break' => 0, 'unavailable' => 0];
$dataError = null;

if ($pdo && $user['ClinicID']) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE ClinicID = ? AND Status = 'Pending'");
        $stmt->execute([$user['ClinicID']]);
        $stats['pending'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Appointments WHERE ClinicID = ? AND AppointmentDate = CURDATE()');
        $stmt->execute([$user['ClinicID']]);
        $stats['today'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND Status IN ('Waiting', 'Calling', 'Serving')");
        $stmt->execute([$user['ClinicID']]);
        $stats['in_queue'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM Users WHERE ClinicID = ? AND AvailabilityStatus = 'On Break'
             AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician')"
        );
        $stmt->execute([$user['ClinicID']]);
        $stats['on_break'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM Users WHERE ClinicID = ? AND AvailabilityStatus = 'Unavailable'
             AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician')"
        );
        $stmt->execute([$user['ClinicID']]);
        $stats['unavailable'] = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Staff dashboard failed: ' . $e->getMessage());
        $dataError = 'Some dashboard information is temporarily unavailable.';
    }
}

$pageTitle = 'Staff Dashboard — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero">
    <div>
      <span class="eyebrow">Staff Portal</span>
      <h1>Welcome, <?= htmlspecialchars($user['FirstName']) ?>.</h1>
      <p>Front-desk overview: incoming requests, today's queue, and physician availability, in one place.</p>
    </div>
    <a href="<?= HQ_BASE_URL ?>/staff/queue.php#walk-in" class="btn btn-primary">Register Walk-in Patient</a>
  </section>

  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="admin-stats" style="margin-top:28px;">
    <article><span>Pending Requests</span><strong><?= $stats['pending'] ?></strong></article>
    <article><span>Today's Appointments</span><strong><?= $stats['today'] ?></strong></article>
    <article><span>In Queue</span><strong><?= $stats['in_queue'] ?></strong></article>
    <article><span>On Break</span><strong><?= $stats['on_break'] ?></strong></article>
    <article><span>Unavailable</span><strong><?= $stats['unavailable'] ?></strong></article>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Quick links</span><h2>Where to go next</h2></div></div>
    <div class="admin-overview-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
      <a href="<?= HQ_BASE_URL ?>/staff/appointments.php" class="clinic-card" style="text-decoration:none;"><div class="clinic-body"><h3>Appointments</h3><p><?= $stats['pending'] ?> pending request(s) to review.</p></div></a>
      <a href="<?= HQ_BASE_URL ?>/staff/queue.php" class="clinic-card" style="text-decoration:none;"><div class="clinic-body"><h3>Queue Management</h3><p><?= $stats['in_queue'] ?> patient(s) currently in the queue.</p></div></a>
      <a href="<?= HQ_BASE_URL ?>/staff/physicians.php" class="clinic-card" style="text-decoration:none;"><div class="clinic-body"><h3>Physicians</h3><p>Live availability for your clinic's team.</p></div></a>
    </div>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
