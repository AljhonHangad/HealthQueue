<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireGuest();

$pdo = getDbConnection();
$errors = [];
$adminExists = false;
$values = ['first_name' => '', 'last_name' => '', 'email' => '', 'contact_number' => ''];

if (!$pdo) {
    $errors[] = 'Admin setup is temporarily unavailable. Please check the database connection.';
} else {
    try {
        $adminExists = (bool) $pdo->query("SELECT 1 FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE r.RoleName = 'Admin' AND u.DeletedAt IS NULL LIMIT 1")->fetchColumn();
    } catch (PDOException $e) {
        error_log('Admin setup check failed: ' . $e->getMessage());
        $errors[] = 'Admin setup is temporarily unavailable. Please try again later.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$adminExists && $pdo) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) $errors[] = 'Your session expired. Please try again.';
    foreach ($values as $field => $value) $values[$field] = trim((string) ($_POST[$field] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($values['first_name'] === '') $errors[] = 'First name is required.';
    if ($values['last_name'] === '') $errors[] = 'Last name is required.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($values['contact_number'] === '') $errors[] = 'Contact number is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters long.';
    elseif ($password !== $passwordConfirm) $errors[] = 'Password and confirmation do not match.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stillNoAdmin = !(bool) $pdo->query("SELECT 1 FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE r.RoleName = 'Admin' AND u.DeletedAt IS NULL LIMIT 1")->fetchColumn();
            $roleStmt = $pdo->prepare('SELECT RoleID FROM Roles WHERE RoleName = ?');
            $roleStmt->execute(['Admin']);
            $role = $roleStmt->fetch();
            $emailStmt = $pdo->prepare('SELECT UserID FROM Users WHERE Email = ? LIMIT 1');
            $emailStmt->execute([$values['email']]);

            if (!$stillNoAdmin) {
                $errors[] = 'An administrator has already been created. Sign in to continue.';
            } elseif (!$role) {
                $errors[] = 'The Admin role is missing. Import the latest database schema and try again.';
            } elseif ($emailStmt->fetch()) {
                $errors[] = 'An account with this email already exists.';
            } else {
                $insert = $pdo->prepare('INSERT INTO Users (ClinicID, RoleID, FirstName, LastName, Email, ContactNumber, PasswordHash) VALUES (NULL, ?, ?, ?, ?, ?, ?)');
                $insert->execute([$role['RoleID'], $values['first_name'], $values['last_name'], $values['email'], $values['contact_number'], password_hash($password, PASSWORD_DEFAULT)]);
                $newAdminId = (int) $pdo->lastInsertId();
                assignUserIdNumber($pdo, $newAdminId);
                $admin = ['UserID' => $newAdminId, 'ClinicID' => null, 'RoleID' => $role['RoleID'], 'RoleName' => 'Admin', 'FirstName' => $values['first_name'], 'LastName' => $values['last_name'], 'Email' => $values['email']];
                $pdo->commit();
                loginUser($admin);
                header('Location: ' . HQ_BASE_URL . '/admin/dashboard.php');
                exit;
            }
            $pdo->rollBack();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Admin setup failed: ' . $e->getMessage());
            $errors[] = 'We could not create the administrator account. Please try again.';
        }
    }
}

$pageTitle = 'Set Up Administrator — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<section class="auth-shell"><div class="auth-card">
  <div class="auth-card-header"><span class="brand-mark"><img src="<?= HQ_BASE_URL ?>/assets/img/logo.png" alt=""></span><span class="eyebrow">Initial system setup</span><h2>Create the first administrator</h2><p>This account can review clinic registrations and set up staff and physician accounts.</p></div>
  <?php if ($adminExists): ?><div class="form-message success" role="status">An administrator account already exists. <a href="<?= HQ_BASE_URL ?>/auth/login.php">Sign in</a> to continue.</div>
  <?php else: ?>
    <?php if ($errors): ?><div class="form-message error" role="alert"><strong>Please check the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><div class="form-stack form-cols-2"><label>First name<input name="first_name" value="<?= htmlspecialchars($values['first_name']) ?>" required autofocus></label><label>Last name<input name="last_name" value="<?= htmlspecialchars($values['last_name']) ?>" required></label></div><div class="form-stack"><label>Email address<input type="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" required></label><label>Contact number<input type="tel" name="contact_number" value="<?= htmlspecialchars($values['contact_number']) ?>" required></label><label>Password<input type="password" name="password" minlength="8" required></label><label>Confirm password<input type="password" name="password_confirm" minlength="8" required></label></div><button class="btn btn-primary btn-block" type="submit">Create administrator account</button></form>
  <?php endif; ?>
</div></section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
