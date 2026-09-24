<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$entries = [];
$dataError = null;
const MINUTES_PER_PATIENT = 15;

if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.Status, q.ClinicID, c.ClinicName,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Clinic c ON c.ClinicID = q.ClinicID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE a.PatientID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')
             ORDER BY q.CreatedAt DESC"
        );
        $stmt->execute([$user['UserID']]);
        $entries = $stmt->fetchAll();

        foreach ($entries as &$entry) {
            $posStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND Status = 'Waiting' AND QueueNumber < ?"
            );
            $posStmt->execute([$entry['ClinicID'], $entry['QueueNumber']]);
            $entry['AheadCount'] = (int) $posStmt->fetchColumn();
            $entry['EstimatedWaitMinutes'] = $entry['Status'] === 'Waiting' ? $entry['AheadCount'] * MINUTES_PER_PATIENT : 0;
        }
        unset($entry);
    } catch (PDOException $e) {
        error_log('Queue status load failed: ' . $e->getMessage());
        $dataError = 'Queue status is temporarily unavailable.';
    }
}

$pageTitle = 'Live Queue Status — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Right now</span><h1>Live Queue Status</h1><p>See your place in line, updated as the clinic calls each patient.</p></div></section>

  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <?php if ($entries): ?>
      <div class="compact-list">
        <?php foreach ($entries as $entry): ?>
          <article style="align-items:flex-start;flex-direction:column;gap:10px;">
            <div style="display:flex;justify-content:space-between;width:100%;flex-wrap:wrap;gap:10px;">
              <div>
                <strong>#<?= (int) $entry['QueueNumber'] ?> — <?= htmlspecialchars($entry['ClinicName']) ?></strong>
                <span style="display:block;color:var(--slate-500);font-size:13px;"><?= $entry['PhyFirstName'] ? 'Dr. ' . htmlspecialchars($entry['PhyFirstName'] . ' ' . $entry['PhyLastName']) : 'No physician preference' ?></span>
              </div>
              <span class="status-badge status-<?= strtolower($entry['Status']) ?>" style="font-size:14px;"><?= htmlspecialchars($entry['Status']) ?></span>
            </div>
            <?php if ($entry['Status'] === 'Waiting'): ?>
              <div class="admin-stats" style="width:100%;">
                <article><span>Patients ahead of you</span><strong><?= (int) $entry['AheadCount'] ?></strong></article>
                <article><span>Estimated wait</span><strong>~<?= (int) $entry['EstimatedWaitMinutes'] ?> min</strong></article>
              </div>
            <?php elseif ($entry['Status'] === 'Calling'): ?>
              <p class="form-message success" style="margin:0;width:100%;" role="status">You're being called now — please proceed to the counter.</p>
            <?php elseif ($entry['Status'] === 'Serving'): ?>
              <p class="admin-empty" style="text-align:left;">You're currently being served.</p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <p style="margin-top:14px;color:var(--slate-400);font-size:12.5px;">Estimated wait times are a rough estimate (~<?= MINUTES_PER_PATIENT ?> min per patient ahead of you), not a guarantee.</p>
    <?php else: ?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>You're not in a queue right now</h3><p>Once a clinic checks you in for a walk-in visit or a confirmed appointment today, your live position will appear here.</p></div>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
