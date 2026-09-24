<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

// Availability is set per calendar date: a date with hour blocks is
// bookable, a "day off" date is not, and a date with nothing set is not
// bookable either ("not set").
$user = currentUser();
$pdo = getDbConnection();
$physicianId = (int) $user['UserID'];
$clinicId = $user['ClinicID'] ? (int) $user['ClinicID'] : null;
$errors = [];
$flash = '';

// Selectable times: every 30 minutes, 6:00 AM – 10:00 PM.
$timeOptions = [];
for ($minutes = 6 * 60; $minutes <= 22 * 60; $minutes += 30) {
    $value = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    $timeOptions[$value] = date('g:i A', strtotime($value));
}
// "8a", "12p", "1:30p" -- compact labels for calendar chips.
$chipTime = static function (string $time): string {
    $ts = strtotime($time);
    return (date('i', $ts) === '00' ? date('g', $ts) : date('g:i', $ts)) . (date('a', $ts) === 'am' ? 'a' : 'p');
};

$today = $pdo ? (string) $pdo->query('SELECT CURDATE()')->fetchColumn() : date('Y-m-d');
$monthParam = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? $_POST['month'] ?? '')) ? ($_GET['month'] ?? $_POST['month']) : substr($today, 0, 7);
$monthStart = new DateTimeImmutable($monthParam . '-01');
$monthEnd = $monthStart->modify('last day of this month');

/** Selected dates from the form: valid Y-m-d strings, today or later. */
function postedDates(string $today): array
{
    $dates = [];
    foreach (explode(',', (string) ($_POST['dates'] ?? '')) as $date) {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= $today) $dates[$date] = true;
    }
    return array_keys($dates);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    $dates = postedDates($today);
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (!$dates) {
        $errors[] = 'Select at least one date (today or later) on the calendar.';
    } elseif ($formType === 'add_hours') {
        $start = (string) ($_POST['start_time'] ?? '');
        $end = (string) ($_POST['end_time'] ?? '');
        $perHour = filter_input(INPUT_POST, 'per_hour', FILTER_VALIDATE_INT) ?: null;

        if (!isset($timeOptions[$start], $timeOptions[$end])) $errors[] = 'Please choose valid start and end times.';
        elseif ($start >= $end) $errors[] = 'End time must be after start time.';
        if ($perHour !== null && ($perHour < 1 || $perHour > 12)) $errors[] = 'Patients per hour must be between 1 and 12.';

        if (!$errors) {
            try {
                $overlap = $pdo->prepare(
                    'SELECT COUNT(*) FROM PhysicianDateAvailability
                     WHERE PhysicianID = ? AND AvailDate = ? AND IsDayOff = 0 AND StartTime < ? AND EndTime > ?'
                );
                $insert = $pdo->prepare('INSERT INTO PhysicianDateAvailability (PhysicianID, AvailDate, StartTime, EndTime, PatientsPerHour) VALUES (?, ?, ?, ?, ?)');
                $clearDayOff = $pdo->prepare('DELETE FROM PhysicianDateAvailability WHERE PhysicianID = ? AND AvailDate = ? AND IsDayOff = 1');
                $added = [];
                $skipped = [];
                foreach ($dates as $date) {
                    $overlap->execute([$physicianId, $date, $end, $start]);
                    if ((int) $overlap->fetchColumn()) {
                        $skipped[] = date('M j', strtotime($date));
                        continue;
                    }
                    // Adding hours to a day off makes it a working day again.
                    $clearDayOff->execute([$physicianId, $date]);
                    $insert->execute([$physicianId, $date, $start, $end, $perHour]);
                    $added[] = $date;
                }
                if ($added) {
                    logActivity($pdo, $physicianId, $clinicId, 'Added availability', count($added) . " date(s), {$start}-{$end}");
                }
                $flash = 'Hours added to ' . count($added) . ' date' . (count($added) === 1 ? '' : 's') . '.';
                if ($skipped) $flash .= ' Skipped (overlapping hours): ' . implode(', ', $skipped) . '.';
            } catch (PDOException $e) {
                error_log('Availability add failed: ' . $e->getMessage());
                $errors[] = 'We could not save these hours.';
            }
        }
    } elseif ($formType === 'day_off' || $formType === 'clear_dates') {
        try {
            $placeholders = implode(',', array_fill(0, count($dates), '?'));
            $pdo->prepare("DELETE FROM PhysicianDateAvailability WHERE PhysicianID = ? AND AvailDate IN ({$placeholders})")
                ->execute([$physicianId, ...$dates]);

            if ($formType === 'day_off') {
                $insert = $pdo->prepare('INSERT INTO PhysicianDateAvailability (PhysicianID, AvailDate, IsDayOff) VALUES (?, ?, 1)');
                foreach ($dates as $date) $insert->execute([$physicianId, $date]);

                // Tell patients already booked with this physician on those days.
                $booked = $pdo->prepare(
                    "SELECT AppointmentID, PatientID, AppointmentDate FROM Appointments
                     WHERE PhysicianID = ? AND AppointmentDate IN ({$placeholders}) AND Status IN ('Pending', 'Confirmed')"
                );
                $booked->execute([$physicianId, ...$dates]);
                $affected = $booked->fetchAll();
                foreach ($affected as $appt) {
                    notifyPatient(
                        $pdo,
                        (int) $appt['PatientID'],
                        'Dr. ' . $user['LastName'] . ' will be away on ' . date('M j', strtotime($appt['AppointmentDate'])) . '. The clinic will contact you to reschedule, or you can reschedule from My Appointments.',
                        (int) $appt['AppointmentID']
                    );
                }
                if ($clinicId && $affected) {
                    notifyClinic($pdo, $clinicId, 'Dr. ' . $user['LastName'] . ' marked ' . count($dates) . ' day(s) off. ' . count($affected) . ' booked patient(s) need rescheduling.');
                }
                logActivity($pdo, $physicianId, $clinicId, 'Marked days off', implode(', ', $dates));
                $flash = count($dates) . ' date' . (count($dates) === 1 ? '' : 's') . ' marked as day off'
                    . ($affected ? ' and ' . count($affected) . ' booked patient' . (count($affected) === 1 ? '' : 's') . ' notified.' : '.');
            } else {
                logActivity($pdo, $physicianId, $clinicId, 'Cleared availability', implode(', ', $dates));
                $flash = 'Cleared ' . count($dates) . ' date' . (count($dates) === 1 ? '' : 's') . '.';
            }
        } catch (PDOException $e) {
            error_log('Availability update failed: ' . $e->getMessage());
            $errors[] = 'We could not update these dates.';
        }
    }
}

