<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

$pdo = getDbConnection();
$errors = [];
$logs = [];

if ($pdo) {
    try {
        $logs = $pdo->query(
            "SELECT l.LogID, l.ActionExecuted, l.Description, l.CreatedAt, c.ClinicName,
                    u.FirstName, u.LastName
             FROM AuditLogs l
             LEFT JOIN Users u ON u.UserID = l.UserID
             LEFT JOIN Clinic c ON c.ClinicID = l.ClinicID
             ORDER BY l.CreatedAt DESC
             LIMIT 100"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Activity log fetch failed: ' . $e->getMessage());
        $errors[] = 'Activity log is temporarily unavailable.';
    }
}

$pageTitle = 'Activity Log — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell"><div class="container">
  <section class="admin-hero"><div><span class="eyebrow">Oversight</span><h1>Activity log</h1><p>The most recent administrative actions taken across the platform.</p></div></section>

  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="admin-section">
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Details</th><th>Clinic</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($log['CreatedAt']))) ?></td>
            <td><?= $log['FirstName'] ? htmlspecialchars($log['FirstName'] . ' ' . $log['LastName']) : '<span class="unassigned">System</span>' ?></td>
            <td><strong><?= htmlspecialchars($log['ActionExecuted']) ?></strong></td>
            <td><?= htmlspecialchars($log['Description'] ?? '') ?></td>
            <td><?= $log['ClinicName'] ? htmlspecialchars($log['ClinicName']) : '<span class="unassigned">&mdash;</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="5" class="admin-empty">No activity has been recorded yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
