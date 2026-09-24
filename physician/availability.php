<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'add_slot') {
        $dayOfWeek = filter_input(INPUT_POST, 'day_of_week', FILTER_VALIDATE_INT);
        $startTime = (string) ($_POST['start_time'] ?? '');
        $endTime = (string) ($_POST['end_time'] ?? '');

        if (!$dayOfWeek || $dayOfWeek < 1 || $dayOfWeek > 7) $errors[] = 'Please choose a valid day.';
        if ($startTime === '' || $endTime === '') $errors[] = 'Please provide both a start and end time.';
        if ($startTime !== '' && $endTime !== '' && $startTime >= $endTime) $errors[] = 'End time must be after start time.';

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('INSERT INTO PhysicianAvailability (PhysicianID, DayOfWeek, StartTime, EndTime) VALUES (?, ?, ?, ?)');
                $stmt->execute([$user['UserID'], $dayOfWeek, $startTime, $endTime]);
                logActivity($pdo, $user['UserID'], $user['ClinicID'], 'Added availability slot', $dayNames[$dayOfWeek] . " {$startTime}-{$endTime}");
                $flash = 'Availability added.';
            } catch (PDOException $e) {
                error_log('Availability add failed: ' . $e->getMessage());
                $errors[] = 'We could not save this availability slot.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'delete_slot') {
        $availabilityId = filter_input(INPUT_POST, 'availability_id', FILTER_VALIDATE_INT);
        if (!$availabilityId) {
            $errors[] = 'Invalid availability slot.';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM PhysicianAvailability WHERE AvailabilityID = ? AND PhysicianID = ?');
                $stmt->execute([$availabilityId, $user['UserID']]);
                if ($stmt->rowCount()) {
                    logActivity($pdo, $user['UserID'], $user['ClinicID'], 'Removed availability slot', "AvailabilityID {$availabilityId}");
                    $flash = 'Availability slot removed.';
                } else {
                    $errors[] = 'That availability slot could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Availability delete failed: ' . $e->getMessage());
                $errors[] = 'We could not remove this availability slot.';
            }
        }
    }
}

$slots = [];
if ($pdo) {
    try {
        $stmt = $pdo->prepare('SELECT AvailabilityID, DayOfWeek, StartTime, EndTime FROM PhysicianAvailability WHERE PhysicianID = ? ORDER BY DayOfWeek, StartTime');
        $stmt->execute([$user['UserID']]);
        $slots = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Availability list failed: ' . $e->getMessage());
        $errors[] = 'Your availability schedule is temporarily unavailable.';
    }
}

$pageTitle = 'My Availability — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Schedule</span><h1>My availability</h1><p>Set the days and hours you're open for consultations. Patients can only be expected within these windows.</p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="form-message" style="background:#f0f8ff;border:1px solid #a3d1e0;color:#0077b3;">
    <strong>Note:</strong> the online booking form doesn't check these hours yet — that's planned for when queue scheduling is built. For now this is your published schedule that staff and admin can see.
  </div>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Add a time block</span><h2>New availability</h2></div></div>
    <form class="registration-form" method="post" style="max-width:640px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="add_slot">
      <div class="form-stack">
        <label>Day
          <select name="day_of_week" required>
            <option value="">Select a day</option>
            <?php foreach ($dayNames as $num => $name): ?><option value="<?= $num ?>"><?= $name ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Start time<input type="time" name="start_time" required></label>
        <label>End time<input type="time" name="end_time" required></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Add time block</button>
    </form>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Weekly schedule</span><h2>Your published hours</h2></div></div>
    <?php if ($slots): ?>
      <div class="compact-list">
        <?php foreach ($slots as $slot): ?>
          <article>
            <div>
              <strong><?= htmlspecialchars($dayNames[(int) $slot['DayOfWeek']] ?? '') ?></strong>
              <span><?= htmlspecialchars(date('g:i A', strtotime($slot['StartTime']))) ?> &ndash; <?= htmlspecialchars(date('g:i A', strtotime($slot['EndTime']))) ?></span>
            </div>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
              <input type="hidden" name="form_type" value="delete_slot">
              <input type="hidden" name="availability_id" value="<?= (int) $slot['AvailabilityID'] ?>">
              <button class="btn btn-outline btn-sm" onclick="return confirm('Remove this time block from your schedule?');">Remove</button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">You haven't published any availability yet.</p>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