// Calendar data for the visible month.
$byDate = [];
$monthHours = 0.0;
$nextDayOff = null;
if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            'SELECT AvailDate, StartTime, EndTime, PatientsPerHour, IsDayOff FROM PhysicianDateAvailability
             WHERE PhysicianID = ? AND AvailDate BETWEEN ? AND ? ORDER BY AvailDate, StartTime'
        );
        $stmt->execute([$physicianId, $monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')]);
        foreach ($stmt->fetchAll() as $row) {
            $byDate[$row['AvailDate']] ??= ['off' => false, 'blocks' => []];
            if ($row['IsDayOff']) {
                $byDate[$row['AvailDate']]['off'] = true;
            } else {
                $byDate[$row['AvailDate']]['blocks'][] = $row;
                $monthHours += (strtotime($row['EndTime']) - strtotime($row['StartTime'])) / 3600;
            }
        }
        $offStmt = $pdo->prepare('SELECT MIN(AvailDate) FROM PhysicianDateAvailability WHERE PhysicianID = ? AND IsDayOff = 1 AND AvailDate >= CURDATE()');
        $offStmt->execute([$physicianId]);
        $nextDayOff = $offStmt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        error_log('Availability load failed: ' . $e->getMessage());
        $errors[] = 'Your availability is temporarily unavailable.';
    }
}

$prevMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$leadingBlanks = (int) $monthStart->format('w'); // Sunday-first grid
$daysInMonth = (int) $monthEnd->format('j');
$weekdayHeaders = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$csrf = htmlspecialchars(csrfToken());

