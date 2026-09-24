<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$announcements = [];
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->query(
            "SELECT a.Title, a.Message, a.Audience, a.CreatedAt, u.FirstName, u.LastName
             FROM Announcements a
             JOIN Users u ON u.UserID = a.PostedByUserID
             WHERE a.Audience IN ('Everyone', 'Patients')
             ORDER BY a.CreatedAt DESC
             LIMIT 50"
        );
        $announcements = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Patient announcements load failed: ' . $e->getMessage());
        $dataError = 'Announcements are temporarily unavailable.';
    }
}

$pageTitle = 'Announcements — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Updates</span><h1>Clinic Announcements</h1><p>News and advisories from HealthQueue and partner clinics.</p></div></section>

  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <?php if ($announcements): ?>
      <div class="compact-list">
        <?php foreach ($announcements as $note): ?>
          <article style="align-items:flex-start;flex-direction:column;gap:6px;">
            <div style="display:flex;justify-content:space-between;width:100%;">
              <strong><?= htmlspecialchars($note['Title']) ?></strong>
              <span style="color:var(--slate-400);font-size:12px;"><?= htmlspecialchars(date('M j, Y', strtotime($note['CreatedAt']))) ?></span>
            </div>
            <p style="margin:0;color:var(--slate-600);font-size:13.5px;white-space:pre-line;"><?= htmlspecialchars($note['Message']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>No announcements yet</h3><p>Updates from HealthQueue and your clinics will appear here.</p></div>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
