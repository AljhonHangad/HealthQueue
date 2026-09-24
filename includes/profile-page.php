<?php
/**
 * Shared "My Profile" page for Staff, Physician and Admin accounts -- same
 * layout as the patient profile (profile card + Personal Information, with
 * modal editing), minus the patient-only medical sections.
 *
 * The including page must already have loaded config/db.php, auth.php and
 * audit.php, defined HQ_BASE_URL and called requireRole(). It may set:
 *   $profileContactRequired (bool) -- physicians must keep a contact number.
 */
$profileContactRequired = $profileContactRequired ?? false;

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$roleName = $user['RoleName'];
$logClinicId = $user['ClinicID'] ? (int) $user['ClinicID'] : null;
$profile = [
    'IDNumber' => null, 'FirstName' => $user['FirstName'], 'LastName' => $user['LastName'], 'Email' => $user['Email'],
    'ContactNumber' => '', 'ProfilePhoto' => null, 'CreatedAt' => null, 'ClinicName' => null, 'AvailabilityStatus' => null,
];

if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            'SELECT u.IDNumber, u.FirstName, u.LastName, u.Email, u.ContactNumber, u.ProfilePhoto, u.CreatedAt, u.AvailabilityStatus, c.ClinicName
             FROM Users u LEFT JOIN Clinic c ON c.ClinicID = u.ClinicID WHERE u.UserID = ?'
        );
        $stmt->execute([$user['UserID']]);
        if ($row = $stmt->fetch()) {
            $profile = $row;
            // Accounts created before ID numbers existed get one on first view.
            if (empty($profile['IDNumber'])) $profile['IDNumber'] = assignUserIdNumber($pdo, (int) $user['UserID']);
        }
    } catch (PDOException $e) {
        error_log($roleName . ' profile load failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif ($formType === 'update_profile') {
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName  = trim((string) ($_POST['last_name'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $contact   = trim((string) ($_POST['contact_number'] ?? ''));

        if ($firstName === '' || $lastName === '') $errors[] = 'First and last name are required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if ($profileContactRequired && $contact === '') $errors[] = 'Contact number is required.';

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
                    logActivity($pdo, $user['UserID'], $logClinicId, 'Updated profile', $roleName . ' updated their own account details.');
                    $flash = 'Profile updated.';
                    $profile['FirstName'] = $firstName;
                    $profile['LastName'] = $lastName;
                    $profile['Email'] = $email;
                    $profile['ContactNumber'] = $contact;
                    $user = currentUser();
                }
            } catch (PDOException $e) {
                error_log($roleName . ' profile update failed: ' . $e->getMessage());
                $errors[] = 'We could not update your profile right now.';
            }
        }
    } elseif ($formType === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $newPass = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

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
                logActivity($pdo, $user['UserID'], $logClinicId, 'Changed password', $roleName . ' changed their own password.');
                $flash = 'Password changed.';
            }
        } catch (PDOException $e) {
            error_log($roleName . ' password change failed: ' . $e->getMessage());
            $errors[] = 'We could not change your password right now.';
        }
    } elseif ($formType === 'change_photo') {
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
                        logActivity($pdo, $user['UserID'], $logClinicId, 'Changed profile photo', null);
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

// On a validation error, reopen the modal the user was working in and keep
// what they typed.
$reopenModal = null;
$old = [];
if ($errors) {
    $formType = $_POST['form_type'] ?? '';
    $reopenModal = ['change_password' => 'changePasswordModal', 'change_photo' => 'photoModal', 'update_profile' => 'personalInfoModal'][$formType] ?? null;
    if ($formType === 'update_profile') $old = $_POST;
}
$formValue = static function (string $postKey, ?string $current) use ($old): string {
    return htmlspecialchars((string) ($old[$postKey] ?? $current ?? ''));
};

$roleLabels = ['Admin' => 'System Administrator', 'Staff' => 'Clinic Staff', 'Physician' => 'Physician'];
$availabilityDots = ['Available' => '#22c55e', 'On Break' => '#0077b3', 'Unavailable' => '#94a3b8'];
$notProvided = '<span class="info-empty">Not provided</span>';

$pageTitle = 'My Profile — HealthQueue';
require __DIR__ . '/header.php';
?>

<main class="portal-shell"><div class="profile-container">
  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors && !$reopenModal): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <div class="profile-grid">
    <aside class="profile-card">
      <div class="profile-banner"></div>
      <div class="profile-avatar-wrap">
        <?php if (!empty($profile['ProfilePhoto'])): ?>
          <img src="<?= HQ_BASE_URL ?>/assets/uploads/avatars/<?= htmlspecialchars($profile['ProfilePhoto']) ?>" alt="Profile photo">
        <?php else: ?>
          <div class="profile-avatar-fallback"><?= htmlspecialchars(strtoupper(substr($profile['FirstName'], 0, 1) . substr($profile['LastName'], 0, 1))) ?></div>
        <?php endif; ?>
        <button type="button" class="profile-avatar-edit" data-modal-open="photoModal" aria-label="Change photo">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/></svg>
        </button>
      </div>
      <h3 class="profile-name"><?= htmlspecialchars(($roleName === 'Physician' ? 'Dr. ' : '') . $profile['FirstName'] . ' ' . $profile['LastName']) ?></h3>
      <p class="profile-email"><?= htmlspecialchars($profile['Email']) ?></p>

      <dl class="profile-meta-list">
        <div class="profile-meta-row"><dt>ID Number</dt><dd><?= !empty($profile['IDNumber']) ? htmlspecialchars($profile['IDNumber']) : '<span class="info-empty">Not assigned</span>' ?></dd></div>
        <div class="profile-meta-row"><dt>Contact Number</dt><dd><?= $profile['ContactNumber'] ? htmlspecialchars($profile['ContactNumber']) : $notProvided ?></dd></div>
        <div class="profile-meta-row"><dt>Member Since</dt><dd><?= $profile['CreatedAt'] ? htmlspecialchars(date('M Y', strtotime($profile['CreatedAt']))) : $notProvided ?></dd></div>
      </dl>

      <button type="button" class="btn btn-outline btn-block" data-modal-open="changePasswordModal" style="margin-top:22px;">Change Password</button>
    </aside>

    <div class="profile-info-column">
      <div class="info-card">
        <div class="info-card-header">
          <h2>Personal Information</h2>
          <button type="button" class="text-link" data-modal-open="personalInfoModal">Edit</button>
        </div>
        <div class="info-fields-grid">
          <div class="info-field">
            <span class="info-field-label">First name</span>
            <p class="info-field-value"><?= htmlspecialchars($profile['FirstName']) ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Last name</span>
            <p class="info-field-value"><?= htmlspecialchars($profile['LastName']) ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Email address</span>
            <p class="info-field-value"><?= htmlspecialchars($profile['Email']) ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Contact number</span>
            <p class="info-field-value"><?= $profile['ContactNumber'] ? htmlspecialchars($profile['ContactNumber']) : $notProvided ?></p>
          </div>
        </div>
      </div>

      <div class="info-card">
        <div class="info-card-header">
          <h2>Account Information</h2>
        </div>
        <div class="info-fields-grid">
          <div class="info-field">
            <span class="info-field-label">Role</span>
            <p class="info-field-value"><?= htmlspecialchars($roleLabels[$roleName] ?? $roleName) ?></p>
          </div>
          <?php if ($roleName !== 'Admin'): ?>
            <div class="info-field">
              <span class="info-field-label">Clinic</span>
              <p class="info-field-value"><?= $profile['ClinicName'] ? htmlspecialchars($profile['ClinicName']) : '<span class="info-empty">Not assigned</span>' ?></p>
            </div>
          <?php endif; ?>
          <?php if ($roleName === 'Physician'): ?>
            <div class="info-field">
              <span class="info-field-label">Availability</span>
              <p class="info-field-value" style="display:flex;align-items:center;gap:8px;"><span style="width:9px;height:9px;border-radius:50%;background:<?= $availabilityDots[$profile['AvailabilityStatus']] ?? '#94a3b8' ?>;"></span><?= htmlspecialchars($profile['AvailabilityStatus'] ?? 'Available') ?></p>
            </div>
          <?php endif; ?>
          <div class="info-field">
            <span class="info-field-label">ID number</span>
            <p class="info-field-value"><?= !empty($profile['IDNumber']) ? htmlspecialchars($profile['IDNumber']) : '<span class="info-empty">Not assigned</span>' ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div></main>

