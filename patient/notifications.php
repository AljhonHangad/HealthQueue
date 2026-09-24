<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $formType = $_POST['form_type'] ?? '';

        if ($formType === 'mark_all_read') {
            try {
                $pdo->prepare('UPDATE PatientNotifications SET IsRead = 1 WHERE PatientID = ? AND IsRead = 0')->execute([$user['UserID']]);
                $flash = 'All notifications marked as read.';
            } catch (PDOException $e) {
                error_log('Patient mark all read failed: ' . $e->getMessage());
                $errors[] = 'We could not update your notifications.';
            }
        } elseif ($formType === 'delete_selected') {
            $ids = array_filter(array_map('intval', (array) ($_POST['notification_ids'] ?? [])));
            if (!$ids) {
                $errors[] = 'Select at least one notification to delete.';
            } else {
                try {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("DELETE FROM PatientNotifications WHERE PatientID = ? AND NotificationID IN ({$placeholders})");
                    $stmt->execute([$user['UserID'], ...$ids]);
                    $flash = count($ids) . ' notification(s) deleted.';
                } catch (PDOException $e) {
                    error_log('Delete selected patient notifications failed: ' . $e->getMessage());
                    $errors[] = 'We could not delete these notifications.';
                }
            }
        } elseif ($formType === 'delete_all') {
            try {
                $pdo->prepare('DELETE FROM PatientNotifications WHERE PatientID = ?')->execute([$user['UserID']]);
                $flash = 'All notifications deleted.';
            } catch (PDOException $e) {
                error_log('Delete all patient notifications failed: ' . $e->getMessage());
                $errors[] = 'We could not delete your notifications.';
            }
        }
    }
}

$notifications = [];
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare('SELECT NotificationID, Message, IsRead, CreatedAt FROM PatientNotifications WHERE PatientID = ? ORDER BY CreatedAt DESC LIMIT 50');
        $stmt->execute([$user['UserID']]);
        $notifications = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Patient notifications load failed: ' . $e->getMessage());
        $dataError = 'Notifications are temporarily unavailable.';
    }
}

$pageTitle = 'Notifications — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Updates</span><h1>Notifications</h1><p>Booking confirmations, queue calls, and record updates.</p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <?php if ($notifications): ?>
      <p class="admin-empty" style="text-align:left;margin-bottom:14px;">Tap a notification to read the full message. Tick the ones you want to remove, then press Delete selected.</p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <div class="notif-toolbar">
          <label class="notif-select-all-wrap"><input type="checkbox" class="notif-select-all"> Select all</label>
          <button type="submit" name="form_type" value="delete_selected" class="btn btn-outline btn-sm notification-delete-selected ma-danger" disabled>Delete selected (<span class="notification-selection-count">0</span>)</button>
          <span class="notif-toolbar-spacer"></span>
          <button type="submit" name="form_type" value="mark_all_read" class="btn btn-primary btn-sm">Mark All Read</button>
          <button type="submit" name="form_type" value="delete_all" class="btn btn-outline btn-sm" onclick="return confirm('Delete ALL notifications? This cannot be undone.');">Delete All</button>
        </div>
        <div class="compact-list notification-list">
          <?php foreach ($notifications as $note): ?>
            <article class="notification-row<?= $note['IsRead'] ? ' is-read' : '' ?>" data-id="<?= (int) $note['NotificationID'] ?>">
              <label class="notif-check-wrap" aria-label="Select notification"><input type="checkbox" class="notif-check" name="notification_ids[]" value="<?= (int) $note['NotificationID'] ?>"></label>
              <div style="min-width:0;flex:1;">
                <strong class="notif-message"><?= htmlspecialchars($note['Message']) ?></strong>
                <span><?= htmlspecialchars(date('M j, Y g:i A', strtotime($note['CreatedAt']))) ?></span>
                <div class="notif-row-actions">
                  <button type="button" class="btn btn-outline btn-sm ma-danger" data-delete-id="<?= (int) $note['NotificationID'] ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Delete notification
                  </button>
                </div>
              </div>
              <?php if (!$note['IsRead']): ?><span class="status-badge">Unread</span><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </form>
    <?php else: ?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>No notifications yet</h3><p>Updates about your appointments and queue status will show up here.</p></div>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
