<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

$user = currentUser();
$pdo = getDbConnection();
$appointments = [];
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, c.ClinicName,
                    pat.FirstName, pat.LastName,
                    (SELECT COUNT(*) FROM ConsultationVersions v
                     JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                     WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized') AS FinalizedCount
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users pat ON pat.UserID = a.PatientID
             WHERE a.PhysicianID = ? AND a.Status = 'Confirmed'
             ORDER BY a.AppointmentDate, a.AppointmentTime"
        );
        $stmt->execute([$user['UserID']]);
        $appointments = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Consultations list load failed: ' . $e->getMessage());
        $dataError = 'Your consultations list is temporarily unavailable.';
    }
}

$pageTitle = 'Consultations — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Active visits</span><h1>Consultations</h1><p>Confirmed appointments ready to start. Once you finalize notes, front-desk staff confirm the visit as complete and it moves to Consultation History.</p></div></section>

  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <?php if ($appointments): ?>
      <div class="compact-list">
        <?php foreach ($appointments as $item): ?>
          <article>
            <div>
              <strong><?= htmlspecialchars($item['FirstName'] . ' ' . $item['LastName']) ?></strong>
              <span><?= htmlspecialchars($item['ClinicName']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($item['AppointmentDate']))) ?> at <?= htmlspecialchars(date('g:i A', strtotime($item['AppointmentTime']))) ?><?= $item['Concern'] ? ' — ' . htmlspecialchars($item['Concern']) : '' ?></span>
              <?php if ((int) $item['FinalizedCount'] > 0): ?>
                <span class="status-badge status-finalized" style="width:fit-content;margin-top:6px;">Finalized — awaiting staff confirmation</span>
              <?php endif; ?>
            </div>
            <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $item['AppointmentID'] ?>" class="btn btn-primary btn-sm"><?= (int) $item['FinalizedCount'] > 0 ? 'View consultation' : 'Start consultation' ?></a>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>No confirmed appointments waiting</h3><p>Confirm a pending appointment from your dashboard to start a consultation.</p><a href="<?= HQ_BASE_URL ?>/physician/dashboard.php" class="btn btn-primary btn-sm">Go to dashboard</a></div>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