$pageTitle = 'My Availability — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container av-page">
  <section class="an-hero">
    <div>
      <span class="an-eyebrow">Schedule</span>
      <h1>My Availability</h1>
      <p>Pick dates on the calendar and set your hours. Dates without hours aren't bookable.</p>
    </div>
    <div class="an-hero-stats">
      <div><strong><?= rtrim(rtrim(number_format($monthHours, 1), '0'), '.') ?></strong><span>hrs this month</span></div>
      <div class="an-hero-stat-alert"><strong><?= $nextDayOff ? htmlspecialchars(date('M j', strtotime($nextDayOff))) : '—' ?></strong><span>next day off</span></div>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <div class="cal-head">
    <div class="cal-title">
      <a href="?month=<?= $prevMonth ?>" class="cal-nav" aria-label="Previous month">&lsaquo;</a>
      <h3 class="qm-section-title">My availability · <?= htmlspecialchars($monthStart->format('F Y')) ?></h3>
      <a href="?month=<?= $nextMonth ?>" class="cal-nav" aria-label="Next month">&rsaquo;</a>
    </div>
    <span class="cal-hint">Click dates to select · click a weekday name to select all of them</span>
  </div>

  <section class="cal-card">
    <div class="cal-grid" role="grid" aria-label="Availability calendar">
      <?php foreach ($weekdayHeaders as $i => $label): ?>
        <button type="button" class="cal-weekday" data-select-weekday="<?= $i ?>" title="Select every <?= $label ?> this month"><?= $label ?></button>
      <?php endforeach; ?>
      <?php for ($b = 0; $b < $leadingBlanks; $b++): ?><span class="cal-blank"></span><?php endfor; ?>
      <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
        <?php
          $date = $monthStart->format('Y-m-') . sprintf('%02d', $d);
          $info = $byDate[$date] ?? null;
          $isPast = $date < $today;
          $classes = ['cal-day'];
          if ($isPast) $classes[] = 'is-past';
          if ($date === $today) $classes[] = 'is-today';
          if ($info && $info['off']) $classes[] = 'is-off';
          elseif ($info && $info['blocks']) $classes[] = 'is-working';
        ?>
        <button type="button" class="<?= implode(' ', $classes) ?>" data-date="<?= $date ?>" data-weekday="<?= (int) date('w', strtotime($date)) ?>"<?= $isPast ? ' disabled' : '' ?> aria-pressed="false">
          <span class="cal-num"><?= $d ?></span>
          <?php if ($info && $info['off']): ?>
            <span class="cal-off">Day off</span>
          <?php elseif ($info): ?>
            <?php foreach ($info['blocks'] as $block): ?>
              <span class="cal-chip"><?= htmlspecialchars($chipTime($block['StartTime']) . '–' . $chipTime($block['EndTime'])) ?><?= $block['PatientsPerHour'] ? ' · ' . (int) $block['PatientsPerHour'] . '/hr' : '' ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
        </button>
      <?php endfor; ?>
    </div>
    <div class="cal-legend">
      <span><i class="lg-working"></i>Available hours</span>
      <span><i class="lg-off"></i>Day off</span>
      <span><i class="lg-selected"></i>Selected</span>
      <span><i class="lg-unset"></i>Not set (not bookable)</span>
    </div>
  </section>

  <section class="cal-card cal-editor">
    <div class="cal-editor-head">
      <div class="cal-mode" role="tablist">
        <button type="button" class="is-active" data-mode="available">Available</button>
        <button type="button" data-mode="dayoff">Day off</button>
      </div>
      <span class="cal-selected-count" id="calSelectedCount">No dates selected</span>
      <button type="button" class="btn btn-outline btn-sm" id="calClear">Clear selection</button>
    </div>

    <form method="post" id="calForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="month" value="<?= htmlspecialchars($monthParam) ?>">
      <input type="hidden" name="dates" id="calDates" value="">
      <input type="hidden" name="form_type" id="calFormType" value="add_hours">

      <div data-mode-panel="available">
        <div class="av-times">
          <select name="start_time" aria-label="Start time">
            <?php foreach ($timeOptions as $value => $label): ?><option value="<?= $value ?>"<?= $value === '08:00' ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
          </select>
          <span class="av-to">to</span>
          <select name="end_time" aria-label="End time">
            <?php foreach ($timeOptions as $value => $label): ?><option value="<?= $value ?>"<?= $value === '12:00' ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
          </select>
          <select name="per_hour" aria-label="Patients per hour">
            <option value="">Default (4 patients/hr)</option>
            <?php for ($n = 1; $n <= 12; $n++): ?><option value="<?= $n ?>"><?= $n ?> patient<?= $n === 1 ? '' : 's' ?>/hr</option><?php endfor; ?>
          </select>
        </div>
        <p class="av-help">Patients can book up to this many per hour (4 if left on default). Adding hours to a day off turns it into a working day. Dates where the hours would overlap existing ones are skipped.</p>
      </div>
      <div data-mode-panel="dayoff" hidden>
        <p class="av-help">Selected dates become days off and any hours on them are removed. Patients already booked with you on those dates are notified, and the front desk is told to reschedule them.</p>
      </div>

      <div class="av-panel-actions">
        <button type="submit" class="btn btn-outline" data-action="clear_dates">Remove from selected</button>
        <button type="submit" class="btn btn-primary" data-action="primary" id="calPrimary">Add hours to 0 dates</button>
      </div>
    </form>
  </section>
