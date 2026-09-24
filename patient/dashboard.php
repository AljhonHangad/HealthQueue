<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$appointments = [];
$clinics = [];
$allClinics = [];
$physicians = [];
$records = [];
$unpaidCount = 0;
$queueEntries = [];
$minutesPerPatient = 15;
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Status, c.ClinicName, c.Address FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID WHERE a.PatientID = ? AND a.AppointmentDate >= CURDATE() ORDER BY a.AppointmentDate, a.AppointmentTime LIMIT 5");
        $stmt->execute([$user['UserID']]);
        $appointments = $stmt->fetchAll();

        $clinics = $pdo->query("SELECT ClinicID, ClinicName, Address, BaseConsultationFee, PhotoUrl FROM Clinic WHERE archived = 0 AND Status = 'Active' ORDER BY ClinicName LIMIT 6")->fetchAll();
        $allClinics = $pdo->query("SELECT ClinicID, ClinicName, Address, BaseConsultationFee FROM Clinic WHERE archived = 0 AND Status = 'Active' ORDER BY ClinicName")->fetchAll();
        $physicians = $pdo->query(
            "SELECT u.UserID, u.ClinicID, u.FirstName, u.LastName, c.ClinicName
             FROM Users u JOIN Roles r ON r.RoleID = u.RoleID JOIN Clinic c ON c.ClinicID = u.ClinicID
             WHERE r.RoleName = 'Physician' AND u.Status = 'Active' AND u.DeletedAt IS NULL AND c.archived = 0 AND c.Status = 'Active'
             ORDER BY c.ClinicName, u.LastName"
        )->fetchAll();

        $unpaidStmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE PatientID = ? AND Status = 'Pending' AND BookingFeePaid = 0");
        $unpaidStmt->execute([$user['UserID']]);
        $unpaidCount = (int) $unpaidStmt->fetchColumn();

        $recordsStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, c.ClinicName, u.FirstName, u.LastName
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users u ON u.UserID = a.PhysicianID
             WHERE a.PatientID = ? AND a.Status = 'Completed'
             ORDER BY a.AppointmentDate DESC LIMIT 5"
        );
        $recordsStmt->execute([$user['UserID']]);
        $records = $recordsStmt->fetchAll();

        $queueStmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.Status, q.ClinicID, c.ClinicName, c.Address,
                    a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Clinic c ON c.ClinicID = q.ClinicID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE a.PatientID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')
             ORDER BY q.CreatedAt DESC"
        );
        $queueStmt->execute([$user['UserID']]);
        $queueEntries = $queueStmt->fetchAll();

        foreach ($queueEntries as &$queueEntry) {
            $posStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND Status = 'Waiting' AND QueueNumber < ?"
            );
            $posStmt->execute([$queueEntry['ClinicID'], $queueEntry['QueueNumber']]);
            $queueEntry['AheadCount'] = (int) $posStmt->fetchColumn();
            $queueEntry['EstimatedWaitMinutes'] = $queueEntry['Status'] === 'Waiting' ? $queueEntry['AheadCount'] * $minutesPerPatient : 0;
        }
        unset($queueEntry);
    } catch (PDOException $e) {
        error_log('Patient dashboard failed: ' . $e->getMessage());
        $dataError = 'Your appointment information is temporarily unavailable.';
    }
} else {
    $dataError = 'Your appointment information is temporarily unavailable.';
}
$pageTitle = 'Patient Dashboard — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <div class="plain-page-header">
    <h1>Good day, <?= htmlspecialchars($user['FirstName']) ?></h1>
    <button type="button" class="btn btn-primary" data-modal-open="bookNowModal">Book Now</button>
  </div>
  <?php if (isset($_GET['booked'])): ?><p class="form-message success" role="status">Your appointment request was submitted. The clinic will confirm it soon.</p><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>
  <?php if ($unpaidCount > 0): ?><p class="form-message error" role="alert">You have <?= $unpaidCount ?> request(s) awaiting payment. <a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php">Complete payment to send them to the clinic &rarr;</a></p><?php endif; ?>

  <section class="portal-section">
    <div class="dashboard-top-grid">
      <div>
        <?php foreach ($queueEntries as $entry):
          $physicianLabel = $entry['PhyFirstName'] ? 'Dr. ' . $entry['PhyFirstName'] . ' ' . $entry['PhyLastName'] : 'No physician preference';
          $whenLabel = date('l, F j, Y', strtotime($entry['AppointmentDate'])) . ' at ' . date('g:i A', strtotime($entry['AppointmentTime']));
        ?>
          <div class="queue-hero-card" role="button" tabindex="0" data-queue-detail
               data-number="<?= (int) $entry['QueueNumber'] ?>"
               data-clinic="<?= htmlspecialchars($entry['ClinicName'], ENT_QUOTES) ?>"
               data-address="<?= htmlspecialchars($entry['Address'], ENT_QUOTES) ?>"
               data-status="<?= htmlspecialchars($entry['Status'], ENT_QUOTES) ?>"
               data-when="<?= htmlspecialchars($whenLabel, ENT_QUOTES) ?>"
               data-physician="<?= htmlspecialchars($physicianLabel, ENT_QUOTES) ?>"
               data-concern="<?= htmlspecialchars($entry['Concern'] ?: 'Not specified', ENT_QUOTES) ?>">
            <div class="queue-hero-top">
              <span class="queue-hero-label">Queue</span>
              <span class="queue-hero-live">Live</span>
            </div>
            <div class="queue-hero-number">#<?= (int) $entry['QueueNumber'] ?></div>
            <div class="queue-hero-meta">
              <?php if ($entry['Status'] === 'Waiting'): ?>
                <?= (int) $entry['AheadCount'] ?> patient(s) ahead &middot; ~<?= (int) $entry['EstimatedWaitMinutes'] ?> mins
              <?php elseif ($entry['Status'] === 'Calling'): ?>
                You're being called now — please proceed to the counter.
              <?php else: ?>
                You're currently being served.
              <?php endif; ?>
            </div>
            <div class="queue-hero-clinic"><?= htmlspecialchars($entry['ClinicName']) ?></div>
            <a href="<?= HQ_BASE_URL ?>/patient/queue-status.php" class="text-link" onclick="event.stopPropagation();">Live status <span>→</span></a>
          </div>
        <?php endforeach; ?>

        <?php if (!$queueEntries): ?>
          <div class="queue-empty-card">
            <span class="queue-hero-label">Queue</span>
            <h3>No queue for today</h3>
            <p>Once a clinic checks you in for a walk-in visit or a confirmed appointment today, your live position will appear here.</p>
          </div>
        <?php endif; ?>

        <div class="clock-card">
          <div class="clock-day" id="dashClockDay"></div>
          <div class="clock-time" id="dashClockTime"></div>
          <div class="clock-date" id="dashClockDate"></div>
        </div>
      </div>

      <div>
        <div class="portal-heading"><div><span class="section-kicker">My schedule</span><h2>Upcoming appointments</h2></div><a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php" class="text-link">View All <span>→</span></a></div>
        <?php if ($appointments): ?><div class="appointment-list"><?php foreach ($appointments as $appointment): ?><article class="appointment-card"><div class="appointment-date"><strong><?= htmlspecialchars(date('d', strtotime($appointment['AppointmentDate']))) ?></strong><span><?= htmlspecialchars(date('M', strtotime($appointment['AppointmentDate']))) ?></span></div><div class="appointment-info"><h3><?= htmlspecialchars($appointment['ClinicName']) ?></h3><p><?= htmlspecialchars(date('l, F j', strtotime($appointment['AppointmentDate']))) ?> · <?= htmlspecialchars(date('g:i A', strtotime($appointment['AppointmentTime']))) ?></p><span><?= htmlspecialchars($appointment['Address']) ?></span></div><span class="status-badge status-<?= strtolower(htmlspecialchars($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span></article><?php endforeach; ?></div><?php else: ?><div class="empty-state"><div class="empty-icon">+</div><h3>No upcoming appointments</h3><p>Choose a partner clinic and request a schedule that works for you.</p><a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="btn btn-primary btn-sm">Find a clinic</a></div><?php endif; ?>
      </div>
    </div>
  </section>
  <section class="portal-section"><div class="portal-heading"><div><span class="section-kicker">Records</span><h2>Consultation records</h2></div><a href="<?= HQ_BASE_URL ?>/patient/medical-records.php" class="text-link">View All <span>→</span></a></div>
  <?php if ($records): ?><div class="compact-list"><?php foreach ($records as $record): ?><article><div><strong><?= htmlspecialchars($record['ClinicName']) ?></strong><span>Dr. <?= htmlspecialchars($record['FirstName'] . ' ' . $record['LastName']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($record['AppointmentDate']))) ?></span></div><div style="display:flex;gap:14px;align-items:center;"><button type="button" class="text-link" data-view-record="<?= (int) $record['AppointmentID'] ?>">View record <span>&rarr;</span></button><button type="button" class="text-link" data-view-record="<?= (int) $record['AppointmentID'] ?>" data-scroll-to="ai-summary">AI summary <span>&rarr;</span></button></div></article><?php endforeach; ?></div><?php else: ?><p class="admin-empty">No completed consultations yet. Records appear here once a visit is complete.</p><?php endif; ?></section>
  <section class="portal-section clinics-band"><div class="portal-heading"><div><span class="section-kicker">Care near you</span><h2>Browse Nearby Clinics</h2></div><a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="text-link">View all clinics <span>→</span></a></div><div class="portal-clinics"><?php foreach ($clinics as $clinic): ?><article class="portal-clinic-card"><?php if (!empty($clinic['PhotoUrl'])): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="" class="clinic-photo-block"><?php else: ?><div class="clinic-photo-block"></div><?php endif; ?><h3><?= htmlspecialchars($clinic['ClinicName']) ?></h3><p><?= htmlspecialchars($clinic['Address']) ?></p><div><span>From PHP <?= htmlspecialchars(number_format((float) $clinic['BaseConsultationFee'], 2)) ?></span><a href="<?= HQ_BASE_URL ?>/patient/clinic-detail.php?clinic_id=<?= (int) $clinic['ClinicID'] ?>">View</a></div></article><?php endforeach; ?></div></section>
