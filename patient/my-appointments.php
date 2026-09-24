<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wallet.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

$tabs = [
    'pending'  => 'Pending',
    'approved' => 'Confirmed',
    'rejected' => 'Cancelled',
    'done'     => 'Completed',
];
$activeTab = $_GET['tab'] ?? 'pending';
if (!isset($tabs[$activeTab])) {
    $activeTab = 'pending';
}

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
        } elseif ($formType === 'cancel_appointment') {
            $cancellationReason = trim((string) ($_POST['cancellation_reason'] ?? ''));
            if ($cancellationReason === '') {
                $errors[] = 'Please choose a reason for cancelling.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $lookupStmt = $pdo->prepare(
                        "SELECT a.BookingFeePaid, c.ClinicName, c.BaseConsultationFee
                         FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID
                         WHERE a.AppointmentID = ? AND a.PatientID = ? AND a.Status IN ('Pending', 'Confirmed')
                         FOR UPDATE"
                    );
                    $lookupStmt->execute([$appointmentId, $user['UserID']]);
                    $target = $lookupStmt->fetch();

                    if (!$target) {
                        $pdo->rollBack();
                        $errors[] = 'That appointment could not be cancelled.';
                    } else {
                        $pdo->prepare("UPDATE Appointments SET Status = 'Cancelled', CancellationReason = ? WHERE AppointmentID = ? AND PatientID = ?")
                            ->execute([$cancellationReason, $appointmentId, $user['UserID']]);

                        if ($target['BookingFeePaid']) {
                            walletRefund(
                                $pdo,
                                $user['UserID'],
                                round((float) $target['BaseConsultationFee'], 2),
                                $appointmentId,
                                'Refund for cancelled booking at ' . $target['ClinicName']
                            );
                        }

                        $pdo->commit();
                        $flash = $target['BookingFeePaid']
                            ? 'Appointment cancelled. Your booking fee was refunded to your wallet.'
                            : 'Appointment cancelled.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Patient cancel failed: ' . $e->getMessage());
                    $errors[] = 'We could not cancel this appointment.';
                }
            }
        } elseif ($formType === 'reschedule_appointment') {
            $newDate = trim((string) ($_POST['appointment_date'] ?? ''));
            $newTime = trim((string) ($_POST['appointment_time'] ?? ''));
            if ($newDate === '' || $newDate < date('Y-m-d') || $newTime === '') {
                $errors[] = 'Please choose a valid future date and time.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE Appointments SET AppointmentDate = ?, AppointmentTime = ? WHERE AppointmentID = ? AND PatientID = ? AND Status = 'Pending'");
                    $stmt->execute([$newDate, $newTime, $appointmentId, $user['UserID']]);
                    if ($stmt->rowCount()) {
                        $flash = 'Appointment rescheduled.';
                    } else {
                        $errors[] = 'Only pending requests can be rescheduled from here.';
                    }
                } catch (PDOException $e) {
                    error_log('Patient reschedule failed: ' . $e->getMessage());
                    $errors[] = 'We could not reschedule this appointment.';
                }
            }
        }
    }
}

$appointments = [];
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.Status, a.BookingFeePaid, a.CancellationReason,
                    c.ClinicName, c.Address, c.BaseConsultationFee,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.PatientID = ? AND a.Status = ?
             ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC"
        );
        $stmt->execute([$user['UserID'], $tabs[$activeTab]]);
        $appointments = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('My appointments load failed: ' . $e->getMessage());
        $dataError = 'Your appointments are temporarily unavailable.';
    }
}

