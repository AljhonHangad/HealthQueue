<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$clinicId = (int) $user['ClinicID'];
$walkInValues = ['first_name' => '', 'last_name' => '', 'contact_number' => '', 'email' => '', 'physician_id' => '', 'concern' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $formType = $_POST['form_type'] ?? '';

        if ($formType === 'register_walkin') {
            foreach ($walkInValues as $field => $value) $walkInValues[$field] = trim((string) ($_POST[$field] ?? ''));

            if ($walkInValues['first_name'] === '' || $walkInValues['last_name'] === '') $errors[] = 'First and last name are required.';
            if ($walkInValues['contact_number'] === '') $errors[] = 'Contact number is required.';
            if ($walkInValues['email'] !== '' && !filter_var($walkInValues['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address, or leave it blank.';

            if (!$errors) {
                try {
                    $pdo->beginTransaction();

                    $patientRoleStmt = $pdo->query("SELECT RoleID FROM Roles WHERE RoleName = 'Patient'");
                    $patientRoleId = (int) $patientRoleStmt->fetchColumn();

                    $patientId = null;
                    if ($walkInValues['email'] !== '') {
                        $existingStmt = $pdo->prepare('SELECT UserID, RoleID FROM Users WHERE Email = ?');
                        $existingStmt->execute([$walkInValues['email']]);
                        $existing = $existingStmt->fetch();
                        if ($existing) {
                            if ((int) $existing['RoleID'] !== $patientRoleId) {
                                $errors[] = 'That email already belongs to a non-patient account.';
                            } else {
                                $patientId = (int) $existing['UserID'];
                            }
                        }
                    }

                    if (!$errors && !$patientId) {
                        $email = $walkInValues['email'] !== '' ? $walkInValues['email'] : ('walkin.' . bin2hex(random_bytes(5)) . '@healthqueue.local');
                        $insertPatient = $pdo->prepare(
                            'INSERT INTO Users (RoleID, FirstName, LastName, Email, ContactNumber, PasswordHash, Status) VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $insertPatient->execute([
                            $patientRoleId, $walkInValues['first_name'], $walkInValues['last_name'], $email,
                            $walkInValues['contact_number'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Active',
                        ]);
                        $patientId = (int) $pdo->lastInsertId();
                    }

                    if (!$errors) {
                        $physicianId = $walkInValues['physician_id'] !== '' ? (int) $walkInValues['physician_id'] : null;
                        $insertAppt = $pdo->prepare(
                            "INSERT INTO Appointments (PatientID, ClinicID, PhysicianID, AppointmentDate, AppointmentTime, Concern, Status)
                             VALUES (?, ?, ?, CURDATE(), CURTIME(), ?, 'Confirmed')"
                        );
                        $insertAppt->execute([$patientId, $clinicId, $physicianId, $walkInValues['concern'] ?: null]);
                        $appointmentId = (int) $pdo->lastInsertId();

                        $queueNumStmt = $pdo->prepare('SELECT COALESCE(MAX(QueueNumber), 0) + 1 FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE()');
                        $queueNumStmt->execute([$clinicId]);
                        $queueNumber = (int) $queueNumStmt->fetchColumn();

                        $insertQueue = $pdo->prepare(
                            "INSERT INTO Queue (ClinicID, AppointmentID, PhysicianID, QueueNumber, Status, CreatedByStaffID) VALUES (?, ?, ?, ?, 'Waiting', ?)"
                        );
                        $insertQueue->execute([$clinicId, $appointmentId, $physicianId, $queueNumber, $user['UserID']]);

                        $pdo->commit();
                        logActivity($pdo, $user['UserID'], $clinicId, 'Registered walk-in patient', "{$walkInValues['first_name']} {$walkInValues['last_name']}, queue #{$queueNumber}");
                        $flash = "Walk-in registered as queue #{$queueNumber}.";
                        $walkInValues = ['first_name' => '', 'last_name' => '', 'contact_number' => '', 'email' => '', 'physician_id' => '', 'concern' => ''];
                    }

                    if ($errors && $pdo->inTransaction()) $pdo->rollBack();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Walk-in registration failed: ' . $e->getMessage());
                    $errors[] = 'We could not register this walk-in. Please try again.';
                }
            }
        } elseif (in_array($formType, ['call_patient', 'mark_serving', 'skip_forfeit', 'remove_from_queue'], true)) {
            $queueId = filter_input(INPUT_POST, 'queue_id', FILTER_VALIDATE_INT);
            $transitions = [
                'call_patient'      => ['from' => 'Waiting', 'to' => 'Calling', 'stamp' => 'CalledAt'],
                'mark_serving'      => ['from' => 'Calling', 'to' => 'Serving', 'stamp' => 'ServedAt'],
                'skip_forfeit'      => ['from' => 'Calling', 'to' => 'Forfeited_Late', 'stamp' => null],
                'remove_from_queue' => ['from' => null, 'to' => 'Removed', 'stamp' => null],
            ];
            $t = $transitions[$formType];

            if (!$queueId) {
                $errors[] = 'Invalid queue entry.';
            } else {
                try {
                    $sql = 'UPDATE Queue SET Status = ?' . ($t['stamp'] ? ", {$t['stamp']} = NOW()" : '') . ' WHERE QueueID = ? AND ClinicID = ?';
                    $params = [$t['to'], $queueId, $clinicId];
                    if ($t['from']) {
                        $sql .= ' AND Status = ?';
                        $params[] = $t['from'];
                    }
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    if ($stmt->rowCount()) {
                        logActivity($pdo, $user['UserID'], $clinicId, 'Updated queue status', "Queue #{$queueId} set to {$t['to']}");
                        if ($formType === 'call_patient') {
                            $patientStmt = $pdo->prepare(
                                'SELECT a.PatientID, a.AppointmentID FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID WHERE q.QueueID = ?'
                            );
                            $patientStmt->execute([$queueId]);
                            if ($row = $patientStmt->fetch()) {
                                notifyPatient($pdo, (int) $row['PatientID'], "You're being called now — please proceed to the counter.", (int) $row['AppointmentID']);
                            }
                        }
                        $flash = 'Queue updated.';
                    } else {
                        $errors[] = 'That queue entry could not be updated (it may have already changed).';
                    }
                } catch (PDOException $e) {
                    error_log('Queue transition failed: ' . $e->getMessage());
                    $errors[] = 'We could not update the queue.';
                }
            }
        } elseif ($formType === 'confirm_complete') {
            $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
            if (!$appointmentId) {
                $errors[] = 'Invalid appointment.';
            } else {
                try {
                    // Only allowed once the physician has actually finalized
                    // their notes -- staff confirm the visit is over, they
                    // don't write the notes.
                    $checkStmt = $pdo->prepare(
                        "SELECT a.PatientID, a.Status,
                                (SELECT COUNT(*) FROM ConsultationVersions v
                                 JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                                 WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized') AS FinalizedCount
                         FROM Appointments a
                         WHERE a.AppointmentID = ? AND a.ClinicID = ?"
                    );
                    $checkStmt->execute([$appointmentId, $clinicId]);
                    $target = $checkStmt->fetch();

                    if (!$target || $target['Status'] !== 'Confirmed') {
                        $errors[] = 'That consultation could not be found or is no longer awaiting confirmation.';
                    } elseif ((int) $target['FinalizedCount'] === 0) {
                        $errors[] = 'The physician has not finalized their notes for this visit yet.';
                    } else {
                        $pdo->prepare("UPDATE Appointments SET Status = 'Completed' WHERE AppointmentID = ? AND ClinicID = ?")
                            ->execute([$appointmentId, $clinicId]);
                        $pdo->prepare("UPDATE Queue SET Status = 'Completed' WHERE AppointmentID = ? AND Status IN ('Waiting', 'Calling', 'Serving')")
                            ->execute([$appointmentId]);
                        logActivity($pdo, $user['UserID'], $clinicId, 'Confirmed consultation complete', "Appointment #{$appointmentId}");
                        notifyPatient($pdo, (int) $target['PatientID'], 'Your consultation record is ready to view.', $appointmentId);
                        $flash = 'Consultation confirmed as complete.';
                    }
                } catch (PDOException $e) {
                    error_log('Confirm consultation complete failed: ' . $e->getMessage());
                    $errors[] = 'We could not confirm this consultation right now.';
                }
            }
        } elseif ($formType === 'reschedule_from_queue') {
            $queueId = filter_input(INPUT_POST, 'queue_id', FILTER_VALIDATE_INT);
            $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
            $newDate = trim((string) ($_POST['appointment_date'] ?? ''));
            $newTime = trim((string) ($_POST['appointment_time'] ?? ''));

            if (!$queueId || !$appointmentId || $newDate === '' || $newDate < date('Y-m-d') || $newTime === '') {
                $errors[] = 'Please choose a valid future date and time.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE Queue SET Status = ? WHERE QueueID = ? AND ClinicID = ?')->execute(['Removed', $queueId, $clinicId]);
                    $pdo->prepare('UPDATE Appointments SET AppointmentDate = ?, AppointmentTime = ? WHERE AppointmentID = ? AND ClinicID = ?')
                        ->execute([$newDate, $newTime, $appointmentId, $clinicId]);
                    $pdo->commit();
                    logActivity($pdo, $user['UserID'], $clinicId, 'Rescheduled from queue', "Appointment #{$appointmentId} to {$newDate} {$newTime}");
                    $flash = 'Appointment rescheduled and removed from today\'s queue.';
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Reschedule from queue failed: ' . $e->getMessage());
                    $errors[] = 'We could not reschedule this appointment.';
                }
            }
        }
    }
}

$queueEntries = [];
$physicians = [];
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $stmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.Status, q.AppointmentID, q.CalledAt, q.ServedAt,
                    a.AppointmentDate, a.AppointmentTime, a.Concern,
                    pat.FirstName, pat.LastName,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName,
                    (SELECT COUNT(*) FROM ConsultationVersions v
                     JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                     WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized') AS FinalizedCount
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE()
             ORDER BY q.QueueNumber"
        );
        $stmt->execute([$clinicId]);
        $queueEntries = $stmt->fetchAll();

        $physicians = $pdo->query(
            "SELECT UserID, FirstName, LastName FROM Users
             WHERE ClinicID = {$clinicId} AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
             ORDER BY LastName"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Queue list load failed: ' . $e->getMessage());
        $dataError = 'The queue is temporarily unavailable.';
    }
}

