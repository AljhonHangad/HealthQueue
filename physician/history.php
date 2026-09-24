<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

$user = currentUser();
$pdo = getDbConnection();
$consultations = [];
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, c.ClinicName,
                    pat.FirstName, pat.LastName, cons.CompletedAt
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Consultations cons ON cons.AppointmentID = a.AppointmentID
             WHERE a.PhysicianID = ? AND a.Status = 'Completed'
             ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC
             LIMIT 50"
        );
        $stmt->execute([$user['UserID']]);
        $consultations = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Consultation history load failed: ' . $e->getMessage());
        $dataError = 'Your consultation history is temporarily unavailable.';
    }
}

$pageTitle = 'Consultation History — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Records</span><h1>Consultation history</h1><p>Every completed visit you've handled, with a link back to its clinical notes.</p></div></section>

  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <?php if ($consultations): ?>
      <div class="compact-list">
        <?php foreach ($consultations as $item): ?>
          <article>
            <div>
              <strong><?= htmlspecialchars($item['FirstName'] . ' ' . $item['LastName']) ?></strong>
              <span><?= htmlspecialchars($item['ClinicName']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($item['AppointmentDate']))) ?></span>
            </div>
            <div style="display:flex;gap:14px;align-items:center;">
              <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $item['AppointmentID'] ?>" class="text-link">View notes <span>&rarr;</span></a>
              <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $item['AppointmentID'] ?>#ai-transcriber" class="text-link">AI transcriber <span>&rarr;</span></a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>No completed consultations yet</h3><p>Once you complete an appointment and add notes, it'll show up here.</p></div>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
