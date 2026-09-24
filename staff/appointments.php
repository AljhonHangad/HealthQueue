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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $formType = $_POST['form_type'] ?? '';
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

        if (!$appointmentId) {
            $errors[] = 'Invalid appointment.';
        } elseif ($formType === 'accept_request') {
            $physicianId = filter_input(INPUT_POST, 'physician_id', FILTER_VALIDATE_INT) ?: null;
            try {
                // Scoped to this staff member's own clinic so one clinic's
                // staff can never touch another clinic's appointment requests.
                $checkStmt = $pdo->prepare("SELECT PhysicianID, PatientID, AppointmentDate FROM Appointments WHERE AppointmentID = ? AND ClinicID = ? AND Status = 'Pending'");
                $checkStmt->execute([$appointmentId, $clinicId]);
                $existing = $checkStmt->fetch();

                if (!$existing) {
                    $errors[] = 'That request could not be found.';
                } elseif (!$physicianId && !$existing['PhysicianID']) {
                    $errors[] = 'Please assign a physician before accepting this request.';
                } else {
                    if ($physicianId) {
                        $physCheck = $pdo->prepare(
                            "SELECT UserID FROM Users WHERE UserID = ? AND ClinicID = ?
                             AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician')"
                        );
                        $physCheck->execute([$physicianId, $clinicId]);
                        if (!$physCheck->fetch()) {
                            $errors[] = 'Please choose a physician from your clinic.';
                        }
                    }
                    if (!$errors) {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("UPDATE Appointments SET Status = 'Confirmed', PhysicianID = COALESCE(?, PhysicianID) WHERE AppointmentID = ? AND ClinicID = ?");
                        $stmt->execute([$physicianId, $appointmentId, $clinicId]);

                        // Accepting a request is the moment it joins the day's
                        // queue -- walk-ins get a queue number at registration,
                        // so an accepted online request needs the same step.
                        $queueNumStmt = $pdo->prepare('SELECT COALESCE(MAX(QueueNumber), 0) + 1 FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE()');
                        $queueNumStmt->execute([$clinicId]);
                        $queueNumber = (int) $queueNumStmt->fetchColumn();

                        $insertQueue = $pdo->prepare(
                            "INSERT INTO Queue (ClinicID, AppointmentID, PhysicianID, QueueNumber, Status, CreatedByStaffID) VALUES (?, ?, ?, ?, 'Waiting', ?)"
                        );
                        $insertQueue->execute([$clinicId, $appointmentId, $physicianId ?: $existing['PhysicianID'], $queueNumber, $user['UserID']]);

                        $pdo->commit();
                        logActivity($pdo, $user['UserID'], $clinicId, 'Accepted appointment request', "Appointment #{$appointmentId}, queue #{$queueNumber}");
                        notifyPatient($pdo, (int) $existing['PatientID'], 'Your appointment on ' . $existing['AppointmentDate'] . ' has been confirmed. Your queue number is #' . $queueNumber . '.', $appointmentId);
                        $flash = "Appointment request accepted as queue #{$queueNumber}.";
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Accept request failed: ' . $e->getMessage());
                $errors[] = 'We could not accept this request.';
            }
        } elseif ($formType === 'reject_request') {
            try {
                $patientStmt = $pdo->prepare('SELECT PatientID FROM Appointments WHERE AppointmentID = ? AND ClinicID = ?');
                $patientStmt->execute([$appointmentId, $clinicId]);
                $patientId = (int) $patientStmt->fetchColumn();

                $stmt = $pdo->prepare("UPDATE Appointments SET Status = 'Cancelled' WHERE AppointmentID = ? AND ClinicID = ? AND Status = 'Pending'");
                $stmt->execute([$appointmentId, $clinicId]);
                if ($stmt->rowCount()) {
                    logActivity($pdo, $user['UserID'], $clinicId, 'Rejected appointment request', "Appointment #{$appointmentId}");
                    if ($patientId) notifyPatient($pdo, $patientId, 'Your appointment request was declined by the clinic.', $appointmentId);
                    $flash = 'Appointment request rejected.';
                } else {
                    $errors[] = 'That request could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Reject request failed: ' . $e->getMessage());
                $errors[] = 'We could not reject this request.';
            }
        } elseif ($formType === 'reschedule_request') {
            $newDate = trim((string) ($_POST['appointment_date'] ?? ''));
            $newTime = trim((string) ($_POST['appointment_time'] ?? ''));
            if ($newDate === '' || $newDate < date('Y-m-d') || $newTime === '') {
                $errors[] = 'Please choose a valid future date and time.';
            } else {
                try {
                    $patientStmt = $pdo->prepare('SELECT PatientID FROM Appointments WHERE AppointmentID = ? AND ClinicID = ?');
                    $patientStmt->execute([$appointmentId, $clinicId]);
                    $patientId = (int) $patientStmt->fetchColumn();

                    $stmt = $pdo->prepare('UPDATE Appointments SET AppointmentDate = ?, AppointmentTime = ? WHERE AppointmentID = ? AND ClinicID = ?');
                    $stmt->execute([$newDate, $newTime, $appointmentId, $clinicId]);
                    if ($stmt->rowCount()) {
                        logActivity($pdo, $user['UserID'], $clinicId, 'Rescheduled appointment', "Appointment #{$appointmentId} to {$newDate} {$newTime}");
                        if ($patientId) notifyPatient($pdo, $patientId, 'Your appointment was rescheduled to ' . $newDate . ' ' . $newTime . '.', $appointmentId);
                        $flash = 'Appointment rescheduled.';
                    } else {
                        $errors[] = 'That appointment could not be found.';
                    }
                } catch (PDOException $e) {
                    error_log('Reschedule failed: ' . $e->getMessage());
                    $errors[] = 'We could not reschedule this appointment.';
                }
            }
        } elseif ($formType === 'confirm_complete') {
            try {
                // Scoped to this staff member's own clinic, and only allowed
                // once the physician has actually finalized their notes --
                // staff confirm the visit is over, they don't write the notes.
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
    }
}

$requests = [];
$physicians = [];
$pendingConfirmations = [];
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.PhysicianID,
                    pat.UserID AS PatientUserID, pat.FirstName, pat.LastName, pat.Email, pat.ContactNumber, pat.CreatedAt AS PatientSince,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.ClinicID = ? AND a.Status = 'Pending' AND a.BookingFeePaid = 1
             ORDER BY a.AppointmentDate, a.AppointmentTime"
        );
        $stmt->execute([$clinicId]);
        $requests = $stmt->fetchAll();

        foreach ($requests as &$request) {
            $historyStmt = $pdo->prepare('SELECT COUNT(*) FROM Appointments WHERE PatientID = ? AND ClinicID = ?');
            $historyStmt->execute([$request['PatientUserID'], $clinicId]);
            $request['VisitCount'] = (int) $historyStmt->fetchColumn();
        }
        unset($request);

        $physicians = $pdo->query(
            "SELECT UserID, FirstName, LastName FROM Users
             WHERE ClinicID = {$clinicId} AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
             ORDER BY LastName"
        )->fetchAll();

        $confirmStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime,
                    pat.FirstName, pat.LastName,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName,
                    (SELECT v.UpdatedAt FROM ConsultationVersions v
                     JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                     WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized'
                     ORDER BY v.RevisionNumber DESC LIMIT 1) AS FinalizedAt
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.ClinicID = ? AND a.Status = 'Confirmed'
               AND EXISTS (
                   SELECT 1 FROM ConsultationVersions v
                   JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                   WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized'
               )
             ORDER BY a.AppointmentDate, a.AppointmentTime"
        );
        $confirmStmt->execute([$clinicId]);
        $pendingConfirmations = $confirmStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Appointments list load failed: ' . $e->getMessage());
        $dataError = 'Appointments are temporarily unavailable.';
    }
}

