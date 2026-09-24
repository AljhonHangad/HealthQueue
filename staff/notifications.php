<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$clinicId = (int) $user['ClinicID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $formType = $_POST['form_type'] ?? '';

        if ($formType === 'mark_all_read') {
            try {
                $pdo->prepare('UPDATE Notifications SET IsRead = 1 WHERE ClinicID = ? AND IsRead = 0')->execute([$clinicId]);
                logActivity($pdo, $user['UserID'], $clinicId, 'Marked all notifications read', null);
                $flash = 'All notifications marked as read.';
            } catch (PDOException $e) {
                error_log('Mark all read failed: ' . $e->getMessage());
                $errors[] = 'We could not update your notifications.';
            }
        } elseif ($formType === 'delete_selected') {
            $ids = array_filter(array_map('intval', (array) ($_POST['notification_ids'] ?? [])));
            if (!$ids) {
                $errors[] = 'Select at least one notification to delete.';
            } else {
                try {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("DELETE FROM Notifications WHERE ClinicID = ? AND NotificationID IN ({$placeholders})");
                    $stmt->execute([$clinicId, ...$ids]);
                    logActivity($pdo, $user['UserID'], $clinicId, 'Deleted notifications', count($ids) . ' notification(s)');
                    $flash = count($ids) . ' notification(s) deleted.';
                } catch (PDOException $e) {
                    error_log('Delete selected notifications failed: ' . $e->getMessage());
                    $errors[] = 'We could not delete these notifications.';
                }
            }
        } elseif ($formType === 'delete_all') {
            try {
                $pdo->prepare('DELETE FROM Notifications WHERE ClinicID = ?')->execute([$clinicId]);
                logActivity($pdo, $user['UserID'], $clinicId, 'Deleted all notifications', null);
                $flash = 'All notifications deleted.';
            } catch (PDOException $e) {
                error_log('Delete all notifications failed: ' . $e->getMessage());
                $errors[] = 'We could not delete your notifications.';
            }
        }
    }
}

$notifications = [];
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $stmt = $pdo->prepare('SELECT NotificationID, Message, IsRead, RelatedAppointmentID, CreatedAt FROM Notifications WHERE ClinicID = ? ORDER BY CreatedAt DESC LIMIT 50');
        $stmt->execute([$clinicId]);
        $notifications = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Notifications load failed: ' . $e->getMessage());
        $dataError = 'Notifications are temporarily unavailable.';
    }
}

$pageTitle = 'Notifications — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Front desk</span><h1>Notifications</h1><p>Shared across your clinic's team — anyone marking these read or deleting them changes it for everyone.</p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <?php if ($notifications): ?>
      <p class="admin-empty" style="text-align:left;margin-bottom:14px;">Tap a notification to read the full message. Press and hold one to select it for deletion.</p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
          <button type="submit" name="form_type" value="mark_all_read" class="btn btn-primary btn-sm">Mark All Read</button>
          <button type="submit" name="form_type" value="delete_all" class="btn btn-outline btn-sm" onclick="return confirm('Delete ALL notifications? This cannot be undone.');">Delete All</button>
        </div>
        <div class="notification-selection-bar" hidden>
          <span class="notification-selection-count">0</span> selected
          <button type="button" class="btn btn-outline btn-sm notification-cancel-selection" style="border-color:rgba(255,255,255,.3);color:#fff;">Cancel</button>
          <button type="button" class="btn btn-primary btn-sm notification-delete-selected">Delete Selected</button>
        </div>
        <div class="compact-list notification-list">
          <?php foreach ($notifications as $note): ?>
            <article class="notification-row" data-id="<?= (int) $note['NotificationID'] ?>" style="<?= $note['IsRead'] ? 'opacity:.6;' : '' ?>">
              <div style="min-width:0;flex:1;">
                <strong class="notif-message"><?= htmlspecialchars($note['Message']) ?></strong>
                <span><?= htmlspecialchars(date('M j, Y g:i A', strtotime($note['CreatedAt']))) ?></span>
              </div>
              <?php if (!$note['IsRead']): ?><span class="status-badge">Unread</span><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </form>
    <?php else: ?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>No notifications yet</h3><p>New appointment requests for your clinic will show up here.</p></div>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
