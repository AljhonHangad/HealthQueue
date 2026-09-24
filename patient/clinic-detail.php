<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$clinicId = filter_input(INPUT_GET, 'clinic_id', FILTER_VALIDATE_INT);

if (!$clinicId || !$pdo) {
    header('Location: ' . HQ_BASE_URL . '/patient/clinics.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT ClinicID, ClinicName, Address, ContactNumber, BaseConsultationFee, PhotoUrl, Description,
            Specialties, OpenDays, OpenTime, CloseTime
     FROM Clinic WHERE ClinicID = ? AND archived = 0 AND Status = 'Active'"
);
$stmt->execute([$clinicId]);
$clinic = $stmt->fetch();

if (!$clinic) {
    header('Location: ' . HQ_BASE_URL . '/patient/clinics.php');
    exit;
}

$physicians = [];
try {
    $physStmt = $pdo->prepare(
        "SELECT UserID, FirstName, LastName, AvailabilityStatus FROM Users
         WHERE ClinicID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
         ORDER BY LastName"
    );
    $physStmt->execute([$clinicId]);
    $physicians = $physStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Clinic detail physician load failed: ' . $e->getMessage());
}

$hqStatusDot = ['Available' => '#22c55e', 'On Break' => '#0077b3', 'Unavailable' => '#94a3b8'];

$ratingAvg = 0.0;
$ratingCount = 0;
$ratingBreakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$reviews = [];
try {
    $ratingStmt = $pdo->prepare('SELECT Rating, COUNT(*) FROM Feedback WHERE ClinicID = ? GROUP BY Rating');
    $ratingStmt->execute([$clinicId]);
    $ratingSum = 0;
    foreach ($ratingStmt->fetchAll(PDO::FETCH_KEY_PAIR) as $stars => $count) {
        $stars = max(1, min(5, (int) $stars));
        $ratingBreakdown[$stars] += (int) $count;
        $ratingCount += (int) $count;
        $ratingSum += $stars * (int) $count;
    }
    $ratingAvg = $ratingCount ? $ratingSum / $ratingCount : 0.0;

    $reviewStmt = $pdo->prepare(
        "SELECT f.Rating, f.Comment, f.CreatedAt, p.FirstName, p.LastName
         FROM Feedback f
         JOIN Users p ON p.UserID = f.PatientID
         WHERE f.ClinicID = ? AND f.Comment IS NOT NULL AND f.Comment <> ''
         ORDER BY f.CreatedAt DESC
         LIMIT 10"
    );
    $reviewStmt->execute([$clinicId]);
    $reviews = $reviewStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Clinic rating load failed: ' . $e->getMessage());
}

// Opening hours -- "now" comes from MySQL to match the clinic browser.
$dayLong = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$formatTime = static function (string $time): string {
    $ts = strtotime($time);
    return date(date('i', $ts) === '00' ? 'g A' : 'g:i A', $ts);
};
$now = new DateTimeImmutable((string) $pdo->query('SELECT NOW()')->fetchColumn());
$today = (int) $now->format('N');
$openDays = array_map('intval', explode(',', (string) $clinic['OpenDays']));
$isOpen = in_array($today, $openDays, true) && $now->format('H:i:s') >= $clinic['OpenTime'] && $now->format('H:i:s') < $clinic['CloseTime'];
$hoursText = $formatTime($clinic['OpenTime']) . ' – ' . $formatTime($clinic['CloseTime']);

$specialties = array_filter(array_map('trim', explode(',', (string) $clinic['Specialties'])), 'strlen');
$photoExists = !empty($clinic['PhotoUrl']) && is_file(__DIR__ . '/../assets/uploads/clinics/' . basename($clinic['PhotoUrl']));
$words = preg_split('/\s+/', trim($clinic['ClinicName']));
$initials = strtoupper(mb_substr($words[0] ?? '', 0, 1) . mb_substr($words[1] ?? '', 0, 1));

$renderStars = static function (float $rating): string {
    $full = (int) round($rating);
    return str_repeat('★', $full) . str_repeat('☆', 5 - $full);
};

$pageTitle = $clinic['ClinicName'] . ' — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container cdp">
  <a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="back-link">&larr; Back to clinics</a>

  <section class="cdp-header">
    <div class="cdp-cover <?= $isOpen ? 'tint-' . ((int) $clinic['ClinicID'] % 5) : 'tint-closed' ?>">
      <?php if ($photoExists): ?>
        <img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="">
      <?php else: ?>
        <span class="cb-initials"><?= htmlspecialchars($initials) ?></span>
      <?php endif; ?>
      <span class="cb-status <?= $isOpen ? 'is-open' : 'is-closed' ?>"><?= $isOpen ? 'Open · until ' . htmlspecialchars($formatTime($clinic['CloseTime'])) : 'Closed now' ?></span>
    </div>
    <div class="cdp-header-body">
      <div class="cdp-header-main">
        <h1><?= htmlspecialchars($clinic['ClinicName']) ?></h1>
        <p class="cb-address">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
          <?= htmlspecialchars($clinic['Address']) ?>
          <?php if ($clinic['ContactNumber']): ?>&nbsp;&middot;&nbsp;<?= htmlspecialchars($clinic['ContactNumber']) ?><?php endif; ?>
        </p>
        <p class="cdp-rating-inline">
          <?php if ($ratingCount): ?>
            <span class="cdp-stars"><?= $renderStars($ratingAvg) ?></span>
            <strong><?= number_format($ratingAvg, 1) ?></strong>
            <a href="#reviews"><?= $ratingCount ?> rating<?= $ratingCount === 1 ? '' : 's' ?></a>
          <?php else: ?>
            <span class="cdp-muted">No ratings yet</span>
          <?php endif; ?>
        </p>
        <?php if ($specialties): ?>
          <div class="cb-tags"><?php foreach ($specialties as $spec): ?><span><?= htmlspecialchars($spec) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
      <div class="cdp-book">
        <span class="cb-fee">from <strong>₱<?= number_format((float) $clinic['BaseConsultationFee']) ?></strong></span>
        <button type="button" class="btn btn-primary" data-book-clinic="<?= (int) $clinic['ClinicID'] ?>">Book Now</button>
      </div>
    </div>
  </section>

  <div class="cdp-grid">
    <div class="cdp-col">
      <section class="cdp-card">
        <h2>About</h2>
        <?php if (!empty($clinic['Description'])): ?>
          <p class="cd-text"><?= htmlspecialchars($clinic['Description']) ?></p>
        <?php else: ?>
          <p class="cdp-muted">This clinic hasn't added a description yet.</p>
        <?php endif; ?>
        <div class="cd-facts" style="margin-top:16px;">
          <div><span>Consultation fee</span><strong>₱<?= number_format((float) $clinic['BaseConsultationFee'], 2) ?></strong><small>Confirmed at checkout</small></div>
          <div><span>Today</span><strong><?= in_array($today, $openDays, true) ? htmlspecialchars($hoursText) : 'Closed' ?></strong><small><?= $isOpen ? 'Open now' : 'Closed now' ?></small></div>
        </div>
      </section>

      <section class="cdp-card">
        <h2>Opening hours</h2>
        <ul class="cdp-hours">
          <?php foreach ($dayLong as $dayNum => $dayName): ?>
            <li class="<?= $dayNum === $today ? 'is-today' : '' ?>">
              <span><?= $dayName ?><?= $dayNum === $today ? ' (today)' : '' ?></span>
              <strong><?= in_array($dayNum, $openDays, true) ? htmlspecialchars($hoursText) : 'Closed' ?></strong>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="cdp-card">
        <h2>Physicians</h2>
        <?php if ($physicians): ?>
          <ul class="cdp-physicians">
            <?php foreach ($physicians as $physician): ?>
              <li>
                <span class="cdp-avatar"><?= htmlspecialchars(strtoupper(substr($physician['FirstName'], 0, 1) . substr($physician['LastName'], 0, 1))) ?></span>
                <strong>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></strong>
                <span class="cdp-availability"><span style="background:<?= $hqStatusDot[$physician['AvailabilityStatus']] ?? '#94a3b8' ?>;"></span><?= htmlspecialchars($physician['AvailabilityStatus']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="cdp-muted">No physicians are listed for this clinic yet.</p>
        <?php endif; ?>
      </section>
    </div>

    <div class="cdp-col">
      <section class="cdp-card" id="reviews">
        <h2>Ratings &amp; reviews</h2>
        <div class="cdp-rating-summary">
          <div class="cdp-rating-big">
            <strong><?= $ratingCount ? number_format($ratingAvg, 1) : '–' ?></strong>
            <span class="cdp-stars"><?= $renderStars($ratingAvg) ?></span>
            <small><?= $ratingCount ?> rating<?= $ratingCount === 1 ? '' : 's' ?></small>
          </div>
          <ul class="cdp-rating-bars">
            <?php foreach ($ratingBreakdown as $stars => $count): ?>
              <li>
                <span><?= $stars ?> ★</span>
                <span class="cdp-bar"><span style="width:<?= $ratingCount ? round($count / $ratingCount * 100) : 0 ?>%;"></span></span>
                <span class="cdp-bar-count"><?= $count ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <?php if ($reviews): ?>
          <ul class="cdp-reviews">
            <?php foreach ($reviews as $review): ?>
              <li>
                <div class="cdp-review-head">
                  <span class="cdp-stars"><?= $renderStars((float) $review['Rating']) ?></span>
                  <span class="cdp-muted"><?= htmlspecialchars($review['FirstName'] . ' ' . mb_substr($review['LastName'], 0, 1) . '.') ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($review['CreatedAt']))) ?></span>
                </div>
                <p><?= htmlspecialchars($review['Comment']) ?></p>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="cdp-muted" style="margin-top:14px;">No written reviews yet. Patients can rate this clinic after a completed visit.</p>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div></main>

<?php require __DIR__ . '/../includes/booking-modal.php'; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
