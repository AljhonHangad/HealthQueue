<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$profile = ['FirstName' => $user['FirstName'], 'LastName' => $user['LastName'], 'Email' => $user['Email'], 'ContactNumber' => '', 'ProfilePhoto' => null];

if ($pdo) {
    try {
        $stmt = $pdo->prepare('SELECT FirstName, LastName, Email, ContactNumber, ProfilePhoto FROM Users WHERE UserID = ?');
        $stmt->execute([$user['UserID']]);
        if ($row = $stmt->fetch()) {
            $profile = $row;
        }
    } catch (PDOException $e) {
        error_log('Staff profile load failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'update_profile') {
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName  = trim((string) ($_POST['last_name'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $contact   = trim((string) ($_POST['contact_number'] ?? ''));

        if ($firstName === '' || $lastName === '') $errors[] = 'First and last name are required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

        if (!$errors) {
            try {
                $dupe = $pdo->prepare('SELECT UserID FROM Users WHERE Email = ? AND UserID <> ?');
                $dupe->execute([$email, $user['UserID']]);
                if ($dupe->fetch()) {
                    $errors[] = 'Another account already uses that email address.';
                } else {
                    $pdo->prepare('UPDATE Users SET FirstName = ?, LastName = ?, Email = ?, ContactNumber = ? WHERE UserID = ?')
                        ->execute([$firstName, $lastName, $email, $contact ?: null, $user['UserID']]);
                    loginUser(['UserID' => $user['UserID'], 'ClinicID' => $user['ClinicID'], 'RoleID' => $user['RoleID'], 'RoleName' => $user['RoleName'], 'FirstName' => $firstName, 'LastName' => $lastName, 'Email' => $email]);
                    logActivity($pdo, $user['UserID'], $user['ClinicID'], 'Updated profile', 'Staff updated their own account details.');
                    $flash = 'Profile updated.';
                    $profile['FirstName'] = $firstName;
                    $profile['LastName'] = $lastName;
                    $profile['Email'] = $email;
                    $profile['ContactNumber'] = $contact;
                    $user = currentUser();
                }
            } catch (PDOException $e) {
                error_log('Staff profile update failed: ' . $e->getMessage());
                $errors[] = 'We could not update your profile right now.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'change_password') {
        $current  = (string) ($_POST['current_password'] ?? '');
        $newPass  = (string) ($_POST['new_password'] ?? '');
        $confirm  = (string) ($_POST['new_password_confirm'] ?? '');

        try {
            $stmt = $pdo->prepare('SELECT PasswordHash FROM Users WHERE UserID = ?');
            $stmt->execute([$user['UserID']]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($current, $row['PasswordHash'])) {
                $errors[] = 'Your current password is incorrect.';
            } elseif (strlen($newPass) < 8) {
                $errors[] = 'New password must be at least 8 characters long.';
            } elseif ($newPass !== $confirm) {
                $errors[] = 'New password and confirmation do not match.';
            } else {
                $pdo->prepare('UPDATE Users SET PasswordHash = ? WHERE UserID = ?')
                    ->execute([password_hash($newPass, PASSWORD_DEFAULT), $user['UserID']]);
                logActivity($pdo, $user['UserID'], $user['ClinicID'], 'Changed password', 'Staff changed their own password.');
                $flash = 'Password changed.';
            }
        } catch (PDOException $e) {
            error_log('Staff password change failed: ' . $e->getMessage());
            $errors[] = 'We could not change your password right now.';
        }
    } elseif (($_POST['form_type'] ?? '') === 'change_photo') {
        $file = $_FILES['photo'] ?? null;
        $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) {
            $errors[] = 'Please choose a photo to upload.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Photo is too large (2 MB limit).';
        } else {
            $mime = mime_content_type($file['tmp_name']);
            if (!isset($allowedTypes[$mime])) {
                $errors[] = 'Please upload a JPG, PNG, GIF, or WEBP image.';
            } else {
                try {
                    $uploadDir = __DIR__ . '/../assets/uploads/avatars';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $filename = 'user_' . $user['UserID'] . '_' . bin2hex(random_bytes(6)) . '.' . $allowedTypes[$mime];

                    if (!empty($profile['ProfilePhoto']) && is_file($uploadDir . '/' . basename($profile['ProfilePhoto']))) {
                        unlink($uploadDir . '/' . basename($profile['ProfilePhoto']));
                    }

                    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
                        $errors[] = 'We could not save this photo. Please try again.';
                    } else {
                        $pdo->prepare('UPDATE Users SET ProfilePhoto = ? WHERE UserID = ?')->execute([$filename, $user['UserID']]);
                        logActivity($pdo, $user['UserID'], $user['ClinicID'], 'Changed profile photo', null);
                        $flash = 'Profile photo updated.';
                        $profile['ProfilePhoto'] = $filename;
                    }
                } catch (PDOException $e) {
                    error_log('Photo update failed: ' . $e->getMessage());
                    $errors[] = 'We could not save this photo. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'My Profile — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Account settings</span><h1>My profile</h1><p>Update your own account details, photo, and password.</p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Photo</span><h2>Profile photo</h2></div></div>
    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
      <?php if (!empty($profile['ProfilePhoto'])): ?>
        <img src="<?= HQ_BASE_URL ?>/assets/uploads/avatars/<?= htmlspecialchars($profile['ProfilePhoto']) ?>" alt="Profile photo" style="width:72px;height:72px;border-radius:50%;object-fit:cover;">
      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:50%;background:var(--slate-100);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--slate-500);font-size:22px;"><?= htmlspecialchars(strtoupper(substr($profile['FirstName'], 0, 1) . substr($profile['LastName'], 0, 1))) ?></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="form_type" value="change_photo">
        <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" required>
        <button type="submit" class="btn btn-outline btn-sm">Change Photo</button>
      </form>
    </div>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Profile details</span><h2>Your information</h2></div></div>
    <form class="registration-form" method="post" style="max-width:560px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="update_profile">
      <div class="form-stack form-cols-2">
        <label>First name<input name="first_name" value="<?= htmlspecialchars($profile['FirstName']) ?>" required></label>
        <label>Last name<input name="last_name" value="<?= htmlspecialchars($profile['LastName']) ?>" required></label>
      </div>
      <div class="form-stack">
        <label>Email address<input type="email" name="email" value="<?= htmlspecialchars($profile['Email']) ?>" required></label>
        <label>Contact number<input type="tel" name="contact_number" value="<?= htmlspecialchars($profile['ContactNumber'] ?? '') ?>"></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
    </form>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Security</span><h2>Change password</h2></div></div>
    <form class="registration-form" method="post" style="max-width:560px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="change_password">
      <div class="form-stack">
        <label>Current password<input type="password" name="current_password" required></label>
        <label>New password<input type="password" name="new_password" minlength="8" required></label>
        <label>Confirm new password<input type="password" name="new_password_confirm" minlength="8" required></label>
      </div>
      <button type="submit" class="btn btn-outline btn-block">Change Password</button>
    </form>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
