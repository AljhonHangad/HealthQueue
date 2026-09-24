<?php
if (!defined('HQ_BASE_URL')) {
    define('HQ_BASE_URL', '/healthqueue');
}
require_once __DIR__ . '/auth.php';
$pageTitle = $pageTitle ?? 'HealthQueue';
$hqUser = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="HealthQueue lets patients book appointments, track clinic queues in real time, and access secure digital consultation records for outpatient clinics in Cebu City.">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="icon" type="image/png" href="<?= HQ_BASE_URL ?>/assets/img/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= HQ_BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: 1 ?>">
</head>
<body>

<?php
// Admin, Physician, Staff, and Patient all get the app-style sidebar shell;
// only guests keep the marketing-style top navbar below.
$hqSidebarRoles = ['Admin', 'Physician', 'Staff', 'Patient'];
$hqIsAdminLayout = $hqUser && in_array($hqUser['RoleName'], $hqSidebarRoles, true); // name kept for footer.php's matching check
?>
<?php if ($hqIsAdminLayout):
  $hqCurrentFile = basename($_SERVER['SCRIPT_NAME']);
  $hqRoleFolder = match ($hqUser['RoleName']) {
      'Admin'     => 'admin',
      'Physician' => 'physician',
      'Staff'     => 'staff',
      'Patient'   => 'patient',
  };

  $hqIconDashboard   = '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>';
  $hqIconProfile     = '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>';
  $hqIconCalendar    = '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>';

  $hqSidebarNav = match ($hqUser['RoleName']) {
      'Admin' => [
          ['file' => 'dashboard.php',      'label' => 'Dashboard',      'icon' => $hqIconDashboard],
          ['file' => 'clinics.php',        'label' => 'Clinics',        'icon' => '<path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-6h6v6"/><path d="M9 12h.01M15 12h.01M12 8h.01"/>'],
          ['file' => 'physicians.php',     'label' => 'Physicians',     'icon' => '<path d="M9 2v5a3 3 0 0 0 6 0V2"/><path d="M12 13v3"/><circle cx="12" cy="18" r="3"/>'],
          ['file' => 'staff.php',          'label' => 'Staff',          'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
          ['file' => 'patients.php',       'label' => 'Patients',       'icon' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>'],
          ['file' => 'revenue.php',        'label' => 'Revenue',        'icon' => '<path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
          ['file' => 'announcements.php',  'label' => 'Announcements',  'icon' => '<path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8 13 21h-3"/>'],
          ['file' => 'activity-log.php',   'label' => 'Activity Log',   'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>'],
          ['file' => 'profile.php',        'label' => 'Profile',        'icon' => $hqIconProfile],
      ],
      'Physician' => [
          ['file' => 'dashboard.php',      'label' => 'Dashboard',      'icon' => $hqIconDashboard],
          ['file' => 'consultations.php',  'label' => 'Consultations',  'icon' => '<path d="M4.8 2.3 3 4l1.8 1.7M8 2v3M3 8h3"/><path d="M6 6c0 4 3 9 8 10 2 0 4-2 4-4l-3-3-2 2c-1-1-3-3-3-5l2-2-3-3C7 1 6 3 6 6Z"/>'],
          ['file' => 'availability.php',   'label' => 'My Availability','icon' => $hqIconCalendar],
          ['file' => 'history.php',        'label' => 'Consultation History', 'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>'],
          ['file' => 'profile.php',        'label' => 'Profile',        'icon' => $hqIconProfile],
      ],
      'Staff' => [
          ['file' => 'dashboard.php',      'label' => 'Dashboard',            'icon' => $hqIconDashboard],
          ['file' => 'appointments.php',   'label' => 'Appointments',         'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 15l2 2 4-4"/>'],
          ['file' => 'queue.php',          'label' => 'Queue Management',      'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>'],
          ['file' => 'physicians.php',     'label' => 'Physicians',            'icon' => '<path d="M9 2v5a3 3 0 0 0 6 0V2"/><path d="M12 13v3"/><circle cx="12" cy="18" r="3"/>'],
          ['file' => 'notifications.php',  'label' => 'Notifications',         'icon' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'],
          ['file' => 'profile.php',        'label' => 'Profile',               'icon' => $hqIconProfile],
      ],
      'Patient' => [
          ['file' => 'dashboard.php',        'label' => 'Dashboard',           'icon' => $hqIconDashboard],
          ['file' => 'clinics.php',          'label' => 'Clinics',             'icon' => '<path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-6h6v6"/><path d="M9 12h.01M15 12h.01M12 8h.01"/>'],
          ['file' => 'my-appointments.php',  'label' => 'My Appointments',     'icon' => $hqIconCalendar],
          ['file' => 'wallet.php',           'label' => 'Wallet',              'icon' => '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><circle cx="17" cy="15" r="1.5"/>'],
          ['file' => 'queue-status.php',     'label' => 'Live Queue Status',   'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>'],
          ['file' => 'medical-records.php',  'label' => 'Medical Records',     'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>'],
          ['file' => 'announcements.php',    'label' => 'Announcements',       'icon' => '<path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8 13 21h-3"/>'],
          ['file' => 'notifications.php',    'label' => 'Notifications',       'icon' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'],
          ['file' => 'profile.php',          'label' => 'Profile',             'icon' => $hqIconProfile],
      ],
  };

  // Small attention counts shown as sidebar badges, so nobody has to click
  // into each section just to see whether anything needs action.
  $hqSidebarBadges = [];
  $hqProfilePhoto = null;
  if ($pdo = getDbConnection()) {
      try {
          $hqProfilePhoto = $pdo->prepare('SELECT ProfilePhoto FROM Users WHERE UserID = ?');
          $hqProfilePhoto->execute([$hqUser['UserID']]);
          $hqProfilePhoto = $hqProfilePhoto->fetchColumn() ?: null;

          if ($hqUser['RoleName'] === 'Admin') {
              $hqSidebarBadges['clinics.php'] = (int) $pdo->query(
                  "SELECT COUNT(*) FROM ClinicRegistrationInquiry WHERE Status = 'Pending'"
              )->fetchColumn();
              $hqSidebarBadges['revenue.php'] = (int) $pdo->query(
                  "SELECT COUNT(*) FROM Appointments a
                   JOIN Clinic c ON c.ClinicID = a.ClinicID
                   LEFT JOIN AppointmentPayments p ON p.AppointmentID = a.AppointmentID
                   WHERE p.PaymentID IS NULL AND a.PhysicianID IS NOT NULL
                     AND a.Status = 'Completed' AND c.BaseConsultationFee > 0"
              )->fetchColumn();
          } elseif ($hqUser['RoleName'] === 'Physician') {
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE PhysicianID = ? AND Status = 'Pending'");
              $stmt->execute([$hqUser['UserID']]);
              $hqSidebarBadges['dashboard.php'] = (int) $stmt->fetchColumn();

              $stmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE PhysicianID = ? AND Status = 'Confirmed'");
              $stmt->execute([$hqUser['UserID']]);
              $hqSidebarBadges['consultations.php'] = (int) $stmt->fetchColumn();
          } elseif ($hqUser['RoleName'] === 'Staff' && $hqUser['ClinicID']) {
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE ClinicID = ? AND Status = 'Pending' AND BookingFeePaid = 1");
              $stmt->execute([$hqUser['ClinicID']]);
              $hqPendingRequests = (int) $stmt->fetchColumn();

              $stmt = $pdo->prepare(
                  "SELECT COUNT(*) FROM Appointments a
                   WHERE a.ClinicID = ? AND a.Status = 'Confirmed'
                     AND EXISTS (
                         SELECT 1 FROM ConsultationVersions v
                         JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                         WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized'
                     )"
              );
              $stmt->execute([$hqUser['ClinicID']]);
              $hqPendingConfirmations = (int) $stmt->fetchColumn();

              $hqSidebarBadges['appointments.php'] = $hqPendingRequests + $hqPendingConfirmations;

              $stmt = $pdo->prepare("SELECT COUNT(*) FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND Status IN ('Waiting', 'Calling', 'Serving')");
              $stmt->execute([$hqUser['ClinicID']]);
              $hqSidebarBadges['queue.php'] = (int) $stmt->fetchColumn();

              $stmt = $pdo->prepare('SELECT COUNT(*) FROM Notifications WHERE ClinicID = ? AND IsRead = 0');
              $stmt->execute([$hqUser['ClinicID']]);
              $hqSidebarBadges['notifications.php'] = (int) $stmt->fetchColumn();
          } elseif ($hqUser['RoleName'] === 'Patient') {
              $stmt = $pdo->prepare('SELECT COUNT(*) FROM PatientNotifications WHERE PatientID = ? AND IsRead = 0');
              $stmt->execute([$hqUser['UserID']]);
              $hqSidebarBadges['notifications.php'] = (int) $stmt->fetchColumn();

              $stmt = $pdo->prepare(
                  "SELECT COUNT(*) FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID
                   WHERE a.PatientID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')"
              );
              $stmt->execute([$hqUser['UserID']]);
              $hqSidebarBadges['queue-status.php'] = (int) $stmt->fetchColumn();
          }
      } catch (PDOException $e) {
          error_log('Sidebar badge count failed: ' . $e->getMessage());
      }
  }
?>
<div class="admin-layout">
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-brand-row">
      <a href="<?= HQ_BASE_URL ?>/<?= $hqRoleFolder ?>/dashboard.php" class="admin-sidebar-brand" title="HealthQueue">
        <span class="brand-mark">
          <img src="<?= HQ_BASE_URL ?>/assets/img/logo.png" alt="">
        </span>
        <span class="admin-sidebar-brand-text">HealthQueue</span>
      </a>
      <button type="button" class="admin-sidebar-toggle admin-sidebar-toggle-desktop" aria-label="Toggle menu" aria-expanded="true" aria-controls="adminSidebar">
        <span></span><span></span><span></span>
      </button>
    </div>
    <nav class="admin-nav">
      <?php foreach ($hqSidebarNav as $item): ?>
        <?php $hqBadgeCount = $hqSidebarBadges[$item['file']] ?? 0; ?>
        <a href="<?= HQ_BASE_URL ?>/<?= $hqRoleFolder ?>/<?= $item['file'] ?>" class="admin-nav-link<?= $hqCurrentFile === $item['file'] ? ' active' : '' ?>" title="<?= htmlspecialchars($item['label']) ?><?= $hqBadgeCount > 0 ? ' (' . $hqBadgeCount . ')' : '' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg>
          <span class="admin-nav-label"><?= $item['label'] ?></span>
          <?php if ($hqBadgeCount > 0): ?><span class="admin-nav-badge"><?= $hqBadgeCount > 99 ? '99+' : $hqBadgeCount ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar-footer">
      <div class="admin-sidebar-user">Signed in as<strong><?= htmlspecialchars($hqUser['FirstName'] . ' ' . $hqUser['LastName']) ?></strong></div>
      <a href="<?= HQ_BASE_URL ?>/auth/logout.php" class="btn btn-outline btn-sm btn-block" title="Log Out">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
        <span class="btn-label">Log Out</span>
      </a>
    </div>
  </aside>
  <script>
    // Runs immediately (before the rest of the page paints) so a collapsed
    // sidebar doesn't flash full-width on load -- localStorage is per-browser,
    // read here synchronously rather than waiting for main.js at the bottom.
    (function () {
      if (window.innerWidth > 980 && localStorage.getItem('hqSidebarCollapsed') === 'true') {
        document.getElementById('adminSidebar').classList.add('is-collapsed');
      }
    })();
  </script>

  <div class="admin-main">
    <header class="admin-topbar">
      <button type="button" class="admin-sidebar-toggle admin-sidebar-toggle-mobile" aria-label="Toggle menu" aria-expanded="false" aria-controls="adminSidebar">
        <span></span><span></span><span></span>
      </button>
      <div class="admin-topbar-icons">
        <?php if (in_array('notifications.php', array_column($hqSidebarNav, 'file'), true)): ?>
          <a href="<?= HQ_BASE_URL ?>/<?= $hqRoleFolder ?>/notifications.php" class="header-icon-btn" aria-label="Notifications">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            <?php if (($hqSidebarBadges['notifications.php'] ?? 0) > 0): ?><span class="header-icon-badge"></span><?php endif; ?>
          </a>
        <?php endif; ?>
        <a href="<?= HQ_BASE_URL ?>/<?= $hqRoleFolder ?>/profile.php" class="header-icon-avatar" aria-label="Profile">
          <?php if ($hqProfilePhoto): ?>
            <img src="<?= HQ_BASE_URL ?>/assets/uploads/avatars/<?= htmlspecialchars($hqProfilePhoto) ?>" alt="">
          <?php else: ?>
            <?= htmlspecialchars(strtoupper(substr($hqUser['FirstName'], 0, 1) . substr($hqUser['LastName'], 0, 1))) ?>
          <?php endif; ?>
        </a>
      </div>
    </header>
    <div class="admin-sidebar-scrim" id="adminSidebarScrim"></div>
<?php else: ?>
<header class="navbar">
  <div class="container">
    <a href="<?= HQ_BASE_URL ?>/index.php" class="brand">
      <span class="brand-mark">
        <img src="<?= HQ_BASE_URL ?>/assets/img/logo.png" alt="">
      </span>
      HealthQueue
    </a>

    <nav>
      <ul class="nav-links" id="primaryNavigation">
        <?php if ($hqUser && $hqUser['RoleName'] === 'Patient'): ?>
          <li><a href="<?= HQ_BASE_URL ?>/patient/dashboard.php">My Dashboard</a></li>
          <li><a href="<?= HQ_BASE_URL ?>/patient/book-appointment.php">Book Appointment</a></li>
          <li><a href="<?= HQ_BASE_URL ?>/index.php#clinics">Browse Clinics</a></li>
        <?php else: ?>
          <li><a href="<?= HQ_BASE_URL ?>/index.php#services">Services</a></li>
          <li><a href="<?= HQ_BASE_URL ?>/index.php#how-it-works">How It Works</a></li>
          <li><a href="<?= HQ_BASE_URL ?>/index.php#clinics">Clinics</a></li>
          <li><a href="<?= HQ_BASE_URL ?>/index.php#faq">FAQ</a></li>
          <li><a href="<?= HQ_BASE_URL ?>/index.php#contact">Contact</a></li>
        <?php endif; ?>
      </ul>
    </nav>

    <div class="nav-actions">
      <?php if ($hqUser): ?>
        <span class="nav-greeting">Hi, <?= htmlspecialchars($hqUser['FirstName']) ?></span>
        <a href="<?= HQ_BASE_URL ?>/<?= dashboardPathFor($hqUser['RoleName']) ?>" class="btn btn-outline btn-sm">Dashboard</a>
        <a href="<?= HQ_BASE_URL ?>/auth/logout.php" class="btn btn-primary btn-sm">Log Out</a>
      <?php else: ?>
        <a href="<?= HQ_BASE_URL ?>/auth/login.php" class="btn btn-outline btn-sm">Sign In</a>
        <a href="<?= HQ_BASE_URL ?>/index.php#clinic-registration" class="btn btn-primary btn-sm">Register Clinic</a>
      <?php endif; ?>
    </div>

    <button class="nav-toggle" id="navToggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="primaryNavigation">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>
<?php endif; ?>