</div></main>

<div class="modal-overlay" id="bookNowModal">
  <div class="modal-box modal-box-wide">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Book an appointment</h2>
    <p class="modal-subtitle">Select a clinic and your preferred schedule. The clinic will review and confirm your request.</p>
    <div id="bookNowErrors" class="form-message error" role="alert" style="display:none;"></div>
    <form method="post" action="<?= HQ_BASE_URL ?>/patient/book-appointment.php" id="bookNowForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="appointment_date" id="bookNowDateInput" required>

      <div class="booking-modal-grid">
        <div>
          <div class="form-stack">
            <label>Clinic<select name="clinic_id" id="bookNowClinicSelect" required>
              <option value="">Select a clinic</option>
              <?php foreach ($allClinics as $clinic): ?>
                <option value="<?= (int) $clinic['ClinicID'] ?>" data-fee="<?= htmlspecialchars(number_format((float) $clinic['BaseConsultationFee'], 2), ENT_QUOTES) ?>"><?= htmlspecialchars($clinic['ClinicName']) ?> &mdash; <?= htmlspecialchars($clinic['Address']) ?></option>
              <?php endforeach; ?>
            </select></label>
            <label>Preferred physician <span class="optional">(optional)</span><select name="physician_id">
              <option value="">No preference</option>
              <?php foreach ($physicians as $physician): ?>
                <option value="<?= (int) $physician['UserID'] ?>">Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?> &mdash; <?= htmlspecialchars($physician['ClinicName']) ?></option>
              <?php endforeach; ?>
            </select></label>
          </div>

          <label style="display:block;margin:14px 0 6px;font-size:13.5px;font-weight:600;color:var(--slate-700);">Date</label>
          <div class="calendar-picker" id="bookNowCalendar">
            <div class="calendar-header">
              <button type="button" class="calendar-nav" data-nav="-1" aria-label="Previous month">&lsaquo;</button>
              <strong class="calendar-month-label"></strong>
              <button type="button" class="calendar-nav" data-nav="1" aria-label="Next month">&rsaquo;</button>
            </div>
            <div class="calendar-weekdays"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
            <div class="calendar-days"></div>
          </div>
        </div>

        <div>
          <div class="form-stack">
            <label>Preferred time<input type="time" name="appointment_time" required></label>
            <label>Reason for visit <span class="optional">(optional)</span><textarea name="concern" rows="5" placeholder="Briefly describe what you need help with."></textarea></label>
          </div>

          <div class="booking-fee-card" id="bookNowFeeCard" style="display:none;">
            <div class="fee-row"><span>Consultation fee</span><span id="bookNowFeeAmount"></span></div>
            <div class="fee-row fee-total"><span>Total</span><span id="bookNowTotalAmount"></span></div>
            <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Proceed to Payment</button>
          </div>
          <p id="bookNowFeeHint" class="admin-empty" style="text-align:left;margin-top:16px;font-size:12.5px;">Select a clinic to see the fee and continue.</p>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="payDrawer">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div id="payDrawerContent">
      <h2>Review &amp; Pay</h2>
      <p class="modal-subtitle">Review your total, then choose how you'd like to pay. Paying sends your request to the clinic for confirmation.</p>
      <div id="payDrawerErrors" class="form-message error" role="alert" style="display:none;"></div>
      <div class="compact-list" style="margin-bottom:18px;">
        <article>
          <div>
            <strong id="payDrawerClinic"></strong>
            <span id="payDrawerDetails"></span>
          </div>
          <div style="text-align:right;">
            <span style="display:block;color:var(--slate-400);font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Total</span>
            <strong class="money" id="payDrawerFee"></strong>
          </div>
        </article>
      </div>
      <div class="dev-note" style="margin-bottom:18px;">
        <strong>Simulated payment:</strong> HealthQueue is not connected to a real payment processor or wallet provider. Paying instantly marks this booking fee as paid for demonstration purposes — no real transaction occurs.
      </div>

      <label style="display:block;margin-bottom:8px;font-size:13.5px;font-weight:600;color:var(--slate-700);">Mode of Payment</label>
      <div class="payment-mode-options" id="payDrawerModeOptions">
        <label class="payment-mode-option is-disabled" id="payDrawerWalletOption">
          <input type="radio" name="pay_drawer_mode" value="wallet" id="payDrawerWalletRadio" disabled>
          <span>Wallet <span id="payDrawerWalletBalanceText" style="color:var(--slate-400);"></span></span>
        </label>
        <label class="payment-mode-option">
          <input type="radio" name="pay_drawer_mode" value="card" id="payDrawerCardRadio" checked>
          <span>Simulated Card</span>
        </label>
      </div>
      <p id="payDrawerWalletNote" class="admin-empty" style="text-align:left;margin:10px 0 0;display:none;"></p>

      <button type="button" class="btn btn-primary btn-block" id="payDrawerPayBtn" style="margin-top:16px;">Pay Now</button>
    </div>

    <div id="paySuccessState" style="display:none;text-align:center;padding:24px 0 8px;">
      <svg class="success-check" width="76" height="76" viewBox="0 0 76 76">
        <circle class="success-check-circle" cx="38" cy="38" r="34" fill="none" stroke="#0077b3" stroke-width="4"/>
        <path class="success-check-mark" fill="none" stroke="#0077b3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" d="M23 39l10 10 20-20"/>
      </svg>
      <h2 style="margin:16px 0 4px;">Payment Successful!</h2>
      <p class="modal-subtitle">Your request has been sent to the clinic for confirmation.</p>
    </div>
  </div>
