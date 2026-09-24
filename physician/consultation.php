<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/ai-transcription.php';
require_once __DIR__ . '/../includes/notifications.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

if (!$appointmentId || !$pdo) {
    header('Location: ' . HQ_BASE_URL . '/physician/dashboard.php');
    exit;
}

// Scoped to this physician's own UserID -- one physician can never open or
// write notes for another physician's appointment.
$stmt = $pdo->prepare(
    "SELECT a.AppointmentID, a.ClinicID, a.PatientID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.Status,
            pat.FirstName, pat.LastName, c.ClinicName
     FROM Appointments a
     JOIN Users pat ON pat.UserID = a.PatientID
     JOIN Clinic c ON c.ClinicID = a.ClinicID
     WHERE a.AppointmentID = ? AND a.PhysicianID = ?"
);
$stmt->execute([$appointmentId, $user['UserID']]);
$appointment = $stmt->fetch();

if (!$appointment) {
    header('Location: ' . HQ_BASE_URL . '/physician/dashboard.php');
    exit;
}

if ($appointment['Status'] === 'Cancelled') {
    header('Location: ' . HQ_BASE_URL . '/physician/dashboard.php');
    exit;
}

/** Returns this appointment's ConsultationID, creating the row if it doesn't exist yet. */
function getOrCreateConsultationId(PDO $pdo, array $appointment, int $appointmentId, int $doctorId): int
{
    $stmt = $pdo->prepare('SELECT ConsultationID FROM Consultations WHERE AppointmentID = ?');
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if ($row) {
        return (int) $row['ConsultationID'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO Consultations (ClinicID, AppointmentID, PatientID, DoctorID) VALUES (?, ?, ?, ?)'
    );
    $insert->execute([$appointment['ClinicID'], $appointmentId, $appointment['PatientID'], $doctorId]);
    return (int) $pdo->lastInsertId();
}

$formType = $_POST['form_type'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'save_notes' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($formType === 'save_draft' || $formType === 'finalize_notes') {
        $clinicalNotes = trim((string) ($_POST['clinical_notes'] ?? ''));
        $prescriptionText = trim((string) ($_POST['prescription_text'] ?? ''));
        $versionStatus = $formType === 'finalize_notes' ? 'Finalized' : 'Draft';

        if ($versionStatus === 'Finalized' && $clinicalNotes === '') {
            $errors[] = 'Clinical notes are required to finalize this consultation.';
        }

        if (!$errors) {
            try {
                $consultationId = getOrCreateConsultationId($pdo, $appointment, $appointmentId, $user['UserID']);

                $revStmt = $pdo->prepare('SELECT COALESCE(MAX(RevisionNumber), 0) + 1 AS next_rev FROM ConsultationVersions WHERE ConsultationID = ?');
                $revStmt->execute([$consultationId]);
                $nextRevision = (int) $revStmt->fetchColumn();

                $insertVersion = $pdo->prepare(
                    'INSERT INTO ConsultationVersions (ConsultationID, EditedByPhysicianID, RevisionNumber, ClinicalNotes, PrescriptionText, Status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $insertVersion->execute([$consultationId, $user['UserID'], $nextRevision, $clinicalNotes, $prescriptionText ?: null, $versionStatus]);

                logActivity($pdo, $user['UserID'], $appointment['ClinicID'], $versionStatus === 'Finalized' ? 'Finalized consultation notes' : 'Saved draft consultation notes', "Appointment #{$appointmentId}, revision {$nextRevision}");
                if ($versionStatus === 'Finalized') {
                    notifyClinic(
                        $pdo,
                        $appointment['ClinicID'],
                        'Dr. ' . $user['FirstName'] . ' ' . $user['LastName'] . ' finished with ' . $appointment['FirstName'] . ' ' . $appointment['LastName'] . ' — ready to call the next patient.',
                        $appointmentId
                    );
                }
                $flash = $versionStatus === 'Finalized' ? 'Consultation notes finalized.' : 'Draft saved.';
            } catch (PDOException $e) {
                error_log('Consultation save failed: ' . $e->getMessage());
                $errors[] = 'We could not save these consultation notes. Please try again.';
            }
        }
    } elseif ($formType === 'accept_ai_summary') {
        $summaryText = trim((string) ($_POST['summary_text'] ?? ''));
        if ($summaryText === '') {
            $errors[] = 'The AI summary cannot be empty.';
        } else {
            try {
                $consultationId = getOrCreateConsultationId($pdo, $appointment, $appointmentId, $user['UserID']);
                $pdo->prepare(
                    "INSERT INTO AITranscriptVersions (ConsultationID, TranscriptType, TranscriptText) VALUES (?, 'AI_Summary', ?)"
                )->execute([$consultationId, $summaryText]);
                logActivity($pdo, $user['UserID'], $appointment['ClinicID'], 'Accepted AI summary', "Appointment #{$appointmentId}");
                $flash = 'AI summary saved.';
            } catch (PDOException $e) {
                error_log('AI summary save failed: ' . $e->getMessage());
                $errors[] = 'We could not save this summary. Please try again.';
            }
        }
    } elseif ($formType === 'upload_audio') {
        $file = $_FILES['audio_file'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) {
            $errors[] = 'No recording was received. Please try recording again.';
        } elseif ($file['size'] > 25 * 1024 * 1024) {
            $errors[] = 'Recording is too large (25 MB limit).';
        } else {
            try {
                $consultationId = getOrCreateConsultationId($pdo, $appointment, $appointmentId, $user['UserID']);
                $storageDir = __DIR__ . '/../storage/audio';
                if (!is_dir($storageDir)) {
                    mkdir($storageDir, 0750, true);
                }
                $filename = 'consultation_' . $consultationId . '_' . bin2hex(random_bytes(8)) . '.webm';

                // Remove any previous recording for this consultation before saving the new one.
                $oldPathStmt = $pdo->prepare('SELECT AudioFilePath FROM Consultations WHERE ConsultationID = ?');
                $oldPathStmt->execute([$consultationId]);
                $oldPath = $oldPathStmt->fetchColumn();
                if ($oldPath && is_file($storageDir . '/' . basename($oldPath))) {
                    unlink($storageDir . '/' . basename($oldPath));
                }

                if (!move_uploaded_file($file['tmp_name'], $storageDir . '/' . $filename)) {
                    $errors[] = 'We could not save this recording. Please try again.';
                } else {
                    $duration = filter_input(INPUT_POST, 'duration_seconds', FILTER_VALIDATE_INT) ?: null;
                    $pdo->prepare('UPDATE Consultations SET AudioFilePath = ?, AudioDurationSeconds = ? WHERE ConsultationID = ?')
                        ->execute([$filename, $duration, $consultationId]);
                    logActivity($pdo, $user['UserID'], $appointment['ClinicID'], 'Uploaded consultation recording', "Appointment #{$appointmentId}");
                    $flash = 'Recording saved. You can now generate a transcript.';
                }
            } catch (PDOException $e) {
                error_log('Audio upload failed: ' . $e->getMessage());
                $errors[] = 'We could not save this recording. Please try again.';
            }
        }
    } elseif ($formType === 'generate_transcript') {
        try {
            $consultStmt = $pdo->prepare('SELECT ConsultationID, AudioFilePath FROM Consultations WHERE AppointmentID = ?');
            $consultStmt->execute([$appointmentId]);
            $consultation = $consultStmt->fetch();

            if (!$consultation || !$consultation['AudioFilePath']) {
                $errors[] = 'Upload a recording before generating a transcript.';
            } else {
                $audioPath = __DIR__ . '/../storage/audio/' . basename($consultation['AudioFilePath']);
                $transcriptText = transcribeAudio($audioPath);
                $pdo->prepare(
                    "INSERT INTO AITranscriptVersions (ConsultationID, TranscriptType, TranscriptText) VALUES (?, 'Original_STT', ?)"
                )->execute([(int) $consultation['ConsultationID'], $transcriptText]);
                logActivity($pdo, $user['UserID'], $appointment['ClinicID'], 'Generated AI transcript (stub)', "Appointment #{$appointmentId}");
                $flash = 'Transcript generated (placeholder -- no speech-to-text service is connected yet).';
            }
        } catch (PDOException $e) {
            error_log('Transcript generation failed: ' . $e->getMessage());
            $errors[] = 'We could not generate a transcript right now.';
        }
    } elseif ($formType === 'save_corrected_transcript') {
        $correctedText = trim((string) ($_POST['corrected_transcript'] ?? ''));
        if ($correctedText === '') {
            $errors[] = 'Corrected transcript cannot be empty.';
        } else {
            try {
                $consultStmt = $pdo->prepare('SELECT ConsultationID FROM Consultations WHERE AppointmentID = ?');
                $consultStmt->execute([$appointmentId]);
                $consultationId = (int) $consultStmt->fetchColumn();
                $pdo->prepare(
                    "INSERT INTO AITranscriptVersions (ConsultationID, TranscriptType, TranscriptText) VALUES (?, 'Doctor_Corrected', ?)"
                )->execute([$consultationId, $correctedText]);
                logActivity($pdo, $user['UserID'], $appointment['ClinicID'], 'Saved corrected transcript', "Appointment #{$appointmentId}");
                $flash = 'Corrected transcript saved.';
            } catch (PDOException $e) {
                error_log('Corrected transcript save failed: ' . $e->getMessage());
                $errors[] = 'We could not save your correction.';
            }
        }
    }
}

$latestNotes = '';
$latestPrescription = '';
$history = [];
$hasFinalizedNotes = false;
$audioFilePath = null;
$latestTranscript = null;
$latestCorrected = null;
$latestSummary = null;

try {
    $historyStmt = $pdo->prepare(
        "SELECT v.RevisionNumber, v.ClinicalNotes, v.PrescriptionText, v.UpdatedAt, v.Status, u.FirstName, u.LastName
         FROM ConsultationVersions v
         JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
         JOIN Users u ON u.UserID = v.EditedByPhysicianID
         WHERE cons.AppointmentID = ?
         ORDER BY v.RevisionNumber DESC"
    );
    $historyStmt->execute([$appointmentId]);
    $history = $historyStmt->fetchAll();
    if ($history) {
        $latestNotes = $history[0]['ClinicalNotes'];
        $latestPrescription = $history[0]['PrescriptionText'] ?? '';
    }
    $hasFinalizedNotes = false;
    foreach ($history as $version) {
        if ($version['Status'] === 'Finalized') {
            $hasFinalizedNotes = true;
            break;
        }
    }

    $consultStmt = $pdo->prepare('SELECT ConsultationID, AudioFilePath FROM Consultations WHERE AppointmentID = ?');
    $consultStmt->execute([$appointmentId]);
    $consultation = $consultStmt->fetch();

    if ($consultation) {
        $audioFilePath = $consultation['AudioFilePath'];
        $consultationId = (int) $consultation['ConsultationID'];

        $tStmt = $pdo->prepare(
            'SELECT TranscriptText, GeneratedAt FROM AITranscriptVersions WHERE ConsultationID = ? AND TranscriptType = ? ORDER BY GeneratedAt DESC LIMIT 1'
        );

        $tStmt->execute([$consultationId, 'Original_STT']);
        $latestTranscript = $tStmt->fetch() ?: null;

        $tStmt->execute([$consultationId, 'Doctor_Corrected']);
        $latestCorrected = $tStmt->fetch() ?: null;

        $tStmt->execute([$consultationId, 'AI_Summary']);
        $latestSummary = $tStmt->fetch() ?: null;
    }
} catch (PDOException $e) {
    error_log('Consultation data load failed: ' . $e->getMessage());
    $errors[] = 'Some consultation data could not be loaded, but you can still save new entries.';
}

// The AI Summary card needs something to summarize even before any audio has
// been recorded, so it falls back through corrected transcript -> raw
// transcript -> the patient's stated concern from booking.
$summaryIsSaved = false;
if ($latestSummary) {
    $summaryText = $latestSummary['TranscriptText'];
    $summaryIsSaved = true;
} else {
    $summaryInputText = $latestCorrected['TranscriptText']
        ?? $latestTranscript['TranscriptText']
        ?? $appointment['Concern']
        ?? '';
    $summaryText = $summaryInputText !== '' ? summarizeTranscript($summaryInputText) : '';
}

$pageTitle = 'Consultation Notes — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <a href="<?= HQ_BASE_URL ?>/physician/dashboard.php" class="back-link">&larr; Back to dashboard</a>

  <section class="portal-hero" style="margin-top:18px;">
    <div>
      <span class="eyebrow">Active Consultation</span>
      <h1><?= htmlspecialchars($appointment['FirstName'] . ' ' . $appointment['LastName']) ?></h1>
      <p><?= htmlspecialchars($appointment['ClinicName']) ?> &middot; <?= htmlspecialchars(date('l, F j, Y', strtotime($appointment['AppointmentDate']))) ?> at <?= htmlspecialchars(date('g:i A', strtotime($appointment['AppointmentTime']))) ?><?= $appointment['Concern'] ? ' — ' . htmlspecialchars($appointment['Concern']) : '' ?></p>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:10px;">
      <span class="status-badge status-<?= strtolower(htmlspecialchars($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span>
      <div style="display:flex;align-items:center;gap:12px;">
        <span id="recordingIndicator" class="recording-indicator" style="display:none;"><span class="rec-dot"></span> Recording<span id="recordTimer"></span></span>
        <button type="button" id="recordToggleBtn" class="btn btn-primary btn-sm">&#9679; Start Recording</button>
      </div>
      <span id="recorderUnsupported" class="form-message error" style="display:none;margin:0;font-size:12px;">Your browser doesn't support audio recording (needs microphone access over localhost/HTTPS).</span>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">AI Summary</span><h2>AI-generated summary of stated concern</h2></div></div>
    <div class="dev-note" style="margin-bottom:14px;">
      <strong>Stubbed:</strong> no real AI service is connected yet — this text is placeholder output from <code>includes/ai-transcription.php</code>, generated from <?= $summaryIsSaved ? 'a saved transcript' : (($appointment['Concern'] ?? '') !== '' ? "the patient's stated concern" : 'the visit recording once available') ?>.
    </div>
    <?php if ($summaryText !== ''): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="form_type" value="accept_ai_summary">
        <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
        <div class="form-stack">
          <textarea name="summary_text" id="aiSummaryText" rows="4" readonly style="background:var(--slate-50);"><?= htmlspecialchars($summaryText) ?></textarea>
        </div>
        <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
          <button type="submit" class="btn btn-primary">Accept Summary</button>
          <button type="button" id="editSummaryBtn" class="btn btn-outline">Edit Summary</button>
        </div>
        <?php if ($summaryIsSaved): ?><span style="display:block;margin-top:8px;color:var(--slate-400);font-size:12px;">Saved <?= htmlspecialchars(date('M j, Y g:i A', strtotime($latestSummary['GeneratedAt']))) ?></span><?php endif; ?>
      </form>
    <?php else: ?>
      <p class="admin-empty">No stated concern or recording is available yet to summarize.</p>
    <?php endif; ?>
  </section>

  <form class="registration-form" method="post" style="max-width:720px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
    <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">

    <section class="portal-section">
      <div class="portal-heading"><div><span class="section-kicker">Notes</span><h2>Clinical Notes</h2></div></div>
      <div class="form-stack">
        <textarea name="clinical_notes" rows="6" placeholder="Enter or review notes..."><?= htmlspecialchars($latestNotes) ?></textarea>
      </div>
    </section>

    <section class="portal-section">
      <div class="portal-heading"><div><span class="section-kicker">Rx</span><h2>Prescription</h2></div></div>
      <div class="form-stack">
        <textarea name="prescription_text" rows="4" placeholder="Enter prescription details..."><?= htmlspecialchars($latestPrescription) ?></textarea>
      </div>
    </section>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;">
      <button type="submit" name="form_type" value="save_draft" class="btn btn-outline" style="flex:1;min-width:180px;">Save as Draft</button>
      <button type="submit" name="form_type" value="finalize_notes" class="btn btn-primary" style="flex:1;min-width:180px;">Finalize Consultation</button>
    </div>
  </form>

  <div class="dev-note" style="max-width:720px;margin-top:16px;">
    <?php if ($appointment['Status'] === 'Completed'): ?>
      <strong>Consultation complete.</strong> Front-desk staff confirmed this visit as complete.
    <?php elseif ($hasFinalizedNotes): ?>
      <strong>Awaiting staff confirmation.</strong> Your notes are finalized — front-desk staff will confirm this consultation as complete.
    <?php else: ?>
      <strong>Not yet finalized.</strong> Finalize your consultation notes above; front-desk staff can then confirm this consultation as complete.
    <?php endif; ?>
  </div>

  <?php if ($history): ?>
    <section class="portal-section">
      <div class="portal-heading"><div><span class="section-kicker">History</span><h2>Previous revisions</h2></div></div>
      <div class="compact-list">
        <?php foreach ($history as $version): ?>
          <article style="align-items:flex-start;flex-direction:column;gap:6px;">
            <div style="display:flex;justify-content:space-between;width:100%;">
              <strong>Revision <?= (int) $version['RevisionNumber'] ?> <span class="status-badge status-<?= $version['Status'] === 'Draft' ? 'pending' : 'completed' ?>" style="margin-left:6px;font-size:11px;"><?= htmlspecialchars($version['Status']) ?></span></strong>
              <span style="color:var(--slate-400);font-size:12px;">Dr. <?= htmlspecialchars($version['FirstName'] . ' ' . $version['LastName']) ?> &middot; <?= htmlspecialchars(date('M j, Y g:i A', strtotime($version['UpdatedAt']))) ?></span>
            </div>
            <p style="margin:0;color:var(--slate-600);font-size:13.5px;white-space:pre-line;"><?= htmlspecialchars($version['ClinicalNotes']) ?></p>
            <?php if ($version['PrescriptionText']): ?><p style="margin:4px 0 0;color:var(--slate-500);font-size:13px;white-space:pre-line;"><strong>Rx:</strong> <?= htmlspecialchars($version['PrescriptionText']) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="portal-section" id="ai-transcriber">
    <div class="portal-heading"><div><span class="section-kicker">Voice recorder</span><h2>Consultation recording &amp; AI transcriber</h2></div></div>
    <div class="dev-note" style="margin-bottom:16px;">
      <strong>AI transcription is stubbed</strong> — audio recording and storage below are fully working, but the transcript and summary buttons currently return placeholder text until a real speech-to-text/summarization service is connected in <code>includes/ai-transcription.php</code>. Use the Start/Stop Recording button at the top of the page.
    </div>

    <?php if ($audioFilePath): ?>
      <audio controls style="width:100%;margin-bottom:16px;" src="<?= HQ_BASE_URL ?>/physician/audio-stream.php?appointment_id=<?= (int) $appointmentId ?>"></audio>
    <?php endif; ?>

    <form id="uploadAudioForm" method="post" enctype="multipart/form-data" style="display:none;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="upload_audio">
      <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
      <input type="hidden" name="duration_seconds" id="durationSecondsInput" value="">
      <input type="file" name="audio_file" id="audioFileInput" hidden>
      <audio id="previewPlayer" controls style="width:100%;margin-bottom:10px;"></audio>
      <button type="submit" class="btn btn-primary btn-block">Save this recording</button>
    </form>
  </section>

  <?php if ($audioFilePath): ?>
    <section class="portal-section">
      <div class="portal-heading"><div><span class="section-kicker">Transcript</span><h2>Speech-to-text</h2></div></div>
      <?php if (!$latestTranscript): ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
          <input type="hidden" name="form_type" value="generate_transcript">
          <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
          <button type="submit" class="btn btn-outline">Generate transcript</button>
        </form>
      <?php else: ?>
        <div class="compact-list" style="margin-bottom:16px;">
          <article style="align-items:flex-start;flex-direction:column;gap:6px;">
            <strong>Original speech-to-text output</strong>
            <p style="margin:0;color:var(--slate-600);font-size:13.5px;white-space:pre-line;"><?= htmlspecialchars($latestTranscript['TranscriptText']) ?></p>
            <span style="color:var(--slate-400);font-size:12px;"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($latestTranscript['GeneratedAt']))) ?></span>
          </article>
        </div>

        <form method="post" style="max-width:720px;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
          <input type="hidden" name="form_type" value="save_corrected_transcript">
          <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
          <div class="form-stack">
            <label>Doctor-corrected transcript <span class="optional">(fix any mistakes before summarizing)</span>
              <textarea name="corrected_transcript" rows="5"><?= htmlspecialchars($latestCorrected['TranscriptText'] ?? $latestTranscript['TranscriptText']) ?></textarea>
            </label>
          </div>
          <button type="submit" class="btn btn-outline btn-block">Save correction</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div></main>

