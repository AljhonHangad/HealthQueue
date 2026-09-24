<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/announcements.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$tabs = ['all' => 'All', 'clinics' => 'My clinics', 'healthqueue' => 'HealthQueue'];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';

$announcements = [];
$pinned = [];
$newCount = 0;
$dataError = null;

// Category => pill style.
$categoryStyles = [
    'Closure'         => 'an-pill-red',
    'Schedule change' => 'an-pill-amber',
    'Event'           => 'an-pill-green',
    'Health advisory' => 'an-pill-blue',
    'General'         => 'an-pill-slate',
];

$flash = '';
$errors = [];

// "Delete" hides an announcement for this patient only (announcements are
// shared). Posted from here and from the dashboard's announcements card.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'dismiss_announcement') {
    $announcementId = filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
    $returnTo = ($_POST['return_to'] ?? '') === 'dashboard' ? 'dashboard' : 'announcements';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo || !$announcementId) {
        $errors[] = 'We could not delete that announcement.';
    } else {
        try {
            $pdo->prepare('INSERT IGNORE INTO AnnouncementDismissals (UserID, AnnouncementID) VALUES (?, ?)')
                ->execute([$user['UserID'], $announcementId]);
            $target = $returnTo === 'dashboard' ? '/patient/dashboard.php' : '/patient/announcements.php?tab=' . $activeTab;
            header('Location: ' . HQ_BASE_URL . $target . (str_contains($target, '?') ? '&' : '?') . 'dismissed=1');
            exit;
        } catch (PDOException $e) {
            error_log('Announcement dismiss failed: ' . $e->getMessage());
            $errors[] = 'We could not delete that announcement.';
        }
    }
}
if (isset($_GET['dismissed'])) $flash = 'Announcement deleted.';

