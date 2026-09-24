<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/announcements.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

// Clinic staff post announcements for their own clinic only (closures,
// schedule changes, events). Platform-wide posts stay with admins.
$user = currentUser();
$pdo = getDbConnection();
$clinicId = (int) $user['ClinicID'];
$errors = [];
$flash = '';
$audiences = ['Patients' => 'Patients', 'Everyone' => 'Everyone (patients & team)'];
$values = ['title' => '', 'category' => 'General', 'audience' => 'Patients', 'affects_date' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo || !$clinicId) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif ($formType === 'post_announcement') {
        foreach ($values as $field => $value) $values[$field] = trim((string) ($_POST[$field] ?? ''));

        if ($values['title'] === '') $errors[] = 'Title is required.';
        if ($values['message'] === '') $errors[] = 'Message is required.';
        if (mb_strlen($values['title']) > 150) $errors[] = 'Title must be 150 characters or fewer.';
        if (!in_array($values['category'], ANNOUNCEMENT_CATEGORIES, true)) $errors[] = 'Please choose a valid category.';
        if (!isset($audiences[$values['audience']])) $errors[] = 'Please choose a valid audience.';
        if ($values['affects_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['affects_date'])) $errors[] = 'Please enter a valid affected date.';

        $photo = null;
        if (!$errors) {
            [$photo, $photoError] = saveAnnouncementPhoto($_FILES['photo'] ?? null);
            if ($photoError) $errors[] = $photoError;
        }

        if (!$errors) {
            try {
                $pdo->prepare(
                    'INSERT INTO Announcements (PostedByUserID, ClinicID, Title, Category, Message, PhotoPath, Audience, AffectsDate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$user['UserID'], $clinicId, $values['title'], $values['category'], $values['message'], $photo, $values['audience'], $values['affects_date'] ?: null]);
                logActivity($pdo, $user['UserID'], $clinicId, 'Posted announcement', $values['title']);
                $_SESSION['announcement_flash'] = 'Announcement posted.';
                header('Location: ' . HQ_BASE_URL . '/staff/announcements.php');
                exit;
            } catch (PDOException $e) {
                deleteAnnouncementPhoto($photo);
                error_log('Staff announcement save failed: ' . $e->getMessage());
                $errors[] = 'We could not post this announcement.';
            }
        }
    } elseif ($formType === 'delete_announcement') {
        $announcementId = filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
        try {
            // Only this clinic's own announcements can be deleted here.
            $stmt = $pdo->prepare('SELECT Title, PhotoPath FROM Announcements WHERE AnnouncementID = ? AND ClinicID = ?');
            $stmt->execute([$announcementId, $clinicId]);
            if ($row = $stmt->fetch()) {
                $pdo->prepare('DELETE FROM AnnouncementDismissals WHERE AnnouncementID = ?')->execute([$announcementId]);
                $pdo->prepare('DELETE FROM Announcements WHERE AnnouncementID = ? AND ClinicID = ?')->execute([$announcementId, $clinicId]);
                deleteAnnouncementPhoto($row['PhotoPath']);
                logActivity($pdo, $user['UserID'], $clinicId, 'Deleted announcement', $row['Title']);
                $flash = 'Announcement deleted.';
            } else {
                $errors[] = 'That announcement could not be found.';
            }
        } catch (PDOException $e) {
            error_log('Staff announcement delete failed: ' . $e->getMessage());
            $errors[] = 'We could not delete this announcement.';
        }
    }
}
if (!empty($_SESSION['announcement_flash'])) {
    $flash = $_SESSION['announcement_flash'];
    unset($_SESSION['announcement_flash']);
}

$announcements = [];
$clinicName = '';
if ($pdo && $clinicId) {
    try {
        $nameStmt = $pdo->prepare('SELECT ClinicName FROM Clinic WHERE ClinicID = ?');
        $nameStmt->execute([$clinicId]);
        $clinicName = (string) $nameStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT a.AnnouncementID, a.Title, a.Category, a.Message, a.PhotoPath, a.Audience, a.AffectsDate, a.CreatedAt, u.FirstName, u.LastName,
                    TIMESTAMPDIFF(MINUTE, a.CreatedAt, NOW()) AS MinutesAgo, DATEDIFF(CURDATE(), DATE(a.CreatedAt)) AS DaysAgo,
                    (a.AffectsDate IS NOT NULL AND a.AffectsDate >= CURDATE()) AS AffectsUpcoming
             FROM Announcements a JOIN Users u ON u.UserID = a.PostedByUserID
             WHERE a.ClinicID = ? ORDER BY a.CreatedAt DESC LIMIT 50"
        );
        $stmt->execute([$clinicId]);
        $announcements = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Staff announcements load failed: ' . $e->getMessage());
        $errors[] = 'Announcements are temporarily unavailable.';
    }
}

$categoryStyles = ['Closure' => 'an-pill-red', 'Schedule change' => 'an-pill-amber', 'Event' => 'an-pill-green', 'Health advisory' => 'an-pill-blue', 'General' => 'an-pill-slate'];
$weekCount = count(array_filter($announcements, static fn($n) => (int) $n['DaysAgo'] < 7));
$upcomingCount = count(array_filter($announcements, static fn($n) => (bool) $n['AffectsUpcoming']));
$reopenForm = $errors && ($_POST['form_type'] ?? '') === 'post_announcement';

// Group into Today / Earlier this week / Earlier (same as the patient page).
$groups = [];
foreach ($announcements as $note) {
    $days = (int) $note['DaysAgo'];
    $groups[$days === 0 ? 'Today' : ($days < 7 ? 'Earlier this week' : 'Earlier')][] = $note;
}
$timeAgo = static function (array $note): string {
    $minutes = (int) $note['MinutesAgo'];
    if ($minutes < 1) return 'Just now';
    if ($minutes < 60) return $minutes . ' min ago';
    if ($minutes < 1440) {
        $h = intdiv($minutes, 60);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    if ((int) $note['DaysAgo'] === 1) return 'Yesterday';
    return date('M j', strtotime($note['CreatedAt']));
};
$words = preg_split('/\s+/', trim($clinicName ?: 'Clinic'));
$clinicInitials = strtoupper(mb_substr($words[0] ?? '', 0, 1) . mb_substr($words[1] ?? '', 0, 1));

$pageTitle = 'Announcements — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container an-page">
  <section class="an-hero">
    <div>
      <span class="an-eyebrow">Front desk</span>
      <h1>Clinic Announcements</h1>
      <p>Closures, schedule changes, and events for patients<?= $clinicName ? ' of ' . htmlspecialchars($clinicName) : '' ?>.</p>
      <button type="button" class="btn sd-btn-call sn-new-btn" data-modal-open="snFormModal">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        New announcement
      </button>
    </div>
    <div class="an-hero-stats">
      <div><strong><?= count($announcements) ?></strong><span>Posted</span></div>
      <div><strong><?= $weekCount ?></strong><span>This week</span></div>
      <div class="an-hero-stat-alert"><strong><?= $upcomingCount ?></strong><span>Upcoming dates</span></div>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors && !$reopenForm): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <nav class="an-tabs" aria-label="Filter by category">
    <button type="button" class="an-tab is-active" data-category-filter="">All</button>
    <?php foreach (ANNOUNCEMENT_CATEGORIES as $category): ?>
      <button type="button" class="an-tab" data-category-filter="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></button>
    <?php endforeach; ?>
  </nav>

  <?php if ($groups): ?>
    <?php foreach ($groups as $label => $notes): ?>
      <div class="sn-group">
        <h3 class="an-group"><?= htmlspecialchars($label) ?></h3>
        <?php foreach ($notes as $note): ?>
          <?php $photoUrl = announcementPhotoUrl($note['PhotoPath']); ?>
          <article class="an-card is-collapsed" tabindex="0" role="button" aria-haspopup="dialog" data-sn-card
                   data-id="<?= (int) $note['AnnouncementID'] ?>"
                   data-category="<?= htmlspecialchars($note['Category']) ?>"
                   data-audience="<?= htmlspecialchars($note['Audience']) ?>"
                   data-author="<?= htmlspecialchars($note['FirstName'] . ' ' . $note['LastName']) ?>"
                   data-posted="<?= htmlspecialchars(date('l, F j, Y · g:i A', strtotime($note['CreatedAt']))) ?>"
                   data-affects="<?= $note['AffectsDate'] ? htmlspecialchars(date('l, F j, Y', strtotime($note['AffectsDate']))) : '' ?>"
                   data-photo="<?= htmlspecialchars((string) $photoUrl) ?>">
            <span class="an-avatar tint-<?= $clinicId % 5 ?>"><?= htmlspecialchars($clinicInitials) ?></span>
            <div class="an-body">
              <div class="an-title-row">
                <h4><?= htmlspecialchars($note['Title']) ?></h4>
                <time><?= htmlspecialchars($timeAgo($note)) ?></time>
              </div>
              <div class="an-meta">
                <span class="an-pill <?= $categoryStyles[$note['Category']] ?? 'an-pill-slate' ?>"><?= htmlspecialchars($note['Category']) ?></span>
                <span><?= $note['Audience'] === 'Everyone' ? 'Patients & team' : 'Patients' ?></span>
                <?php if ($note['AffectsDate']): ?><span class="<?= $note['AffectsUpcoming'] ? 'sn-upcoming' : '' ?>">· affects <?= htmlspecialchars(date('M j', strtotime($note['AffectsDate']))) ?></span><?php endif; ?>
                <span>· by <?= htmlspecialchars($note['FirstName']) ?></span>
              </div>
              <p class="an-message"><?= htmlspecialchars($note['Message']) ?></p>
              <?php if (mb_strlen($note['Message']) > 180): ?><span class="an-more">Read more</span><?php endif; ?>
            </div>
            <?php if ($photoUrl): ?><img src="<?= htmlspecialchars($photoUrl) ?>" alt="" class="an-thumb" loading="lazy"><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <p class="ma-empty sn-no-match" hidden>No announcements in this category.</p>
  <?php else: ?>
    <div class="ma-empty">
      <h3>No announcements yet</h3>
      <p>Post closures, schedule changes, and events — they'll appear on patients' Announcements page.</p>
      <button type="button" class="btn btn-primary btn-sm" data-modal-open="snFormModal">New announcement</button>
    </div>
  <?php endif; ?>
</div></main>

<div class="modal-overlay" id="snFormModal">
  <div class="modal-box an-modal">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>New announcement</h2>
    <p class="modal-subtitle">Patients of <?= htmlspecialchars($clinicName ?: 'your clinic') ?> will see this on their Announcements page.</p>
    <?php if ($reopenForm): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="post_announcement">
      <div class="form-stack">
        <label>Title<input name="title" maxlength="150" value="<?= htmlspecialchars($values['title']) ?>" placeholder="e.g. Closed on Oct 1 for a local holiday" required></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Category
          <select name="category">
            <?php foreach (ANNOUNCEMENT_CATEGORIES as $category): ?>
              <option <?= $values['category'] === $category ? 'selected' : '' ?>><?= htmlspecialchars($category) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Who sees it
          <select name="audience">
            <?php foreach ($audiences as $key => $label): ?>
              <option value="<?= $key ?>" <?= $values['audience'] === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="form-stack">
        <label>Message<textarea name="message" rows="4" required placeholder="What should patients know?"><?= htmlspecialchars($values['message']) ?></textarea></label>
        <label>Affects appointments on <span class="optional">(optional — patients booked that day see it pinned)</span><input type="date" name="affects_date" value="<?= htmlspecialchars($values['affects_date']) ?>"></label>
      </div>
      <label class="sn-photo-drop">
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" id="snPhotoInput">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>
        <span id="snPhotoLabel">Add a photo <em>(optional, 5 MB max)</em></span>
        <img id="snPhotoPreview" alt="" hidden>
      </label>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:16px;">Post announcement</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="snDetailModal">
  <div class="modal-box an-modal" role="dialog" aria-modal="true" aria-labelledby="snDetailTitle">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div class="an-modal-head">
      <span class="an-avatar tint-<?= $clinicId % 5 ?>"><?= htmlspecialchars($clinicInitials) ?></span>
      <div>
        <span class="an-modal-source"><?= htmlspecialchars($clinicName ?: 'Your clinic') ?></span>
        <span class="an-pill" id="snDetailCategory"></span>
      </div>
    </div>
    <img class="an-modal-photo" id="snDetailPhoto" alt="" hidden>
    <h2 id="snDetailTitle"></h2>
    <p class="an-modal-posted" id="snDetailPosted"></p>
    <p class="an-modal-affects" id="snDetailAffects" hidden></p>
    <div class="an-modal-message" id="snDetailMessage"></div>
    <form method="post" class="an-modal-actions" onsubmit="return confirm('Delete this announcement? Patients will no longer see it.');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="delete_announcement">
      <input type="hidden" name="announcement_id" id="snDetailId" value="">
      <button type="submit" class="btn btn-outline ma-danger">Delete</button>
      <button type="button" class="btn btn-primary" data-modal-close>Close</button>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  <?php if ($reopenForm): ?>window.hqOpenModal(document.getElementById('snFormModal'));<?php endif; ?>

  // Photo preview in the "New announcement" form.
  var input = document.getElementById('snPhotoInput');
  var preview = document.getElementById('snPhotoPreview');
  var label = document.getElementById('snPhotoLabel');
  input.addEventListener('change', function () {
    var file = input.files[0];
    preview.hidden = !file;
    if (file) {
      preview.src = URL.createObjectURL(file);
      label.textContent = file.name;
    }
  });

  // Card -> detail modal.
  var detail = document.getElementById('snDetailModal');
  var pills = <?= json_encode($categoryStyles) ?>;
  function openDetail(card) {
    var d = card.dataset;
    var pill = document.getElementById('snDetailCategory');
    pill.textContent = d.category;
    pill.className = 'an-pill ' + (pills[d.category] || 'an-pill-slate');
    var photo = document.getElementById('snDetailPhoto');
    photo.hidden = !d.photo;
    if (d.photo) photo.src = d.photo;
    document.getElementById('snDetailTitle').textContent = card.querySelector('h4').textContent;
    document.getElementById('snDetailPosted').textContent = 'Posted ' + d.posted + ' by ' + d.author + ' · ' + (d.audience === 'Everyone' ? 'patients & team' : 'patients');
    var affects = document.getElementById('snDetailAffects');
    affects.hidden = !d.affects;
    affects.textContent = 'Affects appointments on ' + d.affects;
    document.getElementById('snDetailMessage').textContent = card.querySelector('.an-message').textContent;
    document.getElementById('snDetailId').value = d.id;
    window.hqOpenModal(detail);
  }
  document.querySelectorAll('[data-sn-card]').forEach(function (card) {
    card.addEventListener('click', function () { openDetail(card); });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDetail(card); }
    });
  });

  // Category filter chips.
  var noMatch = document.querySelector('.sn-no-match');
  document.querySelectorAll('[data-category-filter]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var category = chip.getAttribute('data-category-filter');
      document.querySelectorAll('[data-category-filter]').forEach(function (c) { c.classList.toggle('is-active', c === chip); });
      var shown = 0;
      document.querySelectorAll('.sn-group').forEach(function (group) {
        var groupShown = 0;
        group.querySelectorAll('[data-sn-card]').forEach(function (card) {
          var visible = !category || card.getAttribute('data-category') === category;
          card.hidden = !visible;
          if (visible) groupShown++;
        });
        group.hidden = groupShown === 0;
        shown += groupShown;
      });
      if (noMatch) noMatch.hidden = shown > 0;
    });
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
