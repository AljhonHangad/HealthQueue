<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireGuest();

$pageTitle = 'Create Your Account — HealthQueue';
$pdo = getDbConnection();

$errors = [];
$values = ['first_name' => '', 'last_name' => '', 'email' => '', 'contact_number' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    foreach ($values as $field => $value) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $password        = (string) ($_POST['password'] ?? '');
    $passwordConfirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($values['first_name'] === '') $errors[] = 'First name is required.';
    if ($values['last_name'] === '')  $errors[] = 'Last name is required.';

    if ($values['email'] === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($values['contact_number'] === '') {
        $errors[] = 'Contact number is required.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $passwordConfirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if (!$errors && !$pdo) {
        $errors[] = 'Registration is temporarily unavailable. Please try again later.';
    }

    if (!$errors && $pdo) {
        try {
            $existing = $pdo->prepare('SELECT UserID FROM Users WHERE Email = ?');
            $existing->execute([$values['email']]);
            if ($existing->fetch()) {
                $errors[] = 'An account with that email already exists. Try signing in instead.';
            }
        } catch (PDOException $e) {
            error_log('Register lookup failed: ' . $e->getMessage());
            $errors[] = 'We could not process your registration right now. Please try again later.';
        }
    }

    if (!$errors && $pdo) {
        try {
            $roleStmt = $pdo->prepare('SELECT RoleID FROM Roles WHERE RoleName = ?');
            $roleStmt->execute(['Patient']);
            $role = $roleStmt->fetch();

            if (!$role) {
                $errors[] = 'Registration is temporarily unavailable. Please try again later.';
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO Users (ClinicID, RoleID, FirstName, LastName, Email, ContactNumber, PasswordHash)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $role['RoleID'],
                    $values['first_name'],
                    $values['last_name'],
                    $values['email'],
                    $values['contact_number'],
                    password_hash($password, PASSWORD_DEFAULT),
                ]);

                $newUserId = (int) $pdo->lastInsertId();
                assignUserIdNumber($pdo, $newUserId);

                loginUser([
                    'UserID'    => $newUserId,
                    'ClinicID'  => null,
                    'RoleID'    => $role['RoleID'],
                    'RoleName'  => 'Patient',
                    'FirstName' => $values['first_name'],
                    'LastName'  => $values['last_name'],
                    'Email'     => $values['email'],
                ]);

                header('Location: ' . HQ_BASE_URL . '/patient/dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('Registration failed: ' . $e->getMessage());
            $errors[] = 'We could not create your account right now. Please try again later.';
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
      <h2>Create your account</h2>
      <p>Book appointments and track your queue position online.</p>
    </div>

    <?php if ($errors): ?>
      <div class="form-message error" role="alert">
        <strong>Please check the following:</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

      <div class="form-stack form-cols-2">
        <label>First name
          <input type="text" name="first_name" value="<?= htmlspecialchars($values['first_name']) ?>" required autofocus>
        </label>
        <label>Last name
          <input type="text" name="last_name" value="<?= htmlspecialchars($values['last_name']) ?>" required>
        </label>
      </div>

      <div class="form-stack">
        <label>Email address
          <input type="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" required>
        </label>
        <label>Contact number
          <input type="tel" name="contact_number" value="<?= htmlspecialchars($values['contact_number']) ?>" placeholder="09XX XXX XXXX" required>
        </label>
        <label>Password
          <input type="password" name="password" minlength="8" required>
        </label>
        <label>Confirm password
          <input type="password" name="password_confirm" minlength="8" required>
        </label>
      </div>

      <button type="submit" class="btn btn-primary btn-block">Create account</button>
    </form>

    <p class="auth-footer-note">
      Already have an account? <a href="<?= HQ_BASE_URL ?>/auth/login.php">Sign in</a>
    </p>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