$walkInHasData = (bool) array_filter($walkInValues);

// Renders the "Today's line" queue list once, so both the full page and the
// periodic AJAX auto-refresh (below) share exactly the same markup.
ob_start();
if ($queueEntries):
?>
      <div class="compact-list">
        <?php foreach ($queueEntries as $entry): ?>
          <article style="align-items:flex-start;flex-direction:column;gap:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;width:100%;flex-wrap:wrap;gap:10px;">
              <div>
                <strong>#<?= (int) $entry['QueueNumber'] ?> — <?= htmlspecialchars($entry['FirstName'] . ' ' . $entry['LastName']) ?></strong>
                <span style="display:block;color:var(--slate-500);font-size:13px;">
                  <?= $entry['PhyFirstName'] ? 'Dr. ' . htmlspecialchars($entry['PhyFirstName'] . ' ' . $entry['PhyLastName']) : '<span class="unassigned">Unassigned</span>' ?>
                  <?= $entry['Concern'] ? ' — ' . htmlspecialchars($entry['Concern']) : '' ?>
                </span>
              </div>
              <span class="status-badge status-<?= strtolower($entry['Status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $entry['Status'])) ?></span>
            </div>

            <?php if (in_array($entry['Status'], ['Waiting', 'Calling', 'Serving'], true)): ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ((int) $entry['FinalizedCount'] > 0): ?>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="confirm_complete"><input type="hidden" name="appointment_id" value="<?= (int) $entry['AppointmentID'] ?>"><button class="btn btn-primary btn-sm">End Consultation</button></form>
                <?php elseif ($entry['Status'] === 'Waiting'): ?>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="call_patient"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>"><button class="btn btn-primary btn-sm">Call Patient</button></form>
                <?php elseif ($entry['Status'] === 'Calling'): ?>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="mark_serving"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>"><button class="btn btn-primary btn-sm">Mark as Serving</button></form>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="skip_forfeit"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>"><button class="btn btn-outline btn-sm" onclick="return confirm('Mark this patient as a late forfeit?');">Skip / Forfeit (Late)</button></form>
                <?php elseif ($entry['Status'] === 'Serving'): ?>
                  <span class="admin-empty" style="text-align:left;">Waiting on physician's finalized notes</span>
                <?php endif; ?>
                <?php if ($entry['Status'] !== 'Serving' && (int) $entry['FinalizedCount'] === 0): ?>
                  <button type="button" class="btn btn-ghost btn-sm view-concern-btn" data-target="resched-<?= (int) $entry['QueueID'] ?>">Reschedule from Queue</button>
                <?php endif; ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="remove_from_queue"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>"><button class="btn btn-outline btn-sm" onclick="return confirm('Remove this patient from the queue?');">Remove from Queue</button></form>
              </div>

              <form method="post" id="resched-<?= (int) $entry['QueueID'] ?>" style="display:none;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="form_type" value="reschedule_from_queue">
                <input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>">
                <input type="hidden" name="appointment_id" value="<?= (int) $entry['AppointmentID'] ?>">
                <input type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($entry['AppointmentDate']) ?>" required>
                <input type="time" name="appointment_time" value="<?= htmlspecialchars($entry['AppointmentTime']) ?>" required>
                <button type="submit" class="btn btn-outline btn-sm">Save new schedule</button>
              </form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