<div class="modal-overlay" id="personalInfoModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Personal Information</h2>
    <?php if ($reopenModal === 'personalInfoModal' && $errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="update_profile">
      <div class="form-stack">
        <label>First name<input type="text" name="first_name" value="<?= $formValue('first_name', $profile['FirstName']) ?>" required></label>
        <label>Last name<input type="text" name="last_name" value="<?= $formValue('last_name', $profile['LastName']) ?>" required></label>
        <label>Email address<input type="email" name="email" value="<?= $formValue('email', $profile['Email']) ?>" required></label>
        <label>Contact number<input type="tel" name="contact_number" value="<?= $formValue('contact_number', $profile['ContactNumber']) ?>"<?= $profileContactRequired ? ' required' : '' ?>></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Save Changes</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="photoModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Profile photo</h2>
    <p class="modal-subtitle">JPG, PNG, GIF, or WEBP — 2 MB max.</p>
    <?php if ($reopenModal === 'photoModal' && $errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="change_photo">
      <div class="form-stack">
        <label>Choose a photo<input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" required></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Upload Photo</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="changePasswordModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Change Password</h2>
    <?php if ($reopenModal === 'changePasswordModal' && $errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="change_password">
      <div class="form-stack">
        <label>Current password<input type="password" name="current_password" required></label>
        <label>New password<input type="password" name="new_password" minlength="8" required></label>
        <label>Confirm new password<input type="password" name="new_password_confirm" minlength="8" required></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Change Password</button>
    </form>
  </div>
</div>

<?php if ($reopenModal): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  window.hqOpenModal(document.getElementById('<?= $reopenModal ?>'));
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