</div></main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var selected = new Set();
  var mode = 'available';
  var days = Array.prototype.slice.call(document.querySelectorAll('.cal-day:not([disabled])'));
  var countEl = document.getElementById('calSelectedCount');
  var primary = document.getElementById('calPrimary');
  var form = document.getElementById('calForm');

  function refresh() {
    days.forEach(function (day) {
      var on = selected.has(day.getAttribute('data-date'));
      day.classList.toggle('is-selected', on);
      day.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    var n = selected.size;
    countEl.textContent = n ? n + ' date' + (n === 1 ? '' : 's') + ' selected' : 'No dates selected';
    primary.textContent = (mode === 'available' ? 'Add hours to ' : 'Mark as day off: ') + n + ' date' + (n === 1 ? '' : 's');
    primary.disabled = n === 0;
    form.querySelector('[data-action="clear_dates"]').disabled = n === 0;
  }

  days.forEach(function (day) {
    day.addEventListener('click', function () {
      var date = day.getAttribute('data-date');
      selected.has(date) ? selected.delete(date) : selected.add(date);
      refresh();
    });
  });

  // Weekday header: select every (future) date on that weekday, or clear them if all are selected.
  document.querySelectorAll('[data-select-weekday]').forEach(function (header) {
    header.addEventListener('click', function () {
      var weekday = header.getAttribute('data-select-weekday');
      var matching = days.filter(function (d) { return d.getAttribute('data-weekday') === weekday; });
      var allSelected = matching.length && matching.every(function (d) { return selected.has(d.getAttribute('data-date')); });
      matching.forEach(function (d) {
        var date = d.getAttribute('data-date');
        allSelected ? selected.delete(date) : selected.add(date);
      });
      refresh();
    });
  });

  document.getElementById('calClear').addEventListener('click', function () { selected.clear(); refresh(); });

  document.querySelectorAll('[data-mode]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      mode = btn.getAttribute('data-mode');
      document.querySelectorAll('[data-mode]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      document.querySelectorAll('[data-mode-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-mode-panel') !== mode; });
      refresh();
    });
  });

  // Submit: send the selected dates and which action was pressed.
  form.addEventListener('submit', function (e) {
    var action = e.submitter ? e.submitter.getAttribute('data-action') : 'primary';
    var type = action === 'clear_dates' ? 'clear_dates' : (mode === 'available' ? 'add_hours' : 'day_off');
    if (!selected.size) { e.preventDefault(); return; }
    if (type === 'clear_dates' && !confirm('Remove all hours and day-off marks from the selected dates?')) { e.preventDefault(); return; }
    if (type === 'day_off' && !confirm('Mark the selected dates as days off? Any hours on them will be removed.')) { e.preventDefault(); return; }
    document.getElementById('calFormType').value = type;
    document.getElementById('calDates').value = Array.from(selected).join(',');
  });

  refresh();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
