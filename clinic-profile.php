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
      <button type="button" class="btn btn-primary" data-book-clinic="<?= (int) $clinic['ClinicID'] ?>">Book Now</button>
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
<?php // Shared booking modal: only times inside physicians' published hours are offered. ?>
<?php require __DIR__ . '/includes/booking-modal.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
