<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$profile = ['FirstName' => $user['FirstName'], 'LastName' => $user['LastName'], 'Email' => $user['Email'], 'ContactNumber' => '', 'ProfilePhoto' => null, 'CreatedAt' => null, 'BloodType' => null, 'Allergies' => null, 'EmergencyContactName' => null, 'EmergencyContactNumber' => null];
$bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

if ($pdo) {
    try {
        $stmt = $pdo->prepare('SELECT FirstName, LastName, Email, ContactNumber, ProfilePhoto, CreatedAt, BloodType, Allergies, EmergencyContactName, EmergencyContactNumber FROM Users WHERE UserID = ?');
        $stmt->execute([$user['UserID']]);
        if ($row = $stmt->fetch()) {
            $profile = $row;
        }
    } catch (PDOException $e) {
        error_log('Patient profile load failed: ' . $e->getMessage());
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
                    logActivity($pdo, $user['UserID'], null, 'Updated profile', 'Patient updated their own account details.');
                    $flash = 'Profile updated.';
                    $profile['FirstName'] = $firstName;
                    $profile['LastName'] = $lastName;
                    $profile['Email'] = $email;
                    $profile['ContactNumber'] = $contact;
                    $user = currentUser();
                }
            } catch (PDOException $e) {
                error_log('Patient profile update failed: ' . $e->getMessage());
                $errors[] = 'We could not update your profile right now.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'update_medical') {
        $bloodType    = trim((string) ($_POST['blood_type'] ?? ''));
        $allergies    = trim((string) ($_POST['allergies'] ?? ''));
        $emergName    = trim((string) ($_POST['emergency_contact_name'] ?? ''));
        $emergNumber  = trim((string) ($_POST['emergency_contact_number'] ?? ''));

        if ($bloodType !== '' && !in_array($bloodType, $bloodTypes, true)) $errors[] = 'Please choose a valid blood type.';
        if (mb_strlen($allergies) > 500) $errors[] = 'Allergies must be 500 characters or fewer.';
        if (mb_strlen($emergName) > 100) $errors[] = 'Emergency contact name must be 100 characters or fewer.';
        if (mb_strlen($emergNumber) > 20) $errors[] = 'Emergency contact number must be 20 characters or fewer.';

        if (!$errors) {
            try {
                $pdo->prepare('UPDATE Users SET BloodType = ?, Allergies = ?, EmergencyContactName = ?, EmergencyContactNumber = ? WHERE UserID = ?')
                    ->execute([$bloodType ?: null, $allergies ?: null, $emergName ?: null, $emergNumber ?: null, $user['UserID']]);
                logActivity($pdo, $user['UserID'], null, 'Updated medical information', 'Patient updated their own medical information.');
                $flash = 'Medical information updated.';
                $profile['BloodType'] = $bloodType ?: null;
                $profile['Allergies'] = $allergies ?: null;
                $profile['EmergencyContactName'] = $emergName ?: null;
                $profile['EmergencyContactNumber'] = $emergNumber ?: null;
            } catch (PDOException $e) {
                error_log('Patient medical info update failed: ' . $e->getMessage());
                $errors[] = 'We could not update your medical information right now.';
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
                logActivity($pdo, $user['UserID'], null, 'Changed password', 'Patient changed their own password.');
                $flash = 'Password changed.';
            }
        } catch (PDOException $e) {
            error_log('Patient password change failed: ' . $e->getMessage());
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
                        logActivity($pdo, $user['UserID'], null, 'Changed profile photo', null);
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

// If a form failed validation, reopen the modal the patient was editing in
// instead of stranding the error behind a closed modal. Submitted values are
// echoed back ($old) so the patient doesn't have to retype them.
$reopenModal = null;
$old = [];
if ($errors) {
    $formType = $_POST['form_type'] ?? '';
    $reopenModal = [
        'change_password' => 'changePasswordModal',
        'change_photo'    => 'photoModal',
        'update_profile'  => 'personalInfoModal',
        'update_medical'  => 'medicalInfoModal',
    ][$formType] ?? null;
    if (in_array($formType, ['update_profile', 'update_medical'], true)) {
        $old = $_POST;
    }
}
$formValue = function (string $postKey, ?string $current) use ($old): string {
    return htmlspecialchars((string) ($old[$postKey] ?? $current ?? ''));
};

$pageTitle = 'My Profile';
require __DIR__ . '/../includes/header.php';
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
      <h3 class="profile-name"><?= htmlspecialchars($profile['FirstName'] . ' ' . $profile['LastName']) ?></h3>
      <p class="profile-email"><?= htmlspecialchars($profile['Email']) ?></p>

      <dl class="profile-meta-list">
        <div class="profile-meta-row"><dt>Patient ID</dt><dd>#<?= (int) $user['UserID'] ?></dd></div>
        <div class="profile-meta-row"><dt>Contact Number</dt><dd><?= $profile['ContactNumber'] ? htmlspecialchars($profile['ContactNumber']) : '<span class="info-empty">Not provided</span>' ?></dd></div>
        <div class="profile-meta-row"><dt>Member Since</dt><dd><?= $profile['CreatedAt'] ? htmlspecialchars(date('M Y', strtotime($profile['CreatedAt']))) : '<span class="info-empty">Not provided</span>' ?></dd></div>
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
            <p class="info-field-value"><?= $profile['ContactNumber'] ? htmlspecialchars($profile['ContactNumber']) : '<span class="info-empty">Not provided</span>' ?></p>
          </div>
        </div>
      </div>

      <div class="info-card">
        <div class="info-card-header">
          <h2>Medical Information</h2>
          <button type="button" class="text-link" data-modal-open="medicalInfoModal">Edit</button>
        </div>
        <div class="info-fields-grid">
          <div class="info-field">
            <span class="info-field-label">Blood type</span>
            <p class="info-field-value"><?= $profile['BloodType'] ? htmlspecialchars($profile['BloodType']) : '<span class="info-empty">Not provided</span>' ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Allergies</span>
            <p class="info-field-value"><?= $profile['Allergies'] ? nl2br(htmlspecialchars($profile['Allergies'])) : '<span class="info-empty">Not provided</span>' ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Emergency contact</span>
            <p class="info-field-value"><?php
              $emergency = trim(($profile['EmergencyContactName'] ?? '') . ($profile['EmergencyContactNumber'] ? ' · ' . $profile['EmergencyContactNumber'] : ''), ' ·');
              echo $emergency !== '' ? htmlspecialchars($emergency) : '<span class="info-empty">Not provided</span>';
            ?></p>
          </div>
        </div>
        <a href="<?= HQ_BASE_URL ?>/patient/medical-records.php" class="text-link" style="display:inline-block;margin-top:14px;">Manage medical records <span>&rarr;</span></a>
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
        <label>Contact number<input type="tel" name="contact_number" value="<?= $formValue('contact_number', $profile['ContactNumber']) ?>"></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Save Changes</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="medicalInfoModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Medical Information</h2>
    <?php if ($reopenModal === 'medicalInfoModal' && $errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="update_medical">
      <?php $selectedBlood = $old['blood_type'] ?? $profile['BloodType'] ?? ''; ?>
      <div class="form-stack">
        <label>Blood type
          <select name="blood_type">
            <option value="">Not specified</option>
            <?php foreach ($bloodTypes as $type): ?>
              <option value="<?= $type ?>"<?= $selectedBlood === $type ? ' selected' : '' ?>><?= $type ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Allergies<textarea name="allergies" rows="3" maxlength="500" placeholder="e.g. Penicillin, peanuts"><?= $formValue('allergies', $profile['Allergies']) ?></textarea></label>
        <label>Emergency contact name<input type="text" name="emergency_contact_name" maxlength="100" value="<?= $formValue('emergency_contact_name', $profile['EmergencyContactName']) ?>"></label>
        <label>Emergency contact number<input type="tel" name="emergency_contact_number" maxlength="20" value="<?= $formValue('emergency_contact_number', $profile['EmergencyContactNumber']) ?>"></label>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