if ($pdo) {
    try {
        $seenStmt = $pdo->prepare('SELECT AnnouncementsSeenAt FROM Users WHERE UserID = ?');
        $seenStmt->execute([$user['UserID']]);
        $seenAt = $seenStmt->fetchColumn() ?: null;

        // "My clinics" = clinics this patient has booked or visited.
        $clinicStmt = $pdo->prepare('SELECT DISTINCT ClinicID FROM Appointments WHERE PatientID = ?');
        $clinicStmt->execute([$user['UserID']]);
        $myClinicIds = array_map('intval', $clinicStmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt = $pdo->prepare(
            "SELECT a.AnnouncementID, a.ClinicID, a.Title, a.Category, a.Message, a.PhotoPath, a.AffectsDate, a.CreatedAt,
                    c.ClinicName,
                    TIMESTAMPDIFF(MINUTE, a.CreatedAt, NOW()) AS MinutesAgo,
                    DATEDIFF(CURDATE(), DATE(a.CreatedAt)) AS DaysAgo,
                    (a.AffectsDate IS NOT NULL AND a.AffectsDate >= CURDATE()) AS AffectsUpcoming
             FROM Announcements a
             LEFT JOIN Clinic c ON c.ClinicID = a.ClinicID
             WHERE a.Audience IN ('Everyone', 'Patients')
               AND NOT EXISTS (SELECT 1 FROM AnnouncementDismissals d WHERE d.UserID = ? AND d.AnnouncementID = a.AnnouncementID)
             ORDER BY a.CreatedAt DESC
             LIMIT 100"
        );
        $stmt->execute([$user['UserID']]);
        $all = $stmt->fetchAll();

        // Clinic announcements tied to a date pin to the top when the patient
        // has an active booking at that clinic on that date.
        $bookingStmt = $pdo->prepare(
            "SELECT AppointmentID, AppointmentDate FROM Appointments
             WHERE PatientID = ? AND ClinicID = ? AND AppointmentDate = ? AND Status IN ('Pending', 'Confirmed')
             ORDER BY AppointmentTime LIMIT 1"
        );

        foreach ($all as $note) {
            $note['IsNew'] = $seenAt === null || $note['CreatedAt'] > $seenAt;
            if ($note['IsNew']) $newCount++;

            if ($note['ClinicID'] && $note['AffectsUpcoming']) {
                $bookingStmt->execute([$user['UserID'], $note['ClinicID'], $note['AffectsDate']]);
                if ($booking = $bookingStmt->fetch()) {
                    $pinned[] = $note + ['BookingID' => (int) $booking['AppointmentID']];
                    continue;
                }
            }

            $isClinic = (bool) $note['ClinicID'];
            if ($activeTab === 'clinics' && (!$isClinic || !in_array((int) $note['ClinicID'], $myClinicIds, true))) continue;
            if ($activeTab === 'healthqueue' && $isClinic) continue;
            $announcements[] = $note;
        }

        // Opening the page marks everything up to now as seen.
        $pdo->prepare('UPDATE Users SET AnnouncementsSeenAt = NOW() WHERE UserID = ?')->execute([$user['UserID']]);
    } catch (PDOException $e) {
        error_log('Patient announcements load failed: ' . $e->getMessage());
        $dataError = 'Announcements are temporarily unavailable.';
    }
}

// Group into Today / Earlier this week / Earlier.
$groups = [];
foreach ($announcements as $note) {
    $days = (int) $note['DaysAgo'];
    $label = $days === 0 ? 'Today' : ($days < 7 ? 'Earlier this week' : 'Earlier');
    $groups[$label][] = $note;
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
$initials = static function (string $name): string {
    $words = preg_split('/\s+/', trim($name));
    return strtoupper(mb_substr($words[0] ?? '', 0, 1) . mb_substr($words[1] ?? '', 0, 1));
};

$pageTitle = 'Announcements — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container an-page">
  <section class="an-hero">
    <div>
      <span class="an-eyebrow">Stay informed</span>
      <h1>News &amp; Advisories</h1>
      <p>Schedule changes, closures, and health tips from your clinics.</p>
    </div>
    <div class="an-hero-stats">
      <div><strong><?= $newCount ?></strong><span>New</span></div>
      <div class="an-hero-stat-alert"><strong><?= count($pinned) ?></strong><span>Affects you</span></div>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <nav class="an-tabs" aria-label="Filter announcements">
    <?php foreach ($tabs as $key => $label): ?>
      <a href="?tab=<?= $key ?>" class="an-tab<?= $activeTab === $key ? ' is-active' : '' ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
  </nav>

  <?php foreach ($pinned as $note): ?>
    <?php $pinSource = $note['ClinicName'] ?: 'HealthQueue'; ?>
    <section class="an-pinned" id="announcement-<?= (int) $note['AnnouncementID'] ?>" tabindex="0" role="button" aria-haspopup="dialog" data-announcement data-announcement-id="<?= (int) $note['AnnouncementID'] ?>" data-photo="<?= htmlspecialchars((string) announcementPhotoUrl($note['PhotoPath'])) ?>"
             data-posted="<?= htmlspecialchars(date('l, F j, Y · g:i A', strtotime($note['CreatedAt'])), ENT_QUOTES) ?>"
             data-affects="<?= htmlspecialchars(date('l, F j, Y', strtotime($note['AffectsDate'])), ENT_QUOTES) ?>">
      <?php /* Hidden copy of the card fields the detail modal reads. */ ?>
      <div hidden>
        <span class="an-avatar tint-<?= (int) $note['ClinicID'] % 5 ?>"><?= htmlspecialchars($initials($pinSource)) ?></span>
        <div class="an-meta"><span><?= htmlspecialchars($pinSource) ?></span><span class="an-pill <?= $categoryStyles[$note['Category']] ?? 'an-pill-slate' ?>"><?= htmlspecialchars($note['Category']) ?></span></div>
        <div class="an-title-row"><h4><?= htmlspecialchars($note['Title']) ?></h4></div>
        <p class="an-message"><?= htmlspecialchars($note['Message']) ?></p>
      </div>
      <span class="an-pinned-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg></span>
      <div>
        <span class="an-pinned-kicker">Affects your <?= htmlspecialchars(date('M j', strtotime($note['AffectsDate']))) ?> appointment</span>
        <h2><?= htmlspecialchars($note['Title']) ?></h2>
        <p><?= htmlspecialchars($note['Message']) ?></p>
        <?php if ($note['PhotoPath']): ?><img src="<?= htmlspecialchars(announcementPhotoUrl($note['PhotoPath'])) ?>" alt="" class="an-pinned-photo"><?php endif; ?>
        <a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php?tab=all#appt-<?= (int) $note['BookingID'] ?>" class="btn btn-primary btn-sm">Review appointment</a>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if ($groups): ?>
    <?php foreach ($groups as $label => $notes): ?>
      <h3 class="an-group"><?= htmlspecialchars($label) ?></h3>
      <?php foreach ($notes as $note): ?>
        <?php
          $isClinic = (bool) $note['ClinicID'];
          $source = $isClinic ? $note['ClinicName'] : 'HealthQueue';
          $isLong = mb_strlen($note['Message']) > 180;
        ?>
        <article class="an-card is-collapsed" id="announcement-<?= (int) $note['AnnouncementID'] ?>" tabindex="0" role="button" aria-haspopup="dialog" data-announcement data-announcement-id="<?= (int) $note['AnnouncementID'] ?>" data-photo="<?= htmlspecialchars((string) announcementPhotoUrl($note['PhotoPath'])) ?>"
                 data-posted="<?= htmlspecialchars(date('l, F j, Y · g:i A', strtotime($note['CreatedAt'])), ENT_QUOTES) ?>"
                 data-affects="<?= $note['AffectsDate'] ? htmlspecialchars(date('l, F j, Y', strtotime($note['AffectsDate'])), ENT_QUOTES) : '' ?>">
          <?php if ($isClinic): ?>
            <span class="an-avatar tint-<?= (int) $note['ClinicID'] % 5 ?>"><?= htmlspecialchars($initials($source)) ?></span>
          <?php else: ?>
            <span class="an-avatar an-avatar-hq"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/></svg></span>
          <?php endif; ?>
          <div class="an-body">
            <div class="an-title-row">
              <h4><?php if ($note['IsNew']): ?><span class="an-new-dot" title="New"></span><?php endif; ?><?= htmlspecialchars($note['Title']) ?></h4>
              <time><?= htmlspecialchars($timeAgo($note)) ?></time>
            </div>
            <div class="an-meta">
              <span><?= htmlspecialchars($source) ?></span>
              <span class="an-pill <?= $categoryStyles[$note['Category']] ?? 'an-pill-slate' ?>"><?= htmlspecialchars($note['Category']) ?></span>
            </div>
            <p class="an-message"><?= htmlspecialchars($note['Message']) ?></p>
            <?php if ($isLong): ?><span class="an-more">Read more</span><?php endif; ?>
          </div>
          <?php if ($note['PhotoPath']): ?><img src="<?= htmlspecialchars(announcementPhotoUrl($note['PhotoPath'])) ?>" alt="" class="an-thumb" loading="lazy"><?php endif; ?>
        </article>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php elseif (!$pinned): ?>
    <div class="ma-empty">
      <h3><?= $activeTab === 'clinics' ? 'No updates from your clinics' : 'No announcements yet' ?></h3>
      <p><?= $activeTab === 'clinics' ? 'Clinics you book with will post closures, events, and schedule changes here.' : 'Updates from HealthQueue and your clinics will appear here.' ?></p>
    </div>
  <?php endif; ?>
</div></main>

<div class="modal-overlay" id="announcementModal">
  <div class="modal-box an-modal" role="dialog" aria-modal="true" aria-labelledby="announcementModalTitle">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div class="an-modal-head">
      <span class="an-modal-avatar"></span>
      <div>
        <span class="an-modal-source"></span>
        <span class="an-modal-pill"></span>
      </div>
    </div>
    <img class="an-modal-photo" alt="" hidden>
    <h2 id="announcementModalTitle"></h2>
    <p class="an-modal-posted"></p>
    <p class="an-modal-affects" hidden></p>
    <div class="an-modal-message"></div>
    <form method="post" class="an-modal-actions" onsubmit="return confirm('Delete this announcement? It will be removed from your list only.');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="dismiss_announcement">
      <input type="hidden" name="announcement_id" value="" id="announcementModalId">
      <button type="submit" class="btn btn-outline ma-danger">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        Delete
      </button>
      <button type="button" class="btn btn-primary" data-modal-close>Close</button>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Clicking an announcement card opens its full text in a modal, built
  // from the card's own markup so there's no extra request.
  var modal = document.getElementById('announcementModal');

  function openAnnouncement(card) {
    var avatar = modal.querySelector('.an-modal-avatar');
    avatar.innerHTML = '';
    avatar.appendChild(card.querySelector('.an-avatar').cloneNode(true));
    modal.querySelector('.an-modal-source').textContent = card.querySelector('.an-meta > span:first-child').textContent;
    var pill = modal.querySelector('.an-modal-pill');
    pill.innerHTML = '';
    pill.appendChild(card.querySelector('.an-pill').cloneNode(true));
    modal.querySelector('#announcementModalTitle').textContent = card.querySelector('.an-title-row h4').textContent.trim();
    modal.querySelector('.an-modal-posted').textContent = 'Posted ' + card.getAttribute('data-posted');
    var affects = modal.querySelector('.an-modal-affects');
    affects.hidden = !card.getAttribute('data-affects');
    affects.textContent = 'Affects appointments on ' + card.getAttribute('data-affects');
    modal.querySelector('.an-modal-message').textContent = card.querySelector('.an-message').textContent;
    document.getElementById('announcementModalId').value = card.getAttribute('data-announcement-id');
    var photo = modal.querySelector('.an-modal-photo');
    photo.hidden = !card.getAttribute('data-photo');
    if (!photo.hidden) photo.src = card.getAttribute('data-photo');

    // Viewing it clears its "new" dot.
    var dot = card.querySelector('.an-new-dot');
    if (dot) dot.remove();
    window.hqOpenModal(modal);
  }

  document.querySelectorAll('[data-announcement]').forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.closest('a')) return; // e.g. "Review appointment"
      openAnnouncement(card);
    });
    card.addEventListener('keydown', function (e) {
      if (e.target !== card) return;
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAnnouncement(card); }
    });
  });

  // Arriving from the dashboard (?open=<AnnouncementID>) opens that one straight away.
  var openId = new URLSearchParams(window.location.search).get('open');
  var target = openId && document.getElementById('announcement-' + openId);
  if (target) {
    target.scrollIntoView({ block: 'center' });
    openAnnouncement(target);
  }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