$pageTitle = 'Appointments — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="admin-section admin-overview-grid">
    <div class="overview-card">
      <div class="portal-heading"><div><span class="section-kicker">Requests</span><h2>Appointment Requests <span class="status-badge" style="vertical-align:middle;"><?= count($requests) ?></span></h2></div></div>
      <?php if ($requests): ?>
        <div class="compact-list">
          <?php foreach ($requests as $request): ?>
            <article style="align-items:flex-start;flex-direction:column;gap:12px;">
              <div style="display:flex;justify-content:space-between;width:100%;flex-wrap:wrap;gap:10px;">
                <div>
                  <strong><?= htmlspecialchars($request['FirstName'] . ' ' . $request['LastName']) ?></strong>
                  <span style="display:block;color:var(--slate-500);font-size:13px;"><?= htmlspecialchars(date('M j, Y', strtotime($request['AppointmentDate']))) ?> at <?= htmlspecialchars(date('g:i A', strtotime($request['AppointmentTime']))) ?><?= $request['Concern'] ? ' — ' . htmlspecialchars($request['Concern']) : '' ?></span>
                </div>
                <button type="button" class="btn btn-ghost btn-sm view-concern-btn" data-target="patient-<?= (int) $request['AppointmentID'] ?>">View Patient Details</button>
              </div>

              <div id="patient-<?= (int) $request['AppointmentID'] ?>" class="dev-note" style="display:none;width:100%;">
                <strong>Email:</strong> <?= htmlspecialchars($request['Email']) ?><br>
                <strong>Contact:</strong> <?= htmlspecialchars($request['ContactNumber'] ?: 'Not provided') ?><br>
                <strong>Patient since:</strong> <?= htmlspecialchars(date('M j, Y', strtotime($request['PatientSince']))) ?><br>
                <strong>Visits at this clinic:</strong> <?= (int) $request['VisitCount'] ?>
              </div>

              <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;width:100%;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="appointment_id" value="<?= (int) $request['AppointmentID'] ?>">
                <select name="physician_id" style="flex:1;min-width:180px;">
                  <option value="">— No preference / not yet assigned —</option>
                  <?php foreach ($physicians as $physician): ?>
                    <option value="<?= (int) $physician['UserID'] ?>" <?= (int) $request['PhysicianID'] === (int) $physician['UserID'] ? 'selected' : '' ?>>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" name="form_type" value="accept_request" class="btn btn-primary btn-sm">Accept Request</button>
                <button type="submit" name="form_type" value="reject_request" class="btn btn-outline btn-sm" onclick="return confirm('Reject this appointment request?');">Reject Request</button>
              </form>

              <button type="button" class="btn btn-ghost btn-sm view-concern-btn" data-target="reschedule-<?= (int) $request['AppointmentID'] ?>" style="padding-left:0;">Reschedule Appointment</button>
              <form method="post" id="reschedule-<?= (int) $request['AppointmentID'] ?>" style="display:none;gap:8px;flex-wrap:wrap;align-items:center;width:100%;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="appointment_id" value="<?= (int) $request['AppointmentID'] ?>">
                <input type="hidden" name="form_type" value="reschedule_request">
                <input type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($request['AppointmentDate']) ?>" required>
                <input type="time" name="appointment_time" value="<?= htmlspecialchars($request['AppointmentTime']) ?>" required>
                <button type="submit" class="btn btn-outline btn-sm">Save new schedule</button>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="admin-empty">No pending requests. New appointment requests for your clinic will appear here.</p>
      <?php endif; ?>
    </div>

    <div class="overview-card">
      <div class="portal-heading"><div><span class="section-kicker">Approvals</span><h2>Consultation Approvals <span class="status-badge" style="vertical-align:middle;"><?= count($pendingConfirmations) ?></span></h2></div></div>
      <?php if ($pendingConfirmations): ?>
        <div class="compact-list">
          <?php foreach ($pendingConfirmations as $item): ?>
            <article>
              <div>
                <strong><?= htmlspecialchars($item['FirstName'] . ' ' . $item['LastName']) ?></strong>
                <span><?= $item['PhyFirstName'] ? 'Dr. ' . htmlspecialchars($item['PhyFirstName'] . ' ' . $item['PhyLastName']) : 'No physician' ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($item['AppointmentDate']))) ?><?= $item['FinalizedAt'] ? ' — notes finalized ' . htmlspecialchars(date('M j, g:i A', strtotime($item['FinalizedAt']))) : '' ?></span>
              </div>
              <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="confirm_complete"><input type="hidden" name="appointment_id" value="<?= (int) $item['AppointmentID'] ?>"><button class="btn btn-primary btn-sm">Confirm Complete</button></form>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="admin-empty">Nothing awaiting confirmation. Once a physician finalizes a visit's notes, it will appear here.</p>
      <?php endif; ?>
    </div>
  </section>
</div></main>

<script>
(function () {
  document.querySelectorAll('.view-concern-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var panel = document.getElementById(btn.getAttribute('data-target'));
      if (!panel) return;
      var showing = panel.style.display !== 'none';
      panel.style.display = showing ? 'none' : (panel.tagName === 'FORM' ? 'flex' : 'block');
    });
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