$pageTitle = 'My Appointments — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">My schedule</span><h1>My Appointments</h1><p>Every request you've made, past and present.</p></div></section>

  <?php if (isset($_GET['paid'])): ?><p class="form-message success" role="status">Payment received — your request has been sent to the clinic.</p><?php endif; ?>
  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;">
      <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'done' => 'Done'] as $key => $label): ?>
        <a href="?tab=<?= $key ?>" class="btn btn-sm <?= $activeTab === $key ? 'btn-primary' : 'btn-outline' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($appointments): ?>
      <div class="compact-list">
        <?php foreach ($appointments as $appt): ?>
          <article id="appt-<?= (int) $appt['AppointmentID'] ?>" style="align-items:flex-start;flex-direction:column;gap:10px;">
            <div style="display:flex;justify-content:space-between;width:100%;flex-wrap:wrap;gap:10px;">
              <div>
                <strong><?= htmlspecialchars($appt['ClinicName']) ?></strong>
                <span style="display:block;color:var(--slate-500);font-size:13px;"><?= htmlspecialchars(date('M j, Y', strtotime($appt['AppointmentDate']))) ?> at <?= htmlspecialchars(date('g:i A', strtotime($appt['AppointmentTime']))) ?></span>
              </div>
              <div style="display:flex;gap:8px;align-items:center;">
                <?php if ($appt['Status'] === 'Pending' && !$appt['BookingFeePaid']): ?>
                  <a href="<?= HQ_BASE_URL ?>/patient/checkout.php?appointment_id=<?= (int) $appt['AppointmentID'] ?>" class="btn btn-primary btn-sm">Complete Payment</a>
                <?php endif; ?>
                <button type="button" class="btn btn-ghost btn-sm view-concern-btn" data-target="details-<?= (int) $appt['AppointmentID'] ?>">View Appointment Details</button>
              </div>
            </div>

            <div id="details-<?= (int) $appt['AppointmentID'] ?>" class="dev-note" style="display:none;width:100%;">
              <strong>Physician:</strong> <?= $appt['PhyFirstName'] ? htmlspecialchars('Dr. ' . $appt['PhyFirstName'] . ' ' . $appt['PhyLastName']) : 'Not yet assigned' ?><br>
              <strong>Address:</strong> <?= htmlspecialchars($appt['Address']) ?><br>
              <strong>Reason:</strong> <?= htmlspecialchars($appt['Concern'] ?: 'Not provided') ?><br>
              <strong>Consultation fee:</strong> PHP <?= number_format((float) $appt['BaseConsultationFee'], 2) ?><br>
              <strong>Booking fee:</strong> <?= $appt['BookingFeePaid'] ? 'Paid' : 'Not yet paid' ?>
              <?php if ($appt['Status'] === 'Cancelled' && $appt['CancellationReason']): ?><br><strong>Cancellation reason:</strong> <?= htmlspecialchars($appt['CancellationReason']) ?><?php endif; ?>
            </div>

            <?php if ($appt['Status'] === 'Pending'): ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn btn-outline btn-sm view-concern-btn" data-target="resched-<?= (int) $appt['AppointmentID'] ?>">Reschedule Appointment</button>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="cancel_appointment"><input type="hidden" name="appointment_id" value="<?= (int) $appt['AppointmentID'] ?>"><input type="hidden" name="cancellation_reason"><button type="button" class="btn btn-outline btn-sm" data-confirm-modal="cancelConfirmModal">Cancel Appointment</button></form>
              </div>
              <form method="post" id="resched-<?= (int) $appt['AppointmentID'] ?>" style="display:none;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="form_type" value="reschedule_appointment">
                <input type="hidden" name="appointment_id" value="<?= (int) $appt['AppointmentID'] ?>">
                <input type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($appt['AppointmentDate']) ?>" required>
                <input type="time" name="appointment_time" value="<?= htmlspecialchars($appt['AppointmentTime']) ?>" required>
                <button type="submit" class="btn btn-outline btn-sm">Save new schedule</button>
              </form>
            <?php elseif ($appt['Status'] === 'Confirmed'): ?>
              <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="cancel_appointment"><input type="hidden" name="appointment_id" value="<?= (int) $appt['AppointmentID'] ?>"><input type="hidden" name="cancellation_reason"><button type="button" class="btn btn-outline btn-sm" data-confirm-modal="cancelConfirmModal">Cancel Appointment</button></form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>Nothing here yet</h3><p>Appointments in this state will show up here.</p></div>
    <?php endif; ?>
  </section>
</div></main>

<div class="modal-overlay" id="cancelConfirmModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Cancel this appointment request?</h2>
    <p class="modal-subtitle">This can't be undone. Any booking fee you've already paid will be refunded to your wallet.</p>
    <div id="cancelReasonError" class="form-message error" role="alert" style="display:none;">Please choose a reason for cancelling.</div>
    <div class="form-stack" style="text-align:left;">
      <label>Reason for cancellation
        <select data-confirm-field="cancellation_reason" id="cancelReasonSelect">
          <option value="">Select a reason</option>
          <option value="Schedule conflict">Schedule conflict</option>
          <option value="Found another clinic or physician">Found another clinic or physician</option>
          <option value="No longer needed">No longer needed</option>
          <option value="Booked by mistake">Booked by mistake</option>
          <option value="Financial reasons">Financial reasons</option>
          <option value="Others" data-other="true">Others</option>
        </select>
      </label>
      <label id="cancelReasonOtherWrap" style="display:none;">Please specify
        <textarea data-confirm-field-for="cancellation_reason" id="cancelReasonOtherInput" rows="2" placeholder="Tell us more..."></textarea>
      </label>
    </div>
    <div style="display:flex;gap:10px;margin-top:8px;">
      <button type="button" class="btn btn-outline btn-block" data-modal-close>Keep Appointment</button>
      <button type="button" class="btn btn-primary btn-block" id="cancelConfirmSubmitBtn" data-confirm-submit>Yes, Cancel</button>
    </div>
  </div>
</div>

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

  var reasonSelect = document.getElementById('cancelReasonSelect');
  var reasonOtherWrap = document.getElementById('cancelReasonOtherWrap');
  var reasonOtherInput = document.getElementById('cancelReasonOtherInput');
  var reasonError = document.getElementById('cancelReasonError');
  var reasonSubmitBtn = document.getElementById('cancelConfirmSubmitBtn');

  function isOtherSelected() {
    var opt = reasonSelect ? reasonSelect.options[reasonSelect.selectedIndex] : null;
    return !!(opt && opt.getAttribute('data-other') === 'true');
  }

  if (reasonSelect && reasonOtherWrap) {
    reasonSelect.addEventListener('change', function () {
      reasonOtherWrap.style.display = isOtherSelected() ? 'block' : 'none';
      if (reasonError) reasonError.style.display = 'none';
    });
  }

  // Reset the reason fields every time the modal is opened for a
  // (possibly different) appointment, so a stale choice never carries over.
  document.querySelectorAll('[data-confirm-modal="cancelConfirmModal"]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      if (reasonSelect) reasonSelect.value = '';
      if (reasonOtherInput) reasonOtherInput.value = '';
      if (reasonOtherWrap) reasonOtherWrap.style.display = 'none';
      if (reasonError) reasonError.style.display = 'none';
    });
  });

  // Registered before the generic confirm-modal handler assigns its own
  // onclick, so this runs first and can block the submit with
  // stopImmediatePropagation() when no reason has been chosen.
  if (reasonSubmitBtn) {
    reasonSubmitBtn.addEventListener('click', function (e) {
      var hasReason = reasonSelect && reasonSelect.value !== '';
      var hasOtherText = !isOtherSelected() || (reasonOtherInput && reasonOtherInput.value.trim() !== '');
      if (!hasReason || !hasOtherText) {
        e.stopImmediatePropagation();
        if (reasonError) reasonError.style.display = 'block';
      }
    });
  }
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