<?php
else:
?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>No one in the queue yet today</h3><p>Register a walk-in patient, or accept an appointment request to add someone to the line.</p></div>
<?php
endif;
$queueListHtml = ob_get_clean();

// Periodic auto-refresh (see the inline script near the bottom of this page)
// fetches this same URL with an AJAX header and expects just this fragment
// back, so front-desk staff see new walk-ins / queue changes without having
// to manually reload the page.
$isAjax = $_SERVER['REQUEST_METHOD'] === 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
if ($isAjax) {
    echo $queueListHtml;
    exit;
}

$pageTitle = 'Queue Management — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Front desk</span><h1>Queue Management</h1><p>Today's walk-in and confirmed patients, in line order.</p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section" id="walk-in">
    <div class="portal-heading"><div><span class="section-kicker">Front desk</span><h2>Register Walk-in Patient</h2></div></div>
    <button type="button" class="btn btn-primary" data-modal-open="registerWalkinModal">Register Walk-in Patient</button>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Today's line</span><h2>Queue</h2></div></div>
    <div id="queueListContainer"><?= $queueListHtml ?></div>
  </section>
</div></main>

<div class="modal-overlay" id="registerWalkinModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Register Walk-in Patient</h2>
    <form class="registration-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="register_walkin">
      <div class="form-stack form-cols-2">
        <label>First name<input type="text" name="first_name" value="<?= htmlspecialchars($walkInValues['first_name']) ?>" required></label>
        <label>Last name<input type="text" name="last_name" value="<?= htmlspecialchars($walkInValues['last_name']) ?>" required></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Contact number<input type="text" name="contact_number" value="<?= htmlspecialchars($walkInValues['contact_number']) ?>" required></label>
        <label>Email <span class="optional">(optional)</span><input type="email" name="email" value="<?= htmlspecialchars($walkInValues['email']) ?>"></label>
      </div>
      <div class="form-stack">
        <label>Physician <span class="optional">(optional)</span>
          <select name="physician_id">
            <option value="">No preference</option>
            <?php foreach ($physicians as $physician): ?>
              <option value="<?= (int) $physician['UserID'] ?>" <?= $walkInValues['physician_id'] === (string) $physician['UserID'] ? 'selected' : '' ?>>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Reason for visit <span class="optional">(optional)</span><textarea name="concern" rows="3"><?= htmlspecialchars($walkInValues['concern']) ?></textarea></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Add to Queue</button>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Event-delegated so it keeps working on buttons that arrive later via the
  // auto-refresh below (a direct querySelectorAll/addEventListener pass would
  // only ever see the buttons present at page load).
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.view-concern-btn');
    if (!btn) return;
    var panel = document.getElementById(btn.getAttribute('data-target'));
    if (!panel) return;
    panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
  });

  <?php if ($walkInHasData): ?>
  window.hqOpenModal(document.getElementById('registerWalkinModal'));
  <?php endif; ?>

  // Auto-refresh the queue list so front-desk staff see new walk-ins and
  // changes made by other staff without manually reloading the page. Skipped
  // whenever a reschedule panel is open, so it never wipes out an in-progress
  // edit.
  var queueListContainer = document.getElementById('queueListContainer');
  if (queueListContainer) {
    setInterval(function () {
      if (queueListContainer.querySelector('form[id^="resched-"][style*="flex"]')) return;
      fetch(window.location.href, { headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.text(); })
        .then(function (html) { queueListContainer.innerHTML = html; })
        .catch(function () { /* silent -- try again on the next tick */ });
    }, 20000);
  }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
