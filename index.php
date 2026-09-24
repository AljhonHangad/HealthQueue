<?php
require_once __DIR__ . '/config/db.php';

// The project is served from localhost/healthqueue in XAMPP, so asset and
// navigation links must retain the application directory prefix.
define('HQ_BASE_URL', '/healthqueue');
$pageTitle = 'HealthQueue — Skip the Wait, See Your Doctor Faster';

// Featured clinics: pull from DB if available, otherwise fall back to
// static sample data so the landing page still renders before the
// database has been set up.
$clinics = [];
$pdo = getDbConnection();
$registrationErrors = [];
$registrationSuccess = false;
$registrationValues = [
    'clinic_name' => '', 'contact_name' => '', 'email' => '',
    'phone' => '', 'city' => '', 'physicians_count' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'clinic_registration') {
    foreach ($registrationValues as $field => $value) {
        $registrationValues[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    foreach (['clinic_name' => 'Clinic name', 'contact_name' => 'Contact name', 'email' => 'Email address', 'phone' => 'Phone number', 'city' => 'City'] as $field => $label) {
        if ($registrationValues[$field] === '') {
            $registrationErrors[] = $label . ' is required.';
        }
    }

    if ($registrationValues['email'] !== '' && !filter_var($registrationValues['email'], FILTER_VALIDATE_EMAIL)) {
        $registrationErrors[] = 'Please enter a valid email address.';
    }

    if (!$registrationErrors && $pdo) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ClinicRegistrationInquiry
                 (ClinicName, ContactName, Email, Phone, City, PhysiciansCount)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $registrationValues['clinic_name'],
                $registrationValues['contact_name'],
                $registrationValues['email'],
                $registrationValues['phone'],
                $registrationValues['city'],
                $registrationValues['physicians_count'] !== '' ? (int) $registrationValues['physicians_count'] : null,
            ]);
            $registrationSuccess = true;
            $registrationValues = array_fill_keys(array_keys($registrationValues), '');
        } catch (PDOException $e) {
            error_log('Clinic registration inquiry failed: ' . $e->getMessage());
            $registrationErrors[] = 'We could not save your inquiry right now. Please email hello@healthqueue.app.';
        }
    } elseif (!$registrationErrors) {
        $registrationErrors[] = 'Online registration is temporarily unavailable. Please email hello@healthqueue.app.';
    }
}

if ($pdo) {
    try {
        $stmt = $pdo->query(
            "SELECT ClinicID, ClinicName, Address, ContactNumber, PhotoUrl
             FROM Clinic
             WHERE archived = 0
             ORDER BY RegistrationDate DESC
             LIMIT 4"
        );
        $clinics = $stmt->fetchAll();
    } catch (PDOException $e) {
        $clinics = [];
    }
}

if (empty($clinics)) {
    $clinics = [
        ['ClinicID' => null, 'ClinicName' => 'Sunrise Medical Center',   'Address' => '123 Rizal Ave, Manila',            'ContactNumber' => '+63 2 8123 4567', 'PhotoUrl' => null],
        ['ClinicID' => null, 'ClinicName' => 'Northgate Family Clinic',  'Address' => '456 Aurora Blvd, Quezon City',     'ContactNumber' => '+63 2 8987 6543', 'PhotoUrl' => null],
        ['ClinicID' => null, 'ClinicName' => 'Bayview Health Hub',       'Address' => '789 Roxas Blvd, Pasay',            'ContactNumber' => '+63 2 8555 0102', 'PhotoUrl' => null],
        ['ClinicID' => null, 'ClinicName' => 'Eastside Wellness Clinic', 'Address' => '321 Marcos Highway, Antipolo',     'ContactNumber' => '+63 2 8222 7788', 'PhotoUrl' => null],
    ];
}

require __DIR__ . '/includes/header.php';
?>

