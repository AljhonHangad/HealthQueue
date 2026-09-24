<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireGuest();

$pageTitle = 'Sign In — HealthQueue';
$pdo = getDbConnection();

$errors = [];
$email = '';
$adminSetupAvailable = false;

if ($pdo) {
    try {
        $adminSetupAvailable = !(bool) $pdo->query("SELECT 1 FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE r.RoleName = 'Admin' AND u.DeletedAt IS NULL LIMIT 1")->fetchColumn();
    } catch (PDOException $e) {
        error_log('Admin setup availability check failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter both your email and password.';
    }

    if (!$errors && !$pdo) {
        $errors[] = 'Sign-in is temporarily unavailable. Please try again later.';
    }

    if (!$errors && $pdo) {
        try {
            $stmt = $pdo->prepare(
                'SELECT u.UserID, u.ClinicID, u.RoleID, r.RoleName, u.FirstName, u.LastName, u.Email, u.PasswordHash, u.Status
                 FROM Users u
                 JOIN Roles r ON r.RoleID = u.RoleID
                 WHERE u.Email = ? AND u.DeletedAt IS NULL'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['PasswordHash'])) {
                $errors[] = 'Invalid email or password.';
            } elseif ($user['Status'] !== 'Active') {
                $errors[] = 'This account is currently inactive. Please contact your clinic administrator.';
            } else {
                loginUser($user);
                header('Location: ' . HQ_BASE_URL . '/' . dashboardPathFor($user['RoleName']));
                exit;
            }
        } catch (PDOException $e) {
            error_log('Login failed: ' . $e->getMessage());
            $errors[] = 'We could not sign you in right now. Please try again later.';
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
      <h2>Sign in to HealthQueue</h2>
    </div>

    <?php if ($errors): ?>
      <div class="form-message error" role="alert">
        <strong>Please check the following:</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

      <div class="form-stack">
        <label>Email address
          <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required autofocus>
        </label>
        <label>Password
          <input type="password" name="password" required>
        </label>
      </div>

      <div class="auth-meta-row">
        <span></span>
        <a href="<?= HQ_BASE_URL ?>/auth/forgot-password.php">Forgot password?</a>
      </div>

      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>

    <p class="auth-footer-note">
      New User?<a href="<?= HQ_BASE_URL ?>/auth/register.php">Create an account</a>
    </p>
    <?php if ($adminSetupAvailable): ?>
      <p class="auth-footer-note admin-setup-link">Setting up HealthQueue? <a href="<?= HQ_BASE_URL ?>/auth/setup-admin.php">Create the first administrator</a></p>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
