<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireGuest();

$pageTitle = 'Reset Password — HealthQueue';
$pdo = getDbConnection();

$errors = [];
$success = false;
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$reset = null;

if ($token === '') {
    $errors[] = 'This password reset link is invalid.';
} elseif (!$pdo) {
    $errors[] = 'Password reset is temporarily unavailable. Please try again later.';
} else {
    try {
        $reset = findValidPasswordReset($pdo, $token);
        if (!$reset) {
            $errors[] = 'This password reset link is invalid or has expired. Please request a new one.';
        }
    } catch (PDOException $e) {
        error_log('Reset-password lookup failed: ' . $e->getMessage());
        $errors[] = 'We could not process your request right now. Please try again later.';
    }
}

// A valid, unexpired, unused token is confirmed above — now handle the form.
if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $password        = (string) ($_POST['password'] ?? '');
    $passwordConfirm  = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $passwordConfirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if (!$errors) {
        try {
            $update = $pdo->prepare('UPDATE Users SET PasswordHash = ? WHERE UserID = ?');
            $update->execute([password_hash($password, PASSWORD_DEFAULT), $reset['UserID']]);
            consumePasswordResetToken($pdo, (int) $reset['ResetID']);
            $success = true;
        } catch (PDOException $e) {
            error_log('Password reset failed: ' . $e->getMessage());
            $errors[] = 'We could not reset your password right now. Please try again later.';
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
      <h2>Set a new password</h2>
      <?php if ($reset && !$success): ?>
        <p>Choose a new password for <?= htmlspecialchars($reset['Email']) ?>.</p>
      <?php endif; ?>
    </div>

    <?php if ($success): ?>
      <div class="form-message success" role="status">Your password has been reset.</div>
      <a href="<?= HQ_BASE_URL ?>/auth/login.php" class="btn btn-primary btn-block">Sign in</a>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="form-message error" role="alert">
          <strong>Please check the following:</strong>
          <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <?php if ($reset): ?>
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          <div class="form-stack">
            <label>New password
              <input type="password" name="password" minlength="8" required autofocus>
            </label>
            <label>Confirm new password
              <input type="password" name="password_confirm" minlength="8" required>
            </label>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Reset password</button>
        </form>
      <?php else: ?>
        <a href="<?= HQ_BASE_URL ?>/auth/forgot-password.php" class="btn btn-outline btn-block">Request a new link</a>
      <?php endif; ?>

      <p class="auth-footer-note">
        <a href="<?= HQ_BASE_URL ?>/auth/login.php">Back to sign in</a>
      </p>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