<!-- ===================== HERO ===================== -->
<section class="hero">
  <div class="container">
    <div>
      <span class="eyebrow">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 2 4 6v6c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V6l-8-4Z"/></svg>
        Secure clinic queue platform
      </span>

      <h1>Skip the wait.<br>See your <span class="accent">doctor faster</span>.</h1>

      <p class="lead">
        HealthQueue lets you book appointments, join clinic queues in real time,
        and receive digital consultation records from trusted physicians across Cebu City.
      </p>

      <div class="hero-actions">
        <a href="<?= HQ_BASE_URL ?>/auth/register.php" class="btn btn-primary">Book Appointment</a>
        <a href="#clinic-registration" class="btn btn-outline">Register a Clinic</a>
      </div>
    </div>

    <div class="hero-stats-card">
      <div class="stat-item">
        <span class="stat-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
        </span>
        <div>
          <div class="stat-value">Q-12</div>
          <div class="stat-label">Live queue</div>
        </div>
      </div>

      <div class="stat-item">
        <span class="stat-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/><circle cx="11" cy="7" r="4"/></svg>
        </span>
        <div>
          <div class="stat-value">24+</div>
          <div class="stat-label">Physicians</div>
        </div>
      </div>

      <div class="stat-item">
        <span class="stat-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 3v3M16 3v3"/></svg>
        </span>
        <div>
          <div class="stat-value">180</div>
          <div class="stat-label">Bookings today</div>
        </div>
      </div>

      <div class="stat-item">
        <span class="stat-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 2 4 6v6c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V6l-8-4Z"/></svg>
        </span>
        <div>
          <div class="stat-value">E2EE</div>
          <div class="stat-label">Secure &amp; encrypted</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== SERVICES ===================== -->
<section class="section" id="services">
  <div class="container">
    <div class="section-header">
      <span class="eyebrow">Services Offered</span>
      <h2>Everything your clinic needs, in one place</h2>
      <p>From booking to consultation, HealthQueue keeps patients, staff, and physicians on the same page.</p>
    </div>

    <div class="services-grid">
      <div class="service-card">
        <div class="service-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 3v3M16 3v3"/><path d="m9 15 2 2 4-4"/></svg>
        </div>
        <h3>Online Booking</h3>
        <p>Patients choose a physician, date, and time, then confirm with a clinic-set booking fee.</p>
      </div>

      <div class="service-card">
        <div class="service-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
        </div>
        <h3>Live Queue</h3>
        <p>Real-time queue numbers and estimated wait times for both online and walk-in patients.</p>
      </div>

      <div class="service-card">
        <div class="service-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg>
        </div>
        <h3>Consultation Records</h3>
        <p>Secure digital notes, prescriptions, and AI-generated consultation transcripts.</p>
      </div>

      <div class="service-card">
        <div class="service-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <h3>Role-Based Access</h3>
        <p>Patient, Staff, Physician, and Admin — each sees only the data and tools their role permits.</p>
      </div>

      <div class="service-card">
        <div class="service-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v1a7 7 0 0 0 14 0v-1M12 18v4M9 22h6"/></svg>
        </div>
        <h3>Speech-to-Text</h3>
        <p>Physicians record consultations; the system transcribes and summarizes them automatically.</p>
      </div>

      <div class="service-card">
        <div class="service-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        </div>
        <h3>Notifications</h3>
        <p>Automated SMS and email alerts for booking confirmations, delays, and queue proximity.</p>
      </div>
    </div>
  </div>
</section>

<!-- ===================== AUDIENCES ===================== -->
<section class="section audience-section" id="for-everyone">
  <div class="container">
    <div class="section-header">
      <span class="eyebrow">Built for everyone</span>
      <h2>Care is easier when everyone is connected</h2>
      <p>Patients can get started right away. Physicians and clinic owners can contact us to join HealthQueue.</p>
    </div>

    <div class="audience-grid">
      <article class="audience-card audience-patient">
        <span class="audience-icon">01</span>
        <h3>For Patients</h3>
        <p>Create an account, find a clinic, and book an appointment in just a few steps.</p>
        <a href="<?= HQ_BASE_URL ?>/auth/register.php" class="audience-link">Create a patient account <span aria-hidden="true">&rarr;</span></a>
      </article>

      <article class="audience-card audience-clinic">
        <span class="audience-icon">02</span>
        <h3>For Physicians &amp; Clinics</h3>
        <p>Want to list your clinic or manage your patients with HealthQueue? Contact us to begin registration and onboarding.</p>
        <a href="#clinic-registration" class="audience-link">Start clinic registration <span aria-hidden="true">&rarr;</span></a>
      </article>
    </div>
  </div>
