<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$values = ['title' => '', 'message' => '', 'audience' => 'Everyone'];
$audiences = ['Everyone', 'Patients', 'Staff', 'Physicians'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $values['title'] = trim((string) ($_POST['title'] ?? ''));
        $values['message'] = trim((string) ($_POST['message'] ?? ''));
        $values['audience'] = (string) ($_POST['audience'] ?? 'Everyone');

        if ($values['title'] === '') $errors[] = 'Title is required.';
        if ($values['message'] === '') $errors[] = 'Message is required.';
        if (!in_array($values['audience'], $audiences, true)) $errors[] = 'Please choose a valid audience.';

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('INSERT INTO Announcements (PostedByUserID, Title, Message, Audience) VALUES (?, ?, ?, ?)');
                $stmt->execute([$user['UserID'], $values['title'], $values['message'], $values['audience']]);
                logActivity($pdo, $user['UserID'], null, 'Posted announcement', $values['title'] . ' (' . $values['audience'] . ')');
                header('Location: ' . HQ_BASE_URL . '/admin/announcements.php');
                exit;
            } catch (PDOException $e) {
                error_log('Announcement save failed: ' . $e->getMessage());
                $errors[] = 'We could not post this announcement.';
            }
        }
    }
}

$announcements = [];
if ($pdo) {
    try {
        $announcements = $pdo->query(
            "SELECT a.AnnouncementID, a.Title, a.Message, a.Audience, a.CreatedAt, u.FirstName, u.LastName
             FROM Announcements a JOIN Users u ON u.UserID = a.PostedByUserID
             ORDER BY a.CreatedAt DESC LIMIT 30"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Announcements list failed: ' . $e->getMessage());
        $errors[] = 'Announcement history is temporarily unavailable.';
    }
}

$pageTitle = 'Announcements — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell"><div class="container">
  <section class="admin-hero"><div><span class="eyebrow">Communication</span><h1>Announcements</h1><p>Post updates for patients, staff, or physicians across the platform.</p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="admin-section">
    <div class="portal-heading"><div><span class="section-kicker">New announcement</span><h2>Post an update</h2></div></div>
    <form class="registration-form" method="post" style="max-width:640px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <div class="form-stack">
        <label>Title<input name="title" value="<?= htmlspecialchars($values['title']) ?>" required></label>
        <label>Audience
          <select name="audience">
            <?php foreach ($audiences as $audience): ?>
              <option value="<?= htmlspecialchars($audience) ?>" <?= $values['audience'] === $audience ? 'selected' : '' ?>><?= htmlspecialchars($audience) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Message<textarea name="message" rows="4" required><?= htmlspecialchars($values['message']) ?></textarea></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Post announcement</button>
    </form>
  </section>

  <section class="admin-section">
    <div class="portal-heading"><div><span class="section-kicker">History</span><h2>Recent announcements</h2></div></div>
    <?php if ($announcements): ?>
      <div class="inquiry-list">
        <?php foreach ($announcements as $announcement): ?>
          <article class="inquiry-card">
            <div>
              <h3><?= htmlspecialchars($announcement['Title']) ?></h3>
              <p><?= nl2br(htmlspecialchars($announcement['Message'])) ?></p>
              <span>Posted by <?= htmlspecialchars($announcement['FirstName'] . ' ' . $announcement['LastName']) ?> &middot; <?= htmlspecialchars(date('M j, Y g:i A', strtotime($announcement['CreatedAt']))) ?></span>
            </div>
            <span class="status-badge status-active"><?= htmlspecialchars($announcement['Audience']) ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No announcements have been posted yet.</p>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
