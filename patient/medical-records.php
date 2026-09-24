<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'upload_record') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $file = $_FILES['record_file'] ?? null;
        $allowedTypes = [
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        ];

        if ($title === '') {
            $errors[] = 'Please give this record a title.';
        } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) {
            $errors[] = 'Please choose a file to upload.';
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $errors[] = 'File is too large (10 MB limit).';
        } else {
            $mime = mime_content_type($file['tmp_name']);
            if (!isset($allowedTypes[$mime])) {
                $errors[] = 'Please upload a PDF, JPG, or PNG file.';
            } else {
                try {
                    $storageDir = __DIR__ . '/../storage/medical-records';
                    if (!is_dir($storageDir)) {
                        mkdir($storageDir, 0750, true);
                    }
                    $filename = 'record_' . $user['UserID'] . '_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mime];

                    if (!move_uploaded_file($file['tmp_name'], $storageDir . '/' . $filename)) {
                        $errors[] = 'We could not save this file. Please try again.';
                    } else {
                        $pdo->prepare('INSERT INTO MedicalRecords (PatientID, Title, Description, FilePath, FileType) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$user['UserID'], $title, $description ?: null, $filename, $allowedTypes[$mime]]);
                        $flash = 'Medical record uploaded.';
                    }
                } catch (PDOException $e) {
                    error_log('Medical record upload failed: ' . $e->getMessage());
                    $errors[] = 'We could not save this record. Please try again.';
                }
            }
        }
    }
}

$consultationHistory = [];
$prescriptions = [];
$records = [];
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, c.ClinicName, u.FirstName, u.LastName
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users u ON u.UserID = a.PhysicianID
             WHERE a.PatientID = ? AND a.Status = 'Completed'
             ORDER BY a.AppointmentDate DESC"
        );
        $stmt->execute([$user['UserID']]);
        $consultationHistory = $stmt->fetchAll();

        $rxStmt = $pdo->prepare(
            "SELECT v.PrescriptionText, v.UpdatedAt, a.AppointmentID, c.ClinicName, phy.FirstName, phy.LastName
             FROM ConsultationVersions v
             JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
             JOIN Appointments a ON a.AppointmentID = cons.AppointmentID
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.PatientID = ? AND v.PrescriptionText IS NOT NULL AND v.Status <> 'Draft'
             ORDER BY v.UpdatedAt DESC"
        );
        $rxStmt->execute([$user['UserID']]);
        $prescriptions = $rxStmt->fetchAll();

        $recStmt = $pdo->prepare('SELECT RecordID, Title, Description, FileType, UploadedAt FROM MedicalRecords WHERE PatientID = ? ORDER BY UploadedAt DESC');
        $recStmt->execute([$user['UserID']]);
        $records = $recStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Medical records load failed: ' . $e->getMessage());
        $dataError = 'Some medical record information is temporarily unavailable.';
    }
}

$pageTitle = 'Medical Records — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow">Health records</span><h1>Medical Records</h1><p>Your consultation history, prescriptions, and any personal records you've added.</p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">History</span><h2>Consultation history</h2></div></div>
    <?php if ($consultationHistory): ?>
      <div class="compact-list">
        <?php foreach ($consultationHistory as $item): ?>
          <article>
            <div><strong><?= htmlspecialchars($item['ClinicName']) ?></strong><span>Dr. <?= htmlspecialchars($item['FirstName'] . ' ' . $item['LastName']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($item['AppointmentDate']))) ?></span></div>
            <button type="button" class="text-link" data-view-record="<?= (int) $item['AppointmentID'] ?>">View record <span>&rarr;</span></button>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No completed consultations yet.</p>
    <?php endif; ?>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Prescriptions</span><h2>Your prescriptions</h2></div></div>
    <?php if ($prescriptions): ?>
      <div class="compact-list">
        <?php foreach ($prescriptions as $rx): ?>
          <article style="align-items:flex-start;flex-direction:column;gap:6px;">
            <strong><?= htmlspecialchars($rx['ClinicName']) ?> &middot; Dr. <?= htmlspecialchars($rx['FirstName'] . ' ' . $rx['LastName']) ?></strong>
            <p style="margin:0;color:var(--slate-600);font-size:13.5px;white-space:pre-line;"><?= htmlspecialchars($rx['PrescriptionText']) ?></p>
            <span style="color:var(--slate-400);font-size:12px;"><?= htmlspecialchars(date('M j, Y', strtotime($rx['UpdatedAt']))) ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No prescriptions on file yet.</p>
    <?php endif; ?>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Personal records</span><h2>Upload / Add Medical Record</h2></div></div>
    <form class="registration-form" method="post" enctype="multipart/form-data" style="max-width:560px;margin-bottom:24px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="upload_record">
      <div class="form-stack">
        <label>Title<input type="text" name="title" required></label>
        <label>Description <span class="optional">(optional)</span><textarea name="description" rows="3"></textarea></label>
        <label>File <span class="optional">(PDF, JPG, or PNG — 10 MB max)</span><input type="file" name="record_file" accept=".pdf,.jpg,.jpeg,.png" required></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Upload Record</button>
    </form>

    <?php if ($records): ?>
      <div class="compact-list">
        <?php foreach ($records as $record): ?>
          <article>
            <div><strong><?= htmlspecialchars($record['Title']) ?></strong><span><?= htmlspecialchars(strtoupper($record['FileType'])) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($record['UploadedAt']))) ?><?= $record['Description'] ? ' — ' . htmlspecialchars($record['Description']) : '' ?></span></div>
            <a href="<?= HQ_BASE_URL ?>/patient/record-stream.php?record_id=<?= (int) $record['RecordID'] ?>" class="text-link" target="_blank" rel="noopener">View <span>&rarr;</span></a>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">You haven't uploaded any personal records yet.</p>
    <?php endif; ?>
  </section>
</div></main>

<div class="modal-overlay" id="viewRecordModal">
  <div class="modal-box modal-box-wide" style="max-height:85vh;">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div id="viewRecordContent"><p class="admin-empty">Loading…</p></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var viewRecordModal = document.getElementById('viewRecordModal');
  var viewRecordContent = document.getElementById('viewRecordContent');

  function loadRecord(appointmentId) {
    if (!viewRecordModal || !viewRecordContent) return;
    viewRecordContent.innerHTML = '<p class="admin-empty">Loading…</p>';
    window.hqOpenModal(viewRecordModal);
    fetch('<?= HQ_BASE_URL ?>/patient/consultation.php?appointment_id=' + encodeURIComponent(appointmentId), { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) { viewRecordContent.innerHTML = html; })
      .catch(function () {
        viewRecordContent.innerHTML = '<p class="form-message error" role="alert">We could not load this record. Please try again.</p>';
      });
  }

  document.querySelectorAll('[data-view-record]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      loadRecord(trigger.getAttribute('data-view-record'));
    });
  });

  if (viewRecordContent) {
    viewRecordContent.addEventListener('submit', function (e) {
      if (!e.target.matches('.consultation-feedback-form')) return;
      e.preventDefault();
      var form = e.target;
      var formData = new FormData(form);
      fetch(form.action, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.text(); })
        .then(function (html) { viewRecordContent.innerHTML = html; })
        .catch(function () {
          var err = document.createElement('p');
          err.className = 'form-message error';
          err.textContent = 'We could not save your feedback. Please try again.';
          form.prepend(err);
        });
    });
  }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