</div>

<div class="modal-overlay" id="queueDetailModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Queue #<span id="queueDetailNumber"></span></h2>
    <p class="modal-subtitle" id="queueDetailClinic"></p>
    <div class="dev-note" style="margin-bottom:18px;">
      <strong>Status:</strong> <span id="queueDetailStatus"></span><br>
      <strong>Appointment:</strong> <span id="queueDetailWhen"></span><br>
      <strong>Physician:</strong> <span id="queueDetailPhysician"></span><br>
      <strong>Reason for visit:</strong> <span id="queueDetailConcern"></span>
    </div>
    <a href="<?= HQ_BASE_URL ?>/patient/queue-status.php" class="btn btn-primary btn-block">View Live Status</a>
  </div>
</div>

<div class="modal-overlay" id="viewRecordModal">
  <div class="modal-box modal-box-wide" style="max-height:85vh;">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div id="viewRecordContent"><p class="admin-empty">Loading…</p></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var dashClockDay = document.getElementById('dashClockDay');
  var dashClockTime = document.getElementById('dashClockTime');
  var dashClockDate = document.getElementById('dashClockDate');
  if (dashClockDay && dashClockTime && dashClockDate) {
    var dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var updateClock = function () {
      var now = new Date();
      var hours24 = now.getHours();
      var ampm = hours24 >= 12 ? 'PM' : 'AM';
      var hours12 = hours24 % 12 || 12;
      var minutes = String(now.getMinutes()).padStart(2, '0');
      dashClockDay.textContent = dayNames[now.getDay()];
      dashClockTime.innerHTML = hours12 + ':' + minutes + ' <sup>' + ampm + '</sup>';
      dashClockDate.textContent = monthNames[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear();
    };
    updateClock();
    setInterval(updateClock, 30000);
  }

  var queueDetailModal = document.getElementById('queueDetailModal');
  function openQueueDetail(card) {
    if (!queueDetailModal) return;
    document.getElementById('queueDetailNumber').textContent = card.getAttribute('data-number');
    document.getElementById('queueDetailClinic').textContent = card.getAttribute('data-clinic') + ' — ' + card.getAttribute('data-address');
    document.getElementById('queueDetailStatus').textContent = card.getAttribute('data-status');
    document.getElementById('queueDetailWhen').textContent = card.getAttribute('data-when');
    document.getElementById('queueDetailPhysician').textContent = card.getAttribute('data-physician');
    document.getElementById('queueDetailConcern').textContent = card.getAttribute('data-concern');
    window.hqOpenModal(queueDetailModal);
  }
  document.querySelectorAll('[data-queue-detail]').forEach(function (card) {
    card.addEventListener('click', function () { openQueueDetail(card); });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openQueueDetail(card); }
    });
  });

  var clinicSelect = document.getElementById('bookNowClinicSelect');
  var feeCard = document.getElementById('bookNowFeeCard');
  var feeAmount = document.getElementById('bookNowFeeAmount');
  var totalAmount = document.getElementById('bookNowTotalAmount');
  var feeHint = document.getElementById('bookNowFeeHint');
  if (clinicSelect && feeCard) {
    clinicSelect.addEventListener('change', function () {
      var option = clinicSelect.options[clinicSelect.selectedIndex];
      var fee = option ? option.getAttribute('data-fee') : null;
      if (fee) {
        feeAmount.textContent = 'PHP ' + fee;
        totalAmount.textContent = 'PHP ' + fee;
        feeCard.style.display = 'block';
        if (feeHint) feeHint.style.display = 'none';
      } else {
        feeCard.style.display = 'none';
        if (feeHint) feeHint.style.display = 'block';
      }
    });
  }

  var dateInput = document.getElementById('bookNowDateInput');
  var calendarEl = document.getElementById('bookNowCalendar');
  if (calendarEl && dateInput && window.hqInitCalendar) {
    window.hqInitCalendar(calendarEl, dateInput);
  }

  var bookForm = document.getElementById('bookNowForm');
  var bookErrors = document.getElementById('bookNowErrors');
  var bookModal = document.getElementById('bookNowModal');
  var payDrawer = document.getElementById('payDrawer');
  var payClinic = document.getElementById('payDrawerClinic');
  var payDetails = document.getElementById('payDrawerDetails');
  var payFee = document.getElementById('payDrawerFee');
  var payBtn = document.getElementById('payDrawerPayBtn');
  var walletOption = document.getElementById('payDrawerWalletOption');
  var walletRadio = document.getElementById('payDrawerWalletRadio');
  var cardRadio = document.getElementById('payDrawerCardRadio');
  var walletBalanceText = document.getElementById('payDrawerWalletBalanceText');
  var walletNote = document.getElementById('payDrawerWalletNote');
  var payErrors = document.getElementById('payDrawerErrors');
  var payDrawerContent = document.getElementById('payDrawerContent');
  var paySuccessState = document.getElementById('paySuccessState');
  var currentAppointmentId = null;

  function showPaymentSuccess(redirectUrl) {
    if (!payDrawerContent || !paySuccessState) {
      window.location.href = redirectUrl;
      return;
    }
    payDrawerContent.style.display = 'none';
    paySuccessState.style.display = 'block';
    // Restart the checkmark's draw-in animation every time it's shown.
    paySuccessState.querySelectorAll('.success-check-circle, .success-check-mark').forEach(function (el) {
      el.style.animation = 'none';
      void el.offsetWidth;
      el.style.animation = '';
    });
    setTimeout(function () { window.location.href = redirectUrl; }, 1400);
  }

  function showErrors(container, messages) {
    var list = document.createElement('ul');
    messages.forEach(function (msg) {
      var item = document.createElement('li');
      item.textContent = msg;
      list.appendChild(item);
    });
    container.innerHTML = '';
    container.appendChild(list);
    container.style.display = 'block';
  }

  if (bookForm) {
    bookForm.addEventListener('submit', function (e) {
      e.preventDefault();
      bookErrors.style.display = 'none';
      if (!dateInput.value) {
        showErrors(bookErrors, ['Please choose a date on the calendar.']);
        return;
      }
      var formData = new FormData(bookForm);
      fetch(bookForm.action, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.ok) {
            currentAppointmentId = data.appointment_id;
            payClinic.textContent = data.clinic_name;
            payDetails.textContent = data.physician_label + ' · ' + data.appointment_date + ' at ' + data.appointment_time;
            payFee.textContent = 'PHP ' + data.fee;
            payBtn.textContent = 'Pay Now — PHP ' + data.fee;
            payBtn.disabled = false;
            payErrors.style.display = 'none';

            walletBalanceText.textContent = '— Balance PHP ' + data.wallet_balance;
            if (data.can_pay_with_wallet) {
              walletOption.classList.remove('is-disabled');
              walletRadio.disabled = false;
              walletRadio.checked = true;
              cardRadio.checked = false;
              walletNote.style.display = 'none';
            } else {
              walletOption.classList.add('is-disabled');
              walletRadio.disabled = true;
              walletRadio.checked = false;
              cardRadio.checked = true;
              walletNote.textContent = 'Wallet balance: PHP ' + data.wallet_balance + ' — not enough to cover this fee.';
              walletNote.style.display = 'block';
            }

            window.hqCloseModal(bookModal);
            window.hqOpenModal(payDrawer);
          } else {
            showErrors(bookErrors, data.errors || ['We could not submit your appointment request.']);
          }
        })
        .catch(function () {
          showErrors(bookErrors, ['We could not submit your appointment request. Please try again.']);
        });
    });
  }

  if (payBtn) {
    payBtn.addEventListener('click', function () {
      if (!currentAppointmentId) return;
      var mode = (walletRadio && walletRadio.checked) ? 'wallet' : 'card';
      var originalText = payBtn.textContent;
      payBtn.disabled = true;
      payBtn.textContent = 'Processing…';
      var body = new URLSearchParams();
      body.set('csrf_token', document.querySelector('#bookNowForm [name="csrf_token"]').value);
      body.set('form_type', 'submit_payment');
      body.set('payment_mode', mode);
      body.set('appointment_id', currentAppointmentId);
      fetch('<?= HQ_BASE_URL ?>/patient/checkout.php', { method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.ok) {
            showPaymentSuccess('<?= HQ_BASE_URL ?>/patient/my-appointments.php?paid=1');
          } else {
            showErrors(payErrors, data.errors || ['We could not process your payment right now.']);
            payBtn.disabled = false;
            payBtn.textContent = originalText;
          }
        })
        .catch(function () {
          showErrors(payErrors, ['We could not process your payment right now. Please try again.']);
          payBtn.disabled = false;
          payBtn.textContent = originalText;
        });
    });
  }

  var viewRecordModal = document.getElementById('viewRecordModal');
  var viewRecordContent = document.getElementById('viewRecordContent');

  function loadRecord(appointmentId, scrollTo) {
    if (!viewRecordModal || !viewRecordContent) return;
    viewRecordContent.innerHTML = '<p class="admin-empty">Loading…</p>';
    window.hqOpenModal(viewRecordModal);
    fetch('<?= HQ_BASE_URL ?>/patient/consultation.php?appointment_id=' + encodeURIComponent(appointmentId), { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) {
        viewRecordContent.innerHTML = html;
        if (scrollTo) {
          var target = viewRecordContent.querySelector('#' + scrollTo);
          if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })
      .catch(function () {
        viewRecordContent.innerHTML = '<p class="form-message error" role="alert">We could not load this record. Please try again.</p>';
      });
  }

  document.querySelectorAll('[data-view-record]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      loadRecord(trigger.getAttribute('data-view-record'), trigger.getAttribute('data-scroll-to'));
    });
  });

  if (viewRecordContent) {
    viewRecordContent.addEventListener('submit', function (e) {
      if (!e.target.matches('.consultation-feedback-form')) return;
      e.preventDefault();
      var form = e.target;
      var formData = new FormData(form);
      fetch(form.action, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.text(); })
        .then(function (html) { viewRecordContent.innerHTML = html; })
        .catch(function () {
          var err = document.createElement('p');
          err.className = 'form-message error';
          err.textContent = 'We could not save your feedback. Please try again.';
          form.prepend(err);
        });
    });
  }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