<script>
(function () {
  var toggleBtn = document.getElementById('recordToggleBtn');
  var indicatorEl = document.getElementById('recordingIndicator');
  var timerEl = document.getElementById('recordTimer');
  var unsupportedEl = document.getElementById('recorderUnsupported');
  var uploadForm = document.getElementById('uploadAudioForm');
  var fileInput = document.getElementById('audioFileInput');
  var previewPlayer = document.getElementById('previewPlayer');
  var durationInput = document.getElementById('durationSecondsInput');

  if (!navigator.mediaDevices || !window.MediaRecorder) {
    toggleBtn.disabled = true;
    unsupportedEl.style.display = 'inline';
    return;
  }

  var mediaRecorder, chunks = [], startTime, timerInterval, isRecording = false;

  toggleBtn.addEventListener('click', function () {
    if (isRecording) {
      if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
      isRecording = false;
      toggleBtn.textContent = '● Start Recording';
      toggleBtn.classList.remove('btn-danger');
      toggleBtn.classList.add('btn-primary');
      indicatorEl.style.display = 'none';
      return;
    }

    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      chunks = [];
      mediaRecorder = new MediaRecorder(stream);
      mediaRecorder.ondataavailable = function (e) { if (e.data.size > 0) chunks.push(e.data); };
      mediaRecorder.onstop = function () {
        stream.getTracks().forEach(function (t) { t.stop(); });
        clearInterval(timerInterval);

        var blob = new Blob(chunks, { type: 'audio/webm' });
        var file = new File([blob], 'consultation.webm', { type: 'audio/webm' });
        var dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;

        var url = URL.createObjectURL(blob);
        previewPlayer.src = url;
        durationInput.value = Math.round((Date.now() - startTime) / 1000);
        uploadForm.style.display = 'block';
        uploadForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
      };
      mediaRecorder.start();
      startTime = Date.now();
      isRecording = true;
      toggleBtn.textContent = '■ Stop Recording';
      toggleBtn.classList.remove('btn-primary');
      toggleBtn.classList.add('btn-danger');
      indicatorEl.style.display = 'inline-flex';
      timerInterval = setInterval(function () {
        var secs = Math.round((Date.now() - startTime) / 1000);
        timerEl.textContent = '… ' + Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');
      }, 250);
    }).catch(function () {
      unsupportedEl.textContent = 'Microphone access was denied or unavailable.';
      unsupportedEl.style.display = 'inline';
    });
  });
})();

(function () {
  var editBtn = document.getElementById('editSummaryBtn');
  var summaryText = document.getElementById('aiSummaryText');
  if (!editBtn || !summaryText) return;
  editBtn.addEventListener('click', function () {
    summaryText.removeAttribute('readonly');
    summaryText.focus();
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