</section>

<!-- ===================== HOW IT WORKS ===================== -->
<section class="section how-it-works" id="how-it-works">
  <div class="container">
    <div class="section-header">
      <span class="eyebrow">How it works</span>
      <h2>Your visit, simplified in three steps</h2>
      <p>Spend less time waiting and more time focusing on your health.</p>
    </div>
    <div class="steps-grid">
      <article class="step-card"><span class="step-number">01</span><h3>Find a clinic</h3><p>Browse partner clinics and choose the care that fits your needs.</p></article>
      <article class="step-card"><span class="step-number">02</span><h3>Book your schedule</h3><p>Select an available date and time, then confirm your appointment online.</p></article>
      <article class="step-card"><span class="step-number">03</span><h3>Track your queue</h3><p>Follow your live queue number and arrive closer to your consultation time.</p></article>
    </div>
  </div>
</section>

<section class="trust-strip" aria-label="HealthQueue benefits">
  <div class="container trust-grid">
    <div><strong>Secure records</strong><span>Protected patient information</span></div>
    <div><strong>Live updates</strong><span>See your queue status in real time</span></div>
    <div><strong>Clinic support</strong><span>Guided onboarding for every partner</span></div>
  </div>
</section>

<!-- ===================== CLINIC REGISTRATION ===================== -->
<section class="section registration-section" id="clinic-registration">
  <div class="container registration-layout">
    <div class="registration-copy">
      <span class="eyebrow">Partner with HealthQueue</span>
      <h2>Bring your clinic online.</h2>
      <p>Physicians and clinic owners can submit their details below. Our team will review your inquiry and guide you through onboarding.</p>
      <ul class="registration-benefits">
        <li>Manage appointments and walk-in queues in one place</li>
        <li>Give patients clear, real-time queue updates</li>
        <li>Receive personal onboarding support from our team</li>
      </ul>
    </div>

    <form class="registration-form" method="post" action="#clinic-registration">
      <input type="hidden" name="form_type" value="clinic_registration">
      <h3>Clinic registration inquiry</h3>
      <p>Tell us a little about your clinic.</p>
      <?php if ($registrationSuccess): ?>
        <p class="form-message success" role="status">Thank you! Your registration inquiry has been received.</p>
      <?php endif; ?>
      <?php if ($registrationErrors): ?>
        <div class="form-message error" role="alert"><strong>Please check the following:</strong><ul><?php foreach ($registrationErrors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <div class="form-grid">
        <label>Clinic name<input name="clinic_name" value="<?= htmlspecialchars($registrationValues['clinic_name']) ?>" required></label>
        <label>Contact person<input name="contact_name" value="<?= htmlspecialchars($registrationValues['contact_name']) ?>" required></label>
        <label>Email address<input type="email" name="email" value="<?= htmlspecialchars($registrationValues['email']) ?>" required></label>
        <label>Phone number<input type="tel" name="phone" value="<?= htmlspecialchars($registrationValues['phone']) ?>" required></label>
        <label>City / location<input name="city" value="<?= htmlspecialchars($registrationValues['city']) ?>" required></label>
        <label>Number of physicians<input type="number" name="physicians_count" min="1" value="<?= htmlspecialchars($registrationValues['physicians_count']) ?>"></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Submit registration inquiry</button>
      <small>We’ll use these details only to contact you about clinic registration.</small>
    </form>
  </div>
</section>

<!-- ===================== FEATURED CLINICS ===================== -->
<section class="section section-alt" id="clinics">
  <div class="container">
    <div class="section-header">
      <span class="eyebrow">Featured Clinics</span>
      <h2>Partner clinics across Cebu City</h2>
      <p>Browse participating outpatient clinics and their available physicians.</p>
    </div>

    <div class="clinics-grid">
      <?php foreach ($clinics as $clinic): ?>
        <div class="clinic-card">
          <?php if (!empty($clinic['PhotoUrl'])): ?>
            <div class="clinic-thumb has-photo" style="background-image:url('<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>');background-size:cover;background-position:center;"></div>
          <?php else: ?>
            <div class="clinic-thumb"></div>
          <?php endif; ?>
          <div class="clinic-body">
            <h3><?= htmlspecialchars($clinic['ClinicName']) ?></h3>
            <p class="clinic-meta">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" style="flex-shrink:0;margin-top:2px;"><path d="M12 22s7-6.5 7-12A7 7 0 0 0 5 10c0 5.5 7 12 7 12Z"/><circle cx="12" cy="10" r="2.4"/></svg>
              <?= htmlspecialchars($clinic['Address']) ?>
            </p>
            <p class="clinic-meta">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" style="flex-shrink:0;margin-top:2px;"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 3a2 2 0 0 1-.4 2.1L8 10.3a16 16 0 0 0 6 6l1.5-1.4a2 2 0 0 1 2.1-.4c1 .4 2 .6 3 .7a2 2 0 0 1 1.4 2.7Z"/></svg>
              <?= htmlspecialchars($clinic['ContactNumber']) ?>
            </p>
            <?php if ($clinic['ClinicID']): ?>
              <a href="<?= HQ_BASE_URL ?>/clinic-profile.php?clinic_id=<?= (int) $clinic['ClinicID'] ?>" class="btn btn-outline btn-sm btn-block">View Clinic</a>
            <?php else: ?>
              <a href="<?= HQ_BASE_URL ?>/auth/login.php" class="btn btn-outline btn-sm btn-block">View Clinic</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin-top:32px;">
      <a href="<?= HQ_BASE_URL ?>/clinics.php" class="btn btn-outline">View All Clinics</a>
    </div>
  </div>
</section>

<!-- ===================== FAQ ===================== -->
<section class="section faq-section" id="faq">
  <div class="container">
    <div class="section-header">
      <span class="eyebrow">Frequently asked questions</span>
      <h2>Questions, answered.</h2>
      <p>Everything you need to know before booking or registering a clinic.</p>
    </div>
    <div class="faq-list">
      <details><summary>Is HealthQueue free for patients?</summary><p>Patients can create an account, browse clinics, and book appointments through HealthQueue. Any consultation or booking fees are set by the individual clinic.</p></details>
      <details><summary>Can I track a walk-in queue?</summary><p>Yes. Participating clinics can provide real-time queue updates for both booked appointments and walk-in patients.</p></details>
      <details><summary>How does a physician or clinic register?</summary><p>Submit the clinic registration inquiry above. Our team will contact you to verify your details and guide you through setup.</p></details>
      <details><summary>Is patient information secure?</summary><p>HealthQueue is designed with role-based access so that users only see the records and tools appropriate to their role.</p></details>
    </div>
  </div>
</section>

<!-- ===================== CONTACT ===================== -->
<section class="section" id="contact">
  <div class="container">
    <div class="contact-panel">
      <div>
        <span class="eyebrow" style="background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.12);color:#e0f7fa;">Contact</span>
        <h2>Register your clinic with us.</h2>
        <p class="lead">Patients can book online. Physicians and clinic owners can contact our team to register a clinic and start onboarding.</p>
        <a href="#clinic-registration" class="btn btn-primary contact-action">Start clinic registration</a>
      </div>

      <ul class="contact-list">
        <li>
          <span class="icon-badge">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 3a2 2 0 0 1-.4 2.1L8 10.3a16 16 0 0 0 6 6l1.5-1.4a2 2 0 0 1 2.1-.4c1 .4 2 .6 3 .7a2 2 0 0 1 1.4 2.7Z"/></svg>
          </span>
          <a href="tel:+63280000000">+63 2 8000 0000</a>
        </li>
        <li>
          <span class="icon-badge">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
          </span>
          <a href="mailto:hello@healthqueue.app?subject=Clinic%20Registration%20Inquiry">hello@healthqueue.app</a>
        </li>
        <li>
          <span class="icon-badge">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 22s7-6.5 7-12A7 7 0 0 0 5 10c0 5.5 7 12 7 12Z"/><circle cx="12" cy="10" r="2.4"/></svg>
          </span>
          Cebu City, Philippines
        </li>
      </ul>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
