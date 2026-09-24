<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireGuest();

$pageTitle = 'Forgot Password — HealthQueue';
$pdo = getDbConnection();

$errors = [];
$email = '';
$submitted = false;
$devResetLink = null; // Dev-mode only: see note near the form below.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!$errors && !$pdo) {
        $errors[] = 'Password reset is temporarily unavailable. Please try again later.';
    }

    if (!$errors && $pdo) {
        try {
            $stmt = $pdo->prepare('SELECT UserID FROM Users WHERE Email = ? AND DeletedAt IS NULL');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Always show the same confirmation whether or not the email
            // exists, so this form can't be used to discover which emails
            // have an account (a Data Privacy Act consideration).
            $submitted = true;

            if ($user) {
                $rawToken = createPasswordResetToken($pdo, (int) $user['UserID']);

                // No email/SMS gateway is wired up yet (that's the
                // Automated Notification Engine module), so the reset
                // link is shown directly here instead of being sent out.
                $devResetLink = HQ_BASE_URL . '/auth/reset-password.php?token=' . urlencode($rawToken);
                error_log("Password reset requested for {$email}: {$devResetLink}");
            }
        } catch (PDOException $e) {
            error_log('Forgot-password lookup failed: ' . $e->getMessage());
            $errors[] = 'We could not process your request right now. Please try again later.';
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<section class="auth-shell">
  <div class="auth-card">
    <div class="auth-card-header">
      <span class="brand-mark">
        <img src="<?= HQ_BASE_URL ?>/assets/img/logo.png" alt="">
      </span>
      <h2>Forgot your password?</h2>
      <p>Enter your email and we'll send you a reset link.</p>
    </div>

    <?php if ($errors): ?>
      <div class="form-message error" role="alert">
        <strong>Please check the following:</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if ($submitted): ?>
      <div class="form-message success" role="status">
        If an account exists for <?= htmlspecialchars($email) ?>, reset instructions have been sent.
      </div>

      <?php if ($devResetLink): ?>
        <div class="dev-note">
          <strong>Dev mode</strong> — no email/SMS gateway is connected yet, so here's the link that would have been sent:
          <a href="<?= htmlspecialchars($devResetLink) ?>"><?= htmlspecialchars($devResetLink) ?></a>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <div class="form-stack">
          <label>Email address
            <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required autofocus>
          </label>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
      </form>
    <?php endif; ?>

    <p class="auth-footer-note">
      Remembered it? <a href="<?= HQ_BASE_URL ?>/auth/login.php">Back to sign in</a>
    </p>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
