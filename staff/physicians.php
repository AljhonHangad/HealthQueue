<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

$user = currentUser();
$pdo = getDbConnection();
$clinicId = (int) $user['ClinicID'];
$selectedPhysician = filter_input(INPUT_GET, 'physician_id', FILTER_VALIDATE_INT) ?: null;
$physicians = [];
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $sql = "SELECT UserID, FirstName, LastName, AvailabilityStatus FROM Users
                WHERE ClinicID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'";
        $params = [$clinicId];
        if ($selectedPhysician) {
            $sql .= ' AND UserID = ?';
            $params[] = $selectedPhysician;
        }
        $sql .= ' ORDER BY LastName';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $physicians = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Physicians list load failed: ' . $e->getMessage());
        $dataError = 'Physician availability is temporarily unavailable.';
    }
}

$allPhysicians = [];
if ($pdo && $clinicId) {
    try {
        $allPhysicians = $pdo->query(
            "SELECT UserID, FirstName, LastName FROM Users
             WHERE ClinicID = {$clinicId} AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
             ORDER BY LastName"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Physicians filter list load failed: ' . $e->getMessage());
    }
}

$hqStatusDot = ['Available' => '#22c55e', 'On Break' => '#0077b3', 'Unavailable' => '#94a3b8'];

$pageTitle = 'Physicians — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Team</span><h1>Physicians</h1><p>Live availability for your clinic's physicians.</p></div></section>

  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <form method="get" style="max-width:320px;margin-bottom:18px;">
      <div class="form-stack">
        <label>Filter by Physician
          <select name="physician_id" onchange="this.form.submit()">
            <option value="">All physicians</option>
            <?php foreach ($allPhysicians as $physician): ?>
              <option value="<?= (int) $physician['UserID'] ?>" <?= $selectedPhysician === (int) $physician['UserID'] ? 'selected' : '' ?>>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </form>

    <?php if ($physicians): ?>
      <div class="compact-list">
        <?php foreach ($physicians as $physician): ?>
          <article>
            <strong>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></strong>
            <span style="display:inline-flex;align-items:center;gap:8px;">
              <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= $hqStatusDot[$physician['AvailabilityStatus']] ?? '#94a3b8' ?>;"></span>
              <?= htmlspecialchars($physician['AvailabilityStatus']) ?>
            </span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No physicians match this filter.</p>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
