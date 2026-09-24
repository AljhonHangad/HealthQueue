<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clinic-hours.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

// Read-only view: physicians set their own availability from their dashboard.
$user = currentUser();
$pdo = getDbConnection();
$clinicId = (int) $user['ClinicID'];
$physicians = [];
$dataError = null;
$today = (int) date('N');
$dayShort = [1 => 'M', 2 => 'T', 3 => 'W', 4 => 'Th', 5 => 'F', 6 => 'Sa', 7 => 'Su'];
$dayLong = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

if ($pdo && $clinicId) {
    try {
        $today = (int) clinicNow($pdo)->format('N');

        $stmt = $pdo->prepare(
            "SELECT u.UserID, u.FirstName, u.LastName, u.AvailabilityStatus,
                    (SELECT MAX(q.QueueNumber) FROM Queue q WHERE q.PhysicianID = u.UserID AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Calling', 'Serving')) AS NowServing,
                    (SELECT COUNT(*) FROM Queue q WHERE q.PhysicianID = u.UserID AND DATE(q.CreatedAt) = CURDATE() AND q.Status = 'Serving') AS ServingCount,
                    (SELECT COUNT(*) FROM Queue q WHERE q.PhysicianID = u.UserID AND DATE(q.CreatedAt) = CURDATE() AND q.Status = 'Waiting') AS Waiting
             FROM Users u
             WHERE u.ClinicID = ? AND u.RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND u.Status = 'Active'
             ORDER BY u.LastName"
        );
        $stmt->execute([$clinicId]);
        $physicians = $stmt->fetchAll();

        $slotStmt = $pdo->prepare('SELECT DayOfWeek, StartTime, EndTime FROM PhysicianAvailability WHERE PhysicianID = ? ORDER BY DayOfWeek, StartTime');
        foreach ($physicians as &$physician) {
            $slotStmt->execute([$physician['UserID']]);
            $physician['Slots'] = [];
            foreach ($slotStmt->fetchAll() as $slot) {
                $physician['Slots'][(int) $slot['DayOfWeek']][] = clinicFormatTime($slot['StartTime']) . '–' . clinicFormatTime($slot['EndTime']);
            }
            // "In consultation" is derived from the queue, not set by anyone.
            $physician['DisplayStatus'] = $physician['AvailabilityStatus'] === 'Available' && (int) $physician['ServingCount'] > 0
                ? 'In consultation' : $physician['AvailabilityStatus'];
        }
        unset($physician);
    } catch (PDOException $e) {
        error_log('Physicians list load failed: ' . $e->getMessage());
        $dataError = 'Physician availability is temporarily unavailable.';
    }
}

$statusStyles = [
    'Available'       => ['key' => 'available', 'pill' => 'ph-pill-green'],
    'In consultation' => ['key' => 'consult', 'pill' => 'ph-pill-blue'],
    'On Break'        => ['key' => 'break', 'pill' => 'ph-pill-amber'],
    'Unavailable'     => ['key' => 'unavailable', 'pill' => 'ph-pill-slate'],
];
$counts = ['available' => 0, 'consult' => 0, 'break' => 0, 'unavailable' => 0];
foreach ($physicians as $physician) {
    $counts[$statusStyles[$physician['DisplayStatus']]['key'] ?? 'unavailable']++;
}
$filters = ['all' => 'All', 'available' => 'Available', 'consult' => 'In consultation', 'break' => 'On break', 'unavailable' => 'Unavailable'];
$tints = ['tint-0', 'tint-1', 'tint-2', 'tint-3', 'tint-4'];

$pageTitle = 'Physicians — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container ph-page">
  <section class="sd-hero ph-hero">
    <div>
      <span class="an-eyebrow">Team</span>
      <h1>Physicians</h1>
      <p>Live availability for your clinic's physicians.</p>
    </div>
    <div class="an-hero-stats ph-hero-stats">
      <div class="ph-stat-green"><strong><?= $counts['available'] ?></strong><span>Available</span></div>
      <div class="ph-stat-blue"><strong><?= $counts['consult'] ?></strong><span>In consult</span></div>
      <div class="ph-stat-amber"><strong><?= $counts['break'] ?></strong><span>On break</span></div>
    </div>
  </section>

  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <label class="cb-search ph-search">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
    <input type="search" id="phSearch" placeholder="Search physician" aria-label="Search physician">
  </label>

  <div class="ph-filters" role="group" aria-label="Filter by status">
    <?php foreach ($filters as $key => $label): ?>
      <button type="button" class="ph-filter<?= $key === 'all' ? ' is-active' : '' ?>" data-filter="<?= $key ?>">
        <?php if ($key !== 'all'): ?><span class="ph-dot ph-dot-<?= $key ?>"></span><?php endif; ?>
        <?= $label ?><?= $key === 'all' ? ' ' . count($physicians) : '' ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php if ($physicians): ?>
    <div class="ph-grid" id="phGrid">
      <?php foreach ($physicians as $i => $phy): ?>
        <?php
          $style = $statusStyles[$phy['DisplayStatus']] ?? $statusStyles['Unavailable'];
          $hasSchedule = (bool) $phy['Slots'];
          $todayHours = $phy['Slots'][$today] ?? null;
          if ((int) $phy['ServingCount'] > 0 || $phy['NowServing']) {
              $queueText = 'Serving #' . (int) $phy['NowServing'] . ' · ' . (int) $phy['Waiting'] . ' waiting';
          } else {
              $queueText = (int) $phy['Waiting'] ? (int) $phy['Waiting'] . ' waiting' : 'No patients yet';
          }
          $fullName = 'Dr. ' . $phy['FirstName'] . ' ' . $phy['LastName'];
        ?>
        <article class="ph-card" data-status="<?= $style['key'] ?>" data-name="<?= htmlspecialchars(mb_strtolower($fullName)) ?>">
          <div class="ph-card-head">
            <span class="ph-avatar <?= $tints[$i % 5] ?>"><?= htmlspecialchars(strtoupper(mb_substr($phy['FirstName'], 0, 1) . mb_substr($phy['LastName'], 0, 1))) ?></span>
            <div class="ph-name">
              <strong><?= htmlspecialchars($fullName) ?></strong>
              <span>Physician</span>
            </div>
            <span class="ph-pill <?= $style['pill'] ?>"><span class="ph-dot ph-dot-<?= $style['key'] ?>"></span><?= htmlspecialchars($phy['DisplayStatus']) ?></span>
          </div>

          <div class="ph-facts">
            <div>
              <span>Today's hours</span>
              <strong><?= $todayHours ? htmlspecialchars(implode(', ', $todayHours)) : ($hasSchedule ? 'Off today' : 'No schedule set') ?></strong>
            </div>
            <div class="<?= $phy['DisplayStatus'] === 'On Break' ? 'is-amber' : '' ?>">
              <span>Queue</span>
              <strong><?= htmlspecialchars($queueText) ?></strong>
            </div>
          </div>

          <div class="ph-week" aria-label="Weekly schedule">
            <?php foreach ($dayShort as $dayNum => $label): ?>
              <?php $cls = $dayNum === $today ? 'is-today' : (isset($phy['Slots'][$dayNum]) ? 'is-working' : 'is-off'); ?>
              <span class="<?= $cls ?>" title="<?= $dayLong[$dayNum] ?>: <?= isset($phy['Slots'][$dayNum]) ? htmlspecialchars(implode(', ', $phy['Slots'][$dayNum])) : 'off' ?>"><?= $label ?></span>
            <?php endforeach; ?>
          </div>

          <div class="ph-card-foot">
            <button type="button" class="ph-link" data-toggle-schedule<?= $hasSchedule ? '' : ' disabled' ?>>View schedule</button>
            <span class="ph-muted"><?= $phy['DisplayStatus'] === 'In consultation' ? 'Set by queue' : 'Set by physician' ?></span>
          </div>
          <?php if ($hasSchedule): ?>
            <ul class="ph-schedule" hidden>
              <?php foreach ($dayLong as $dayNum => $dayName): ?>
                <li class="<?= $dayNum === $today ? 'is-today' : '' ?>"><span><?= $dayName ?></span><strong><?= isset($phy['Slots'][$dayNum]) ? htmlspecialchars(implode(', ', $phy['Slots'][$dayNum])) : 'Off' ?></strong></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <p class="ma-empty ph-no-match" id="phNoMatch" hidden>No physicians match this filter.</p>
    <p class="ph-legend">Schedule strip: <b class="ph-green">green</b> working · <b class="ph-blue">blue</b> today · <b class="ph-grey">grey</b> day off. Physicians set their own availability and weekly hours.</p>
  <?php else: ?>
    <div class="ma-empty"><h3>No physicians yet</h3><p>Physicians assigned to your clinic will appear here.</p></div>
  <?php endif; ?>
</div></main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var cards = Array.prototype.slice.call(document.querySelectorAll('.ph-card'));
  var search = document.getElementById('phSearch');
  var noMatch = document.getElementById('phNoMatch');
  var activeFilter = 'all';

  function apply() {
    var term = (search.value || '').trim().toLowerCase();
    var shown = 0;
    cards.forEach(function (card) {
      var visible = (activeFilter === 'all' || card.getAttribute('data-status') === activeFilter)
        && (!term || card.getAttribute('data-name').indexOf(term) !== -1);
      card.hidden = !visible;
      if (visible) shown++;
    });
    if (noMatch) noMatch.hidden = shown > 0;
  }

  document.querySelectorAll('[data-filter]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      activeFilter = btn.getAttribute('data-filter');
      document.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      apply();
    });
  });
  if (search) search.addEventListener('input', apply);

  document.querySelectorAll('[data-toggle-schedule]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var list = btn.closest('.ph-card').querySelector('.ph-schedule');
      if (!list) return;
      list.hidden = !list.hidden;
      btn.textContent = list.hidden ? 'View schedule' : 'Hide schedule';
    });
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
