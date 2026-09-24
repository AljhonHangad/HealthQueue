<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$appointments = [];
$clinics = [];
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

<?php require __DIR__ . '/../includes/booking-modal.php'; ?>

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
