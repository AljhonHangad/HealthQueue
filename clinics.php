<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

define('HQ_BASE_URL', '/healthqueue');

$pdo = getDbConnection();
$search = trim((string) ($_GET['q'] ?? ''));
$clinics = [];
$dataError = null;

if ($pdo) {
    try {
        $sql = "SELECT ClinicID, ClinicName, Address, BaseConsultationFee, PhotoUrl FROM Clinic WHERE archived = 0 AND Status = 'Active'";
        $params = [];
        if ($search !== '') {
            $sql .= ' AND (ClinicName LIKE ? OR Address LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $sql .= ' ORDER BY ClinicName';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clinics = $stmt->fetchAll();

        foreach ($clinics as &$clinic) {
            $ratingStmt = $pdo->prepare('SELECT COALESCE(AVG(Rating), 0) AS avg_rating, COUNT(*) AS review_count FROM Feedback WHERE ClinicID = ?');
            $ratingStmt->execute([$clinic['ClinicID']]);
            $rating = $ratingStmt->fetch();
            $clinic['AvgRating'] = (float) $rating['avg_rating'];
            $clinic['ReviewCount'] = (int) $rating['review_count'];
        }
        unset($clinic);
    } catch (PDOException $e) {
        error_log('Public clinics browse failed: ' . $e->getMessage());
        $dataError = 'Clinics are temporarily unavailable.';
    }
}

$pageTitle = 'All Clinics — HealthQueue';
require __DIR__ . '/includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <a href="<?= HQ_BASE_URL ?>/index.php#clinics" class="back-link">&larr; Back to home</a>

  <section class="portal-hero" style="margin-top:18px;"><div><span class="eyebrow">Care near you</span><h1>All Clinics</h1><p>Every partner clinic currently active on HealthQueue in Cebu City.</p></div></section>

  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <form method="get" class="admin-search" style="max-width:420px;margin-bottom:20px;">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by clinic name or address...">
      <button type="submit" class="btn btn-outline btn-sm">Search</button>
      <?php if ($search !== ''): ?><a href="<?= HQ_BASE_URL ?>/clinics.php" class="text-link">Clear</a><?php endif; ?>
    </form>

    <?php if ($clinics): ?>
      <div class="portal-clinics">
        <?php foreach ($clinics as $clinic): ?>
          <article class="portal-clinic-card">
            <?php if (!empty($clinic['PhotoUrl'])): ?>
              <img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="" class="portal-clinic-photo">
            <?php else: ?>
              <div class="portal-clinic-mark">+</div>
            <?php endif; ?>
            <h3><?= htmlspecialchars($clinic['ClinicName']) ?></h3>
            <p><?= htmlspecialchars($clinic['Address']) ?></p>
            <?php if ($clinic['ReviewCount'] > 0): ?>
              <p style="color:#f59e0b;font-weight:700;margin:2px 0 8px;font-size:13px;">★ <?= number_format($clinic['AvgRating'], 1) ?> <span style="color:var(--slate-400);font-weight:400;">(<?= $clinic['ReviewCount'] ?>)</span></p>
            <?php endif; ?>
            <div>
              <span>From PHP <?= number_format((float) $clinic['BaseConsultationFee'], 2) ?></span>
              <a href="<?= HQ_BASE_URL ?>/clinic-profile.php?clinic_id=<?= (int) $clinic['ClinicID'] ?>">View details</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No clinics match your search.</p>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/includes/footer.php'; ?>
