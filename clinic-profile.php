<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

define('HQ_BASE_URL', '/healthqueue');

$pdo = getDbConnection();
$clinicId = filter_input(INPUT_GET, 'clinic_id', FILTER_VALIDATE_INT);

if (!$clinicId || !$pdo) {
    header('Location: ' . HQ_BASE_URL . '/index.php#clinics');
    exit;
}

$stmt = $pdo->prepare("SELECT ClinicID, ClinicName, Address, ContactNumber, BaseConsultationFee, PhotoUrl, Description FROM Clinic WHERE ClinicID = ? AND archived = 0 AND Status = 'Active'");
$stmt->execute([$clinicId]);
$clinic = $stmt->fetch();

if (!$clinic) {
    header('Location: ' . HQ_BASE_URL . '/index.php#clinics');
    exit;
}

$physicians = [];
$ratingSummary = ['avg_rating' => 0, 'review_count' => 0];
$reviews = [];

try {
    $physStmt = $pdo->prepare(
        "SELECT UserID, FirstName, LastName, AvailabilityStatus FROM Users
         WHERE ClinicID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
         ORDER BY LastName"
    );
    $physStmt->execute([$clinicId]);
    $physicians = $physStmt->fetchAll();

    $ratingStmt = $pdo->prepare('SELECT COALESCE(AVG(Rating), 0) AS avg_rating, COUNT(*) AS review_count FROM Feedback WHERE ClinicID = ?');
    $ratingStmt->execute([$clinicId]);
    $ratingSummary = $ratingStmt->fetch();

    $reviewStmt = $pdo->prepare(
        "SELECT f.Rating, f.Comment, f.CreatedAt, p.FirstName
         FROM Feedback f
         JOIN Users p ON p.UserID = f.PatientID
         WHERE f.ClinicID = ? AND f.Comment IS NOT NULL
         ORDER BY f.CreatedAt DESC
         LIMIT 5"
    );
    $reviewStmt->execute([$clinicId]);
    $reviews = $reviewStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Public clinic profile load failed: ' . $e->getMessage());
}

$hqStatusDot = ['Available' => '#22c55e', 'On Break' => '#0077b3', 'Unavailable' => '#94a3b8'];
$currentUserData = currentUser();
$isPatient = $currentUserData && $currentUserData['RoleName'] === 'Patient';

