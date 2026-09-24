<?php
/**
 * Shared "Book an appointment" modal + "Review & Pay" drawer for patient pages.
 *
 * Include after the page's </main>. Expects $pdo and HQ_BASE_URL. Any element
 * with data-book-clinic="<ClinicID>" opens the modal with that clinic
 * preselected (data-book-clinic="" opens it with no clinic chosen), and so
 * does arriving on the page with ?book=<ClinicID>. JS: window.hqOpenBooking(id).
 */
$bookingClinics = [];
$bookingPhysicians = [];
if ($pdo) {
    try {
        $bookingClinics = $pdo->query("SELECT ClinicID, ClinicName, Address, BaseConsultationFee FROM Clinic WHERE archived = 0 AND Status = 'Active' ORDER BY ClinicName")->fetchAll();
        $bookingPhysicians = $pdo->query(
            "SELECT u.UserID, u.ClinicID, u.FirstName, u.LastName, c.ClinicName
             FROM Users u JOIN Roles r ON r.RoleID = u.RoleID JOIN Clinic c ON c.ClinicID = u.ClinicID
             WHERE r.RoleName = 'Physician' AND u.Status = 'Active' AND u.DeletedAt IS NULL AND c.archived = 0 AND c.Status = 'Active'
             ORDER BY c.ClinicName, u.LastName"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Booking modal lookup failed: ' . $e->getMessage());
    }
}
?>
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
              <?php foreach ($bookingClinics as $clinic): ?>
                <option value="<?= (int) $clinic['ClinicID'] ?>" data-fee="<?= htmlspecialchars(number_format((float) $clinic['BaseConsultationFee'], 2), ENT_QUOTES) ?>"><?= htmlspecialchars($clinic['ClinicName']) ?> &mdash; <?= htmlspecialchars($clinic['Address']) ?></option>
              <?php endforeach; ?>
            </select></label>
            <label>Preferred physician <span class="optional">(optional)</span><select name="physician_id" id="bookNowPhysicianSelect">
              <option value="">No preference</option>
              <?php foreach ($bookingPhysicians as $physician): ?>
                <option value="<?= (int) $physician['UserID'] ?>" data-clinic="<?= (int) $physician['ClinicID'] ?>">Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></option>
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
          <p class="booking-avail-hint" id="bookNowDateHint">Choose a clinic to see available dates.</p>
        </div>

        <div>
          <div class="form-stack">
            <label>Preferred time
              <select name="appointment_time" id="bookNowTimeSelect" required disabled>
                <option value="">Pick a date first</option>
              </select>
            </label>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
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

    // Opens the booking modal (closing any other open modal, e.g. clinic
    // details) with the given clinic preselected when it's bookable.
    window.hqOpenBooking = function (clinicId) {
      document.querySelectorAll('.modal-overlay.is-open').forEach(function (open) { window.hqCloseModal(open); });
      if (clinicId && clinicSelect.querySelector('option[value="' + CSS.escape(String(clinicId)) + '"]')) {
        clinicSelect.value = String(clinicId);
      }
      clinicSelect.dispatchEvent(new Event('change'));
      window.hqOpenModal(document.getElementById('bookNowModal'));
    };

    // Delegated so buttons inside fetched content (clinic details modal) work too.
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-book-clinic]');
      if (!trigger) return;
      e.preventDefault();
      e.stopPropagation();
      window.hqOpenBooking(trigger.getAttribute('data-book-clinic'));
    });

    var bookParam = new URLSearchParams(window.location.search).get('book');
    if (bookParam) window.hqOpenBooking(bookParam);
  }

  // Availability: only dates/times inside a physician's published hours
  // (physician calendar) are offered. The server re-checks on submit.
  var dateInput = document.getElementById('bookNowDateInput');
  var calendarEl = document.getElementById('bookNowCalendar');
  var physicianSelect = document.getElementById('bookNowPhysicianSelect');
  var timeSelect = document.getElementById('bookNowTimeSelect');
  var dateHint = document.getElementById('bookNowDateHint');
  var availableDates = new Set();
  var fullDates = new Set();
  var shownMonth = null;
  var calendar = null;
  var availUrl = '<?= HQ_BASE_URL ?>/patient/availability-api.php';

  function availParams() {
    return 'clinic_id=' + encodeURIComponent(clinicSelect ? clinicSelect.value : '') + '&physician_id=' + encodeURIComponent(physicianSelect ? physicianSelect.value : '');
  }
  function resetTimes(message) {
    timeSelect.innerHTML = '<option value="">' + message + '</option>';
    timeSelect.disabled = true;
  }
  function loadDates() {
    availableDates = new Set();
    fullDates = new Set();
    if (calendar) calendar.render();
    if (!clinicSelect || !clinicSelect.value || !shownMonth) {
      dateHint.textContent = 'Choose a clinic to see available dates.';
      return;
    }
    dateHint.textContent = 'Loading available dates…';
    var month = shownMonth;
    fetch(availUrl + '?' + availParams() + '&month=' + month, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (month !== shownMonth) return;
        availableDates = new Set(data.dates || []);
        fullDates = new Set(data.full || []);
        if (calendar) calendar.render();
        dateHint.textContent = availableDates.size
          ? 'Blue dates have open times. Greyed-out dates have no hours' + (fullDates.size ? '; struck-through dates are fully booked.' : '.')
          : (fullDates.size ? 'Every date with hours this month is fully booked' : 'No available dates this month') + (physicianSelect && physicianSelect.value ? ' for this physician' : '') + '. Try the next month' + (physicianSelect && physicianSelect.value ? ' or “No preference”' : '') + '.';
      })
      .catch(function () { dateHint.textContent = 'We could not load available dates. Please try again.'; });
  }
  function loadTimes(date) {
    resetTimes('Loading times…');
    fetch(availUrl + '?' + availParams() + '&date=' + date, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        var times = data.times || [];
        if (!times.length) { resetTimes('No open times on this date'); return; }
        timeSelect.innerHTML = '<option value="">Select a time</option>' + times.map(function (t) {
          return '<option value="' + t.value + '">' + t.label + '</option>';
        }).join('');
        timeSelect.disabled = false;
      })
      .catch(function () { resetTimes('Could not load times'); });
  }
  function onScopeChange() {
    if (calendar) calendar.clear();
    resetTimes('Pick a date first');
    loadDates();
  }

  if (calendarEl && dateInput && window.hqInitCalendar) {
    calendar = window.hqInitCalendar(calendarEl, dateInput, loadTimes, {
      isEnabled: function (iso) { return availableDates.has(iso); },
      noteFor: function (iso) { return fullDates.has(iso) ? 'Fully booked' : ''; },
      onMonthChange: function (year, month) {
        shownMonth = year + '-' + (month < 9 ? '0' : '') + (month + 1);
        loadDates();
      }
    });
  }
  // Only list physicians from the chosen clinic.
  function filterPhysicians() {
    if (!physicianSelect) return;
    Array.prototype.forEach.call(physicianSelect.options, function (opt) {
      if (!opt.value) return;
      opt.hidden = opt.disabled = !clinicSelect || opt.getAttribute('data-clinic') !== clinicSelect.value;
    });
    if (physicianSelect.selectedOptions[0] && physicianSelect.selectedOptions[0].hidden) physicianSelect.value = '';
  }
  if (clinicSelect) clinicSelect.addEventListener('change', function () { filterPhysicians(); onScopeChange(); });
  if (physicianSelect) physicianSelect.addEventListener('change', onScopeChange);
  filterPhysicians();
  // A clinic may already be chosen (e.g. opened via ?book=<ClinicID>).
  if (clinicSelect && clinicSelect.value) loadDates();

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
});
</script>
