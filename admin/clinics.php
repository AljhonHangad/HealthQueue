<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$showArchived = isset($_GET['archived']);
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: null;
$search = trim((string) ($_GET['q'] ?? ''));
$values = ['clinic_name' => '', 'address' => '', 'contact_number' => '', 'base_consultation_fee' => '', 'description' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'save_clinic') {
        $clinicId = filter_input(INPUT_POST, 'clinic_id', FILTER_VALIDATE_INT) ?: null;
        $values['clinic_name'] = trim((string) ($_POST['clinic_name'] ?? ''));
        $values['address'] = trim((string) ($_POST['address'] ?? ''));
        $values['contact_number'] = trim((string) ($_POST['contact_number'] ?? ''));
        $values['base_consultation_fee'] = trim((string) ($_POST['base_consultation_fee'] ?? ''));
        $values['description'] = trim((string) ($_POST['description'] ?? ''));

        if ($values['clinic_name'] === '') $errors[] = 'Clinic name is required.';
        if ($values['address'] === '') $errors[] = 'Address is required.';
        if ($values['contact_number'] === '') $errors[] = 'Contact number is required.';
        if (!is_numeric($values['base_consultation_fee']) || (float) $values['base_consultation_fee'] < 0) {
            $errors[] = 'Consultation fee must be a valid non-negative amount.';
        }

        $newPhotoFilename = null;
        $photoFile = $_FILES['photo'] ?? null;
        if ($photoFile && $photoFile['error'] !== UPLOAD_ERR_NO_FILE) {
            $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if ($photoFile['error'] !== UPLOAD_ERR_OK || $photoFile['size'] <= 0) {
                $errors[] = 'We could not read the uploaded photo. Please try again.';
            } elseif ($photoFile['size'] > 4 * 1024 * 1024) {
                $errors[] = 'Clinic photo is too large (4 MB limit).';
            } else {
                $mime = mime_content_type($photoFile['tmp_name']);
                if (!isset($allowedTypes[$mime])) {
                    $errors[] = 'Please upload a JPG, PNG, or WEBP image for the clinic photo.';
                } else {
                    $newPhotoFilename = 'clinic_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mime];
                }
            }
        }

        if (!$errors) {
            try {
                $uploadDir = __DIR__ . '/../assets/uploads/clinics';
                if ($newPhotoFilename && !is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                if ($clinicId) {
                    $oldPhotoStmt = $pdo->prepare('SELECT PhotoUrl FROM Clinic WHERE ClinicID = ?');
                    $oldPhotoStmt->execute([$clinicId]);
                    $oldPhoto = $oldPhotoStmt->fetchColumn();

                    $sql = 'UPDATE Clinic SET ClinicName = ?, Address = ?, ContactNumber = ?, BaseConsultationFee = ?, Description = ?';
                    $params = [$values['clinic_name'], $values['address'], $values['contact_number'], (float) $values['base_consultation_fee'], $values['description'] ?: null];
                    if ($newPhotoFilename) {
                        $sql .= ', PhotoUrl = ?';
                        $params[] = $newPhotoFilename;
                    }
                    $sql .= ' WHERE ClinicID = ?';
                    $params[] = $clinicId;

                    if ($newPhotoFilename && !move_uploaded_file($photoFile['tmp_name'], $uploadDir . '/' . $newPhotoFilename)) {
                        $errors[] = 'We could not save the clinic photo. Please try again.';
                    } else {
                        $pdo->prepare($sql)->execute($params);
                        if ($newPhotoFilename && $oldPhoto && is_file($uploadDir . '/' . basename($oldPhoto))) {
                            unlink($uploadDir . '/' . basename($oldPhoto));
                        }
                        logActivity($pdo, $user['UserID'], $clinicId, 'Updated clinic', $values['clinic_name']);
                        $flash = 'Clinic updated.';
                    }
                } else {
                    $stmt = $pdo->prepare('INSERT INTO Clinic (ClinicName, Address, ContactNumber, BaseConsultationFee, Description, PhotoUrl) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$values['clinic_name'], $values['address'], $values['contact_number'], (float) $values['base_consultation_fee'], $values['description'] ?: null, $newPhotoFilename]);
                    $newClinicId = (int) $pdo->lastInsertId();

                    if ($newPhotoFilename && !move_uploaded_file($photoFile['tmp_name'], $uploadDir . '/' . $newPhotoFilename)) {
                        $pdo->prepare('UPDATE Clinic SET PhotoUrl = NULL WHERE ClinicID = ?')->execute([$newClinicId]);
                    }

                    logActivity($pdo, $user['UserID'], $newClinicId, 'Created clinic', $values['clinic_name']);
                    $flash = 'Clinic added.';
                }

                if (!$errors) {
                    header('Location: ' . HQ_BASE_URL . '/admin/clinics.php');
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Clinic save failed: ' . $e->getMessage());
                $errors[] = 'We could not save this clinic.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'update_clinic_status') {
        $clinicId = filter_input(INPUT_POST, 'clinic_id', FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? '';
        if (!$clinicId || !in_array($status, ['Active', 'Inactive'], true)) {
            $errors[] = 'Invalid clinic status update.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE Clinic SET Status = ? WHERE ClinicID = ? AND archived = 0');
                $stmt->execute([$status, $clinicId]);
                if ($stmt->rowCount()) {
                    logActivity($pdo, $user['UserID'], $clinicId, 'Changed clinic status', "Set to {$status}.");
                    $flash = 'Clinic status updated.';
                } else {
                    $errors[] = 'The clinic could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Clinic status update failed: ' . $e->getMessage());
                $errors[] = 'We could not update the clinic status.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'archive_clinic') {
        $clinicId = filter_input(INPUT_POST, 'clinic_id', FILTER_VALIDATE_INT);
        if (!$clinicId) {
            $errors[] = 'Invalid clinic.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE Clinic SET archived = 1, DeletedAt = NOW(), Status = 'Inactive' WHERE ClinicID = ?");
                $stmt->execute([$clinicId]);
                logActivity($pdo, $user['UserID'], $clinicId, 'Archived clinic', 'Removed from the active directory.');
                $flash = 'Clinic archived. It no longer appears to patients or in the active directory.';
            } catch (PDOException $e) {
                error_log('Clinic archive failed: ' . $e->getMessage());
                $errors[] = 'We could not archive this clinic.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'restore_clinic') {
        $clinicId = filter_input(INPUT_POST, 'clinic_id', FILTER_VALIDATE_INT);
        if (!$clinicId) {
            $errors[] = 'Invalid clinic.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE Clinic SET archived = 0, DeletedAt = NULL, Status = 'Inactive' WHERE ClinicID = ?");
                $stmt->execute([$clinicId]);
                logActivity($pdo, $user['UserID'], $clinicId, 'Restored clinic', 'Restored from the archive as Inactive.');
                $flash = 'Clinic restored. Activate it to let patients book again.';
            } catch (PDOException $e) {
                error_log('Clinic restore failed: ' . $e->getMessage());
                $errors[] = 'We could not restore this clinic.';
            }
        }
    }
}

$clinics = [];
$editingClinic = null;
if ($pdo) {
    try {
        $sql = 'SELECT ClinicID, ClinicName, Address, ContactNumber, BaseConsultationFee, PhotoUrl, Status, archived FROM Clinic WHERE archived = ?';
        $params = [$showArchived ? 1 : 0];
        if ($search !== '') {
            $sql .= ' AND (ClinicName LIKE ? OR Address LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $sql .= ' ORDER BY ClinicName';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clinics = $stmt->fetchAll();

        if ($editId) {
            $editStmt = $pdo->prepare('SELECT ClinicID, ClinicName, Address, ContactNumber, BaseConsultationFee, Description, PhotoUrl FROM Clinic WHERE ClinicID = ?');
            $editStmt->execute([$editId]);
            $editingClinic = $editStmt->fetch();
            if ($editingClinic) {
                $values = [
                    'clinic_name' => $editingClinic['ClinicName'],
                    'address' => $editingClinic['Address'],
                    'contact_number' => $editingClinic['ContactNumber'],
                    'base_consultation_fee' => number_format((float) $editingClinic['BaseConsultationFee'], 2, '.', ''),
                    'description' => $editingClinic['Description'] ?? '',
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('Clinics list failed: ' . $e->getMessage());
        $errors[] = 'Clinic information is temporarily unavailable.';
    }
}

$pageTitle = 'Manage Clinics — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell"><div class="container">
  <section class="admin-hero"><div><span class="eyebrow">Clinic directory</span><h1>Manage clinics</h1><p>Add new clinics, edit their details, or archive ones no longer partnered with HealthQueue.</p></div><a href="<?= HQ_BASE_URL ?>/admin/export.php?type=clinics" class="btn btn-outline">Export CSV</a></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="admin-section" id="clinic-form">
    <div class="portal-heading"><div><span class="section-kicker"><?= $editingClinic ? 'Edit clinic' : 'Add clinic' ?></span><h2><?= $editingClinic ? htmlspecialchars($editingClinic['ClinicName']) : 'New clinic' ?></h2></div></div>
    <form class="registration-form" method="post" enctype="multipart/form-data" style="max-width:640px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="save_clinic">
      <?php if ($editingClinic): ?><input type="hidden" name="clinic_id" value="<?= (int) $editingClinic['ClinicID'] ?>"><?php endif; ?>
      <div class="form-stack">
        <label>Clinic name<input name="clinic_name" value="<?= htmlspecialchars($values['clinic_name']) ?>" required></label>
        <label>Address<input name="address" value="<?= htmlspecialchars($values['address']) ?>" required></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Contact number<input type="tel" name="contact_number" value="<?= htmlspecialchars($values['contact_number']) ?>" required></label>
        <label>Consultation fee (PHP)<input type="number" step="0.01" min="0" name="base_consultation_fee" value="<?= htmlspecialchars($values['base_consultation_fee']) ?>" required></label>
      </div>
      <div class="form-stack">
        <label>Description <span class="optional">(shown on the clinic's public profile)</span><textarea name="description" rows="4" placeholder="A short description of this clinic for patients."><?= htmlspecialchars($values['description']) ?></textarea></label>
        <label>Clinic photo <span class="optional"><?= $editingClinic ? '(leave blank to keep current photo)' : '(optional, JPG/PNG/WEBP, 4 MB max)' ?></span>
          <?php if ($editingClinic && !empty($editingClinic['PhotoUrl'])): ?>
            <img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($editingClinic['PhotoUrl']) ?>" alt="" style="display:block;width:100%;max-width:280px;height:140px;object-fit:cover;border-radius:var(--radius-sm);margin-bottom:8px;">
          <?php endif; ?>
          <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
        </label>
      </div>
      <button type="submit" class="btn btn-primary btn-block"><?= $editingClinic ? 'Save changes' : 'Add clinic' ?></button>
      <?php if ($editingClinic): ?><a href="<?= HQ_BASE_URL ?>/admin/clinics.php" class="text-link" style="display:block;text-align:center;margin-top:12px;">Cancel edit</a><?php endif; ?>
    </form>
  </section>

  <section class="admin-section">
    <div class="portal-heading">
      <div><span class="section-kicker"><?= $showArchived ? 'Archived' : 'Active directory' ?></span><h2><?= $showArchived ? 'Archived clinics' : 'All clinics' ?></h2></div>
      <a href="<?= HQ_BASE_URL ?>/admin/clinics.php?<?= $showArchived ? '' : 'archived=1&' ?>q=<?= urlencode($search) ?>" class="text-link"><?= $showArchived ? 'View active directory' : 'View archived clinics' ?> <span>&rarr;</span></a>
    </div>
    <form method="get" class="admin-search">
      <?php if ($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by clinic name or address&hellip;">
      <button type="submit" class="btn btn-outline btn-sm">Search</button>
      <?php if ($search !== ''): ?><a href="<?= HQ_BASE_URL ?>/admin/clinics.php<?= $showArchived ? '?archived=1' : '' ?>" class="text-link">Clear</a><?php endif; ?>
    </form>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Photo</th><th>Clinic</th><th>Location</th><th>Contact</th><th>Fee</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($clinics as $clinic): ?>
          <tr>
            <td><?php if (!empty($clinic['PhotoUrl'])): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="" style="width:52px;height:40px;object-fit:cover;border-radius:6px;"><?php else: ?><span class="unassigned">None</span><?php endif; ?></td>
            <td><strong><?= htmlspecialchars($clinic['ClinicName']) ?></strong></td>
            <td><?= htmlspecialchars($clinic['Address']) ?></td>
            <td><?= htmlspecialchars($clinic['ContactNumber']) ?></td>
            <td>PHP <?= number_format((float) $clinic['BaseConsultationFee'], 2) ?></td>
            <td><span class="status-badge status-<?= strtolower(htmlspecialchars($clinic['Status'])) ?>"><?= htmlspecialchars($clinic['Status']) ?></span></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php if (!$showArchived): ?>
                  <a href="<?= HQ_BASE_URL ?>/admin/clinics.php?edit=<?= (int) $clinic['ClinicID'] ?>#clinic-form" class="btn btn-outline btn-sm">Edit</a>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_clinic_status"><input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
                    <?php if ($clinic['Status'] === 'Active'): ?><button class="btn btn-outline btn-sm" name="status" value="Inactive" onclick="return confirm('Set this clinic to inactive? Patients will no longer be able to request appointments.');">Set inactive</button>
                    <?php else: ?><button class="btn btn-primary btn-sm" name="status" value="Active">Activate</button><?php endif; ?>
                  </form>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="archive_clinic"><input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
                    <button class="btn btn-outline btn-sm" onclick="return confirm('Archive this clinic? It will be hidden from patients and the active directory. This can be undone.');">Archive</button>
                  </form>
                <?php else: ?>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="restore_clinic"><input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
                    <button class="btn btn-primary btn-sm">Restore</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$clinics): ?><tr><td colspan="7" class="admin-empty"><?= $search !== '' ? 'No clinics match "' . htmlspecialchars($search) . '".' : 'No clinics to show here.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