$pageTitle = htmlspecialchars($clinic['ClinicName']) . ' — HealthQueue';
require __DIR__ . '/includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <a href="<?= HQ_BASE_URL ?>/index.php#clinics" class="back-link">&larr; Back to clinics</a>

  <?php if (!empty($clinic['PhotoUrl'])): ?>
    <img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="<?= htmlspecialchars($clinic['ClinicName']) ?>" class="clinic-profile-photo" style="margin-top:18px;">
  <?php endif; ?>

  <section class="portal-hero" style="margin-top:<?= empty($clinic['PhotoUrl']) ? '18px' : '0' ?>;">
    <div>
      <span class="eyebrow">Clinic profile</span>
      <h1><?= htmlspecialchars($clinic['ClinicName']) ?></h1>
      <p><?= htmlspecialchars($clinic['Address']) ?> &middot; <?= htmlspecialchars($clinic['ContactNumber'] ?: 'No contact number listed') ?></p>
      <?php if ((int) $ratingSummary['review_count'] > 0): ?>
        <p style="color:#fbbf24;font-weight:700;margin-top:6px;">★ <?= number_format((float) $ratingSummary['avg_rating'], 1) ?> <span style="color:rgba(255,255,255,.7);font-weight:400;">(<?= (int) $ratingSummary['review_count'] ?> review<?= (int) $ratingSummary['review_count'] === 1 ? '' : 's' ?>)</span></p>
      <?php else: ?>
        <p style="color:rgba(255,255,255,.7);margin-top:6px;">No reviews yet.</p>
      <?php endif; ?>
    </div>
    <?php if ($isPatient): ?>
      <button type="button" class="btn btn-primary" data-modal-open="bookNowModal">Book Now</button>
    <?php elseif (!$currentUserData): ?>
      <a href="<?= HQ_BASE_URL ?>/auth/register.php" class="btn btn-primary">Sign up to book</a>
    <?php endif; ?>
  </section>

  <?php if (!empty($clinic['Description'])): ?>
    <section class="portal-section">
      <div class="portal-heading"><div><span class="section-kicker">About</span><h2>About this clinic</h2></div></div>
      <p style="margin:0;color:var(--slate-600);font-size:14.5px;white-space:pre-line;"><?= htmlspecialchars($clinic['Description']) ?></p>
    </section>
  <?php endif; ?>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Pricing</span><h2>Base consultation fee</h2></div></div>
    <p class="admin-empty" style="text-align:left;">PHP <?= number_format((float) $clinic['BaseConsultationFee'], 2) ?> — the exact fee is confirmed at checkout after your request is submitted.</p>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Team</span><h2>Physicians</h2></div></div>
    <?php if ($physicians): ?>
      <div class="compact-list">
        <?php foreach ($physicians as $physician): ?>
          <article>
            <strong>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></strong>
            <span style="display:inline-flex;align-items:center;gap:8px;">
              <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= $hqStatusDot[$physician['AvailabilityStatus']] ?? '#94a3b8' ?>;"></span>
              <?= htmlspecialchars($physician['AvailabilityStatus']) ?>
            </span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No physicians are listed for this clinic yet.</p>
    <?php endif; ?>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Reviews</span><h2>What patients say</h2></div></div>
    <?php if ($reviews): ?>
      <div class="compact-list">
        <?php foreach ($reviews as $review): ?>
          <article style="align-items:flex-start;flex-direction:column;gap:6px;">
            <strong style="color:#f59e0b;"><?= str_repeat('★', (int) $review['Rating']) . str_repeat('☆', 5 - (int) $review['Rating']) ?></strong>
            <p style="margin:0;color:var(--slate-600);font-size:14px;white-space:pre-line;"><?= htmlspecialchars($review['Comment']) ?></p>
            <span style="color:var(--slate-400);font-size:12px;"><?= htmlspecialchars($review['FirstName']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($review['CreatedAt']))) ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No written reviews yet.</p>
    <?php endif; ?>
  </section>
</div></main>

<?php if ($isPatient): ?>
<div class="modal-overlay" id="bookNowModal">
  <div class="modal-box modal-box-wide">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Book with <?= htmlspecialchars($clinic['ClinicName']) ?></h2>
    <p class="modal-subtitle">Select your preferred schedule. The clinic will review and confirm your request.</p>
    <div id="bookNowErrors" class="form-message error" role="alert" style="display:none;"></div>
    <form method="post" action="<?= HQ_BASE_URL ?>/patient/book-appointment.php" id="bookNowForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
      <input type="hidden" name="appointment_date" id="bookNowDateInput" required>

      <div class="booking-modal-grid">
        <div>
          <label>Preferred physician <span class="optional">(optional)</span><select name="physician_id">
            <option value="">No preference</option>
            <?php foreach ($physicians as $physician): ?>
              <option value="<?= (int) $physician['UserID'] ?>">Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></option>
            <?php endforeach; ?>
          </select></label>

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

          <div class="booking-fee-card">
            <div class="fee-row"><span>Consultation fee</span><span>PHP <?= number_format((float) $clinic['BaseConsultationFee'], 2) ?></span></div>
            <div class="fee-row fee-total"><span>Total</span><span>PHP <?= number_format((float) $clinic['BaseConsultationFee'], 2) ?></span></div>
            <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Proceed to Payment</button>
          </div>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
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
    paySuccessState.querySelectorAll('.success-check-circle, .success-check-mark').forEach(function (el) {
      el.style.animation = 'none';
      void el.offsetWidth;
      el.style.animation = '';
    });
    setTimeout(function () { window.location.href = redirectUrl; }, 1400);
  }

  var dateInput = document.getElementById('bookNowDateInput');
  var calendarEl = document.getElementById('bookNowCalendar');
  if (calendarEl && dateInput && window.hqInitCalendar) {
    window.hqInitCalendar(calendarEl, dateInput);
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
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
