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
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

if (!$appointmentId || !$pdo) {
    header('Location: ' . HQ_BASE_URL . '/physician/consultations.php');
    exit;
}

// Scoped to this physician's own UserID -- one physician can never open or
// write notes for another physician's appointment.
$stmt = $pdo->prepare(
    "SELECT a.AppointmentID, a.ClinicID, a.PatientID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.Status, a.BookingFeePaid,
            pat.FirstName, pat.LastName, pat.IDNumber, pat.Allergies, pat.BloodType, c.ClinicName
     FROM Appointments a
     JOIN Users pat ON pat.UserID = a.PatientID
     JOIN Clinic c ON c.ClinicID = a.ClinicID
     WHERE a.AppointmentID = ? AND a.PhysicianID = ?"
);
$stmt->execute([$appointmentId, $user['UserID']]);
$appointment = $stmt->fetch();

if (!$appointment || $appointment['Status'] === 'Cancelled') {
    header('Location: ' . HQ_BASE_URL . '/physician/consultations.php');
    exit;
}
$isCompleted = $appointment['Status'] === 'Completed';

/** Returns this appointment's ConsultationID, creating the row if it doesn't exist yet. */
function getOrCreateConsultationId(PDO $pdo, array $appointment, int $appointmentId, int $doctorId): int
{
    $stmt = $pdo->prepare('SELECT ConsultationID FROM Consultations WHERE AppointmentID = ?');
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if ($row) return (int) $row['ConsultationID'];

    $pdo->prepare('INSERT INTO Consultations (ClinicID, AppointmentID, PatientID, DoctorID) VALUES (?, ?, ?, ?)')
        ->execute([$appointment['ClinicID'], $appointmentId, $appointment['PatientID'], $doctorId]);
    return (int) $pdo->lastInsertId();
}

/**
 * Reads the structured notes from the form. Plain-text ClinicalNotes and
 * PrescriptionText are also produced for the patient-facing record pages.
 */
function notesFromPost(): array
{
    $clean = static fn(string $key, int $max = 4000): string => mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
    $notes = [
        'subjective' => $clean('subjective'),
        'vitals'     => ['bp' => $clean('vital_bp', 20), 'temp' => $clean('vital_temp', 20), 'weight' => $clean('vital_weight', 20)],
        'assessment' => $clean('assessment'),
        'plan'       => $clean('plan'),
        'meds'       => [],
    ];
    $names = (array) ($_POST['med_name'] ?? []);
    foreach ($names as $i => $name) {
        $name = mb_substr(trim((string) $name), 0, 120);
        if ($name === '') continue;
        $notes['meds'][] = [
            'name'      => $name,
            'frequency' => mb_substr(trim((string) ($_POST['med_frequency'][$i] ?? '')), 0, 60),
            'duration'  => mb_substr(trim((string) ($_POST['med_duration'][$i] ?? '')), 0, 60),
        ];
    }
    return $notes;
}

function notesToText(array $notes): array
{
    $vitals = array_filter([
        $notes['vitals']['bp'] !== '' ? 'BP ' . $notes['vitals']['bp'] : '',
        $notes['vitals']['temp'] !== '' ? 'Temp ' . $notes['vitals']['temp'] : '',
        $notes['vitals']['weight'] !== '' ? 'Weight ' . $notes['vitals']['weight'] : '',
    ]);
    $sections = array_filter([
        $notes['subjective'] !== '' ? 'Subjective: ' . $notes['subjective'] : '',
        $vitals ? 'Objective: ' . implode(' · ', $vitals) : '',
        $notes['assessment'] !== '' ? 'Assessment: ' . $notes['assessment'] : '',
        $notes['plan'] !== '' ? 'Plan: ' . $notes['plan'] : '',
    ]);
    $rx = array_map(static fn($m) => implode(' — ', array_filter([$m['name'], $m['frequency'], $m['duration']])), $notes['meds']);
    return [implode("\n", $sections), implode("\n", $rx)];
}

/** Saves a draft (updating the current draft in place) or finalizes. Returns the save time. */
function saveNotes(PDO $pdo, array $appointment, int $appointmentId, int $doctorId, array $notes, bool $finalize): string
{
    [$clinicalNotes, $prescriptionText] = notesToText($notes);
    $consultationId = getOrCreateConsultationId($pdo, $appointment, $appointmentId, $doctorId);
    $json = json_encode($notes, JSON_UNESCAPED_UNICODE);

    $latest = $pdo->prepare('SELECT VersionID, Status FROM ConsultationVersions WHERE ConsultationID = ? ORDER BY RevisionNumber DESC LIMIT 1');
    $latest->execute([$consultationId]);
    $latestRow = $latest->fetch();

    if ($latestRow && $latestRow['Status'] === 'Draft') {
        // Autosaves update the working draft instead of piling up revisions.
        $pdo->prepare('UPDATE ConsultationVersions SET ClinicalNotes = ?, PrescriptionText = ?, NotesJson = ?, Status = ?, UpdatedAt = NOW(), EditedByPhysicianID = ? WHERE VersionID = ?')
            ->execute([$clinicalNotes, $prescriptionText ?: null, $json, $finalize ? 'Finalized' : 'Draft', $doctorId, $latestRow['VersionID']]);
    } else {
        $rev = $pdo->prepare('SELECT COALESCE(MAX(RevisionNumber), 0) + 1 FROM ConsultationVersions WHERE ConsultationID = ?');
        $rev->execute([$consultationId]);
        $pdo->prepare(
            'INSERT INTO ConsultationVersions (ConsultationID, EditedByPhysicianID, RevisionNumber, ClinicalNotes, PrescriptionText, NotesJson, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$consultationId, $doctorId, (int) $rev->fetchColumn(), $clinicalNotes, $prescriptionText ?: null, $json, $finalize ? 'Finalized' : 'Draft']);
    }
    return (string) $pdo->query('SELECT NOW()')->fetchColumn();
}

function jsonReply(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($isCompleted) {
        $errors[] = 'This consultation is completed and can no longer be edited.';
    } elseif ($formType === 'save_draft' || $formType === 'finalize_notes') {
        $notes = notesFromPost();
        $finalize = $formType === 'finalize_notes';
        [$clinicalText] = notesToText($notes);
        if ($finalize && ($notes['assessment'] === '' || $clinicalText === '')) {
            $errors[] = 'Add at least an assessment before finalizing this consultation.';
        } else {
            try {
                $savedAt = saveNotes($pdo, $appointment, $appointmentId, (int) $user['UserID'], $notes, $finalize);
                if ($finalize) {
                    logActivity($pdo, $user['UserID'], $appointment['ClinicID'], 'Finalized consultation notes', "Appointment #{$appointmentId}");
                    notifyClinic(
                        $pdo,
                        (int) $appointment['ClinicID'],
                        'Dr. ' . $user['LastName'] . ' finished with ' . $appointment['FirstName'] . ' ' . $appointment['LastName'] . ' — ready to call the next patient.',
                        $appointmentId
                    );
                    $_SESSION['consult_flash'] = 'Consultation finalized. The front desk will confirm it as complete.';
                    header('Location: ' . HQ_BASE_URL . '/physician/consultations.php');
                    exit;
                }
                if ($isAjax) jsonReply(['ok' => true, 'savedAt' => $savedAt]);
                $flash = 'Draft saved.';
            } catch (PDOException $e) {
                error_log('Consultation save failed: ' . $e->getMessage());
                $errors[] = 'We could not save these notes. Please try again.';
            }
        }
        if ($isAjax) jsonReply(['ok' => false, 'errors' => $errors], 422);
    } elseif ($formType === 'set_consent') {
        try {
            $consultationId = getOrCreateConsultationId($pdo, $appointment, $appointmentId, (int) $user['UserID']);
            $consent = ($_POST['consent'] ?? '') === '1' ? 1 : 0;
            $pdo->prepare('UPDATE Consultations SET RecordingConsent = ? WHERE ConsultationID = ?')->execute([$consent, $consultationId]);
            logActivity($pdo, $user['UserID'], $appointment['ClinicID'], $consent ? 'Recorded patient consent to recording' : 'Withdrew recording consent', "Appointment #{$appointmentId}");
            jsonReply(['ok' => true]);
        } catch (PDOException $e) {
            error_log('Consent save failed: ' . $e->getMessage());
            jsonReply(['ok' => false], 500);
        }
    } elseif ($formType === 'upload_audio') {
        $file = $_FILES['audio_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) {
            $errors[] = 'No recording was received. Please try recording again.';
        } elseif ($file['size'] > 25 * 1024 * 1024) {
            $errors[] = 'Recording is too large (25 MB limit).';
        } else {
            try {
                $consultationId = getOrCreateConsultationId($pdo, $appointment, $appointmentId, (int) $user['UserID']);
                $consentStmt = $pdo->prepare('SELECT RecordingConsent, AudioFilePath FROM Consultations WHERE ConsultationID = ?');
                $consentStmt->execute([$consultationId]);
                $consult = $consentStmt->fetch();
                if (!(int) $consult['RecordingConsent']) {
                    $errors[] = 'The patient has not consented to recording.';
                } else {
                    $storageDir = __DIR__ . '/../storage/audio';
                    if (!is_dir($storageDir)) mkdir($storageDir, 0750, true);
                    $filename = 'consultation_' . $consultationId . '_' . bin2hex(random_bytes(8)) . '.webm';
                    if ($consult['AudioFilePath'] && is_file($storageDir . '/' . basename($consult['AudioFilePath']))) {
                        unlink($storageDir . '/' . basename($consult['AudioFilePath']));
                    }
                    if (!move_uploaded_file($file['tmp_name'], $storageDir . '/' . $filename)) {
                        $errors[] = 'We could not save this recording. Please try again.';
                    } else {
                        $duration = filter_input(INPUT_POST, 'duration_seconds', FILTER_VALIDATE_INT) ?: null;
                        $pdo->prepare('UPDATE Consultations SET AudioFilePath = ?, AudioDurationSeconds = ? WHERE ConsultationID = ?')
                            ->execute([$filename, $duration, $consultationId]);
                        logActivity($pdo, $user['UserID'], $appointment['ClinicID'], 'Uploaded consultation recording', "Appointment #{$appointmentId}");
                        if ($isAjax) jsonReply(['ok' => true]);
                        $flash = 'Recording saved.';
                    }
                }
            } catch (PDOException $e) {
                error_log('Audio upload failed: ' . $e->getMessage());
                $errors[] = 'We could not save this recording. Please try again.';
            }
        }
        if ($isAjax) jsonReply(['ok' => false, 'errors' => $errors], 422);
    } elseif ($formType === 'generate_transcript') {
        try {
            $consultStmt = $pdo->prepare('SELECT ConsultationID, AudioFilePath FROM Consultations WHERE AppointmentID = ?');
            $consultStmt->execute([$appointmentId]);
            $consultation = $consultStmt->fetch();
            if (!$consultation || !$consultation['AudioFilePath']) {
                $errors[] = 'Record the consultation before generating a transcript.';
            } else {
                $transcriptText = transcribeAudio(__DIR__ . '/../storage/audio/' . basename($consultation['AudioFilePath']));
                $pdo->prepare("INSERT INTO AITranscriptVersions (ConsultationID, TranscriptType, TranscriptText) VALUES (?, 'Original_STT', ?)")
                    ->execute([(int) $consultation['ConsultationID'], $transcriptText]);
                logActivity($pdo, $user['UserID'], $appointment['ClinicID'], 'Generated AI transcript (stub)', "Appointment #{$appointmentId}");
                $flash = 'Transcript generated (placeholder — no speech-to-text service is connected yet).';
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
                $consultationId = getOrCreateConsultationId($pdo, $appointment, $appointmentId, (int) $user['UserID']);
                $pdo->prepare("INSERT INTO AITranscriptVersions (ConsultationID, TranscriptType, TranscriptText) VALUES (?, 'Doctor_Corrected', ?)")
                    ->execute([$consultationId, $correctedText]);
                logActivity($pdo, $user['UserID'], $appointment['ClinicID'], 'Saved corrected transcript', "Appointment #{$appointmentId}");
                $flash = 'Corrected transcript saved.';
            } catch (PDOException $e) {
                error_log('Corrected transcript save failed: ' . $e->getMessage());
                $errors[] = 'We could not save your correction.';
            }
        }
    } elseif ($formType === 'regenerate_summary') {
        try {
            $consultationId = getOrCreateConsultationId($pdo, $appointment, $appointmentId, (int) $user['UserID']);
            $src = $pdo->prepare("SELECT TranscriptText FROM AITranscriptVersions WHERE ConsultationID = ? AND TranscriptType IN ('Doctor_Corrected', 'Original_STT') ORDER BY TranscriptType = 'Doctor_Corrected' DESC, GeneratedAt DESC LIMIT 1");
            $src->execute([$consultationId]);
            $input = (string) ($src->fetchColumn() ?: ($appointment['Concern'] ?? ''));
            if ($input === '') {
                $errors[] = 'There is nothing to summarize yet — record the visit or add a stated concern.';
            } else {
                $pdo->prepare("INSERT INTO AITranscriptVersions (ConsultationID, TranscriptType, TranscriptText) VALUES (?, 'AI_Summary', ?)")
                    ->execute([$consultationId, summarizeTranscript($input)]);
                logActivity($pdo, $user['UserID'], $appointment['ClinicID'], 'Regenerated AI summary (stub)', "Appointment #{$appointmentId}");
                $flash = 'AI summary regenerated.';
            }
        } catch (PDOException $e) {
            error_log('Summary regenerate failed: ' . $e->getMessage());
            $errors[] = 'We could not regenerate the summary.';
        }
    }
}
if (!empty($_SESSION['consult_flash'])) {
    $flash = $_SESSION['consult_flash'];
    unset($_SESSION['consult_flash']);
}

// ---- Load everything for the workspace ----
$history = [];
$notes = ['subjective' => '', 'vitals' => ['bp' => '', 'temp' => '', 'weight' => ''], 'assessment' => '', 'plan' => '', 'meds' => []];
$lastSavedAt = null;
$hasFinalized = false;
$consultation = null;
$latestTranscript = $latestCorrected = $latestSummary = null;
$queueEntry = null;
$previousVisit = null;

try {
    $historyStmt = $pdo->prepare(
        "SELECT v.RevisionNumber, v.ClinicalNotes, v.PrescriptionText, v.NotesJson, v.UpdatedAt, v.Status, u.LastName
         FROM ConsultationVersions v
         JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
         JOIN Users u ON u.UserID = v.EditedByPhysicianID
         WHERE cons.AppointmentID = ? ORDER BY v.RevisionNumber DESC"
    );
    $historyStmt->execute([$appointmentId]);
    $history = $historyStmt->fetchAll();
    foreach ($history as $version) {
        if ($version['Status'] === 'Finalized') $hasFinalized = true;
    }
    if ($history) {
        $latest = $history[0];
        $lastSavedAt = $latest['UpdatedAt'];
        $decoded = $latest['NotesJson'] ? json_decode($latest['NotesJson'], true) : null;
        if (is_array($decoded)) {
            $notes = array_replace_recursive($notes, $decoded);
        } else {
            // Older free-text notes: put them in Subjective, medicines one per line.
            $notes['subjective'] = (string) $latest['ClinicalNotes'];
            foreach (array_filter(array_map('trim', preg_split('/\r?\n/', (string) $latest['PrescriptionText']))) as $line) {
                $notes['meds'][] = ['name' => $line, 'frequency' => '', 'duration' => ''];
            }
        }
    }

    $consultStmt = $pdo->prepare('SELECT ConsultationID, AudioFilePath, AudioDurationSeconds, RecordingConsent FROM Consultations WHERE AppointmentID = ?');
    $consultStmt->execute([$appointmentId]);
    $consultation = $consultStmt->fetch() ?: null;
    if ($consultation) {
        $tStmt = $pdo->prepare('SELECT TranscriptText, GeneratedAt FROM AITranscriptVersions WHERE ConsultationID = ? AND TranscriptType = ? ORDER BY GeneratedAt DESC, TranscriptVersionID DESC LIMIT 1');
        foreach (['Original_STT' => 'latestTranscript', 'Doctor_Corrected' => 'latestCorrected', 'AI_Summary' => 'latestSummary'] as $type => $var) {
            $tStmt->execute([(int) $consultation['ConsultationID'], $type]);
            $$var = $tStmt->fetch() ?: null;
        }
    }

    $queueStmt = $pdo->prepare('SELECT QueueNumber, Status FROM Queue WHERE AppointmentID = ? ORDER BY CreatedAt DESC LIMIT 1');
    $queueStmt->execute([$appointmentId]);
    $queueEntry = $queueStmt->fetch() ?: null;

    $prevStmt = $pdo->prepare(
        "SELECT a.AppointmentDate, phy.LastName AS PhyLastName,
                (SELECT v.NotesJson FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID = v.ConsultationID
                 WHERE c.AppointmentID = a.AppointmentID AND v.Status = 'Finalized' ORDER BY v.RevisionNumber DESC LIMIT 1) AS NotesJson,
                (SELECT v.ClinicalNotes FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID = v.ConsultationID
                 WHERE c.AppointmentID = a.AppointmentID AND v.Status = 'Finalized' ORDER BY v.RevisionNumber DESC LIMIT 1) AS ClinicalNotes
         FROM Appointments a LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
         WHERE a.PatientID = ? AND a.Status = 'Completed' AND a.AppointmentID <> ?
         ORDER BY a.AppointmentDate DESC LIMIT 1"
    );
    $prevStmt->execute([$appointment['PatientID'], $appointmentId]);
    $previousVisit = $prevStmt->fetch() ?: null;
} catch (PDOException $e) {
    error_log('Consultation data load failed: ' . $e->getMessage());
    $errors[] = 'Some consultation data could not be loaded, but you can still save notes.';
}

// AI summary: the saved one, or a fresh stub from transcript/concern (not saved until regenerated).
$summaryText = $latestSummary['TranscriptText'] ?? '';
if ($summaryText === '') {
    $summaryInput = $latestCorrected['TranscriptText'] ?? $latestTranscript['TranscriptText'] ?? ($appointment['Concern'] ?? '');
    $summaryText = $summaryInput !== '' ? summarizeTranscript($summaryInput) : '';
}

$previousLabel = null;
if ($previousVisit) {
    $prevNotes = $previousVisit['NotesJson'] ? json_decode($previousVisit['NotesJson'], true) : null;
    $prevAssessment = $prevNotes['assessment'] ?? trim(strtok((string) $previousVisit['ClinicalNotes'], "\n"));
    $previousLabel = date('M j', strtotime($previousVisit['AppointmentDate']))
        . ($previousVisit['PhyLastName'] ? ' · Dr. ' . $previousVisit['PhyLastName'] : '')
        . ($prevAssessment ? ' · ' . mb_strimwidth(preg_replace('/^Assessment:\s*/', '', $prevAssessment), 0, 50, '…') : '');
}

// Status steps: Confirmed -> In progress -> Finalized -> Completed.
$step = 1;
if ($consultation || ($queueEntry['Status'] ?? '') === 'Serving') $step = 2;
if ($hasFinalized) $step = 3;
if ($isCompleted) $step = 4;
$steps = [1 => 'Confirmed', 2 => 'In progress', 3 => 'Finalized', 4 => 'Completed'];

if (!$notes['meds']) $notes['meds'][] = ['name' => '', 'frequency' => '', 'duration' => ''];
$csrf = htmlspecialchars(csrfToken());
$readonly = $isCompleted ? ' readonly' : '';
$source = $appointment['BookingFeePaid'] ? 'Appointment ' . date('g:i A', strtotime($appointment['AppointmentTime'])) : 'Walk-in';

$pageTitle = 'Consultation — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container cw-page">
  <a href="<?= HQ_BASE_URL ?>/physician/consultations.php" class="back-link">&larr; Back to consultations</a>

  <section class="an-hero cw-hero">
    <div>
      <span class="an-eyebrow"><?= $queueEntry ? 'Queue #' . (int) $queueEntry['QueueNumber'] : 'Consultation' ?><?= $appointment['IDNumber'] ? ' · ID ' . htmlspecialchars($appointment['IDNumber']) : '' ?></span>
      <h1><?= htmlspecialchars($appointment['FirstName'] . ' ' . $appointment['LastName']) ?> <small>· <?= htmlspecialchars($source) ?><?= $appointment['AppointmentDate'] !== date('Y-m-d') ? ' · ' . htmlspecialchars(date('M j', strtotime($appointment['AppointmentDate']))) : '' ?></small></h1>
      <div class="cw-steps">
        <?php foreach ($steps as $n => $label): ?>
          <span class="<?= $n < $step || ($n === $step && $n === 4) ? 'is-done' : ($n === $step ? 'is-current' : '') ?>"><?= $n < $step || ($n === $step && $n === 4) ? '✓ ' : '' ?><?= $label ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($isCompleted): ?><p class="form-message success">This visit was confirmed complete by the front desk. Notes are read-only.</p>
  <?php elseif ($hasFinalized): ?><p class="form-message cw-info">Finalized — waiting for the front desk to confirm the visit. You can still update the notes.</p><?php endif; ?>

  <div class="cw-grid">
    <div class="cw-col">
      <section class="sd-card">
        <h2 class="cw-card-title">Patient context</h2>
        <dl class="cw-context">
          <div><dt>Stated concern</dt><dd><?= trim((string) $appointment['Concern']) !== '' ? '"' . htmlspecialchars($appointment['Concern']) . '"' : '<span class="cw-muted">None given</span>' ?></dd></div>
          <div><dt>Previous visit</dt><dd><?= $previousLabel ? htmlspecialchars($previousLabel) : '<span class="cw-muted">First visit</span>' ?></dd></div>
          <div><dt>Allergies</dt><dd class="<?= $appointment['Allergies'] ? 'cw-allergy' : '' ?>"><?= $appointment['Allergies'] ? htmlspecialchars($appointment['Allergies']) : '<span class="cw-muted">None recorded</span>' ?></dd></div>
          <?php if ($appointment['BloodType']): ?><div><dt>Blood type</dt><dd><?= htmlspecialchars($appointment['BloodType']) ?></dd></div><?php endif; ?>
        </dl>
      </section>

      <section class="sd-card">
        <div class="cw-card-head">
          <h2 class="cw-card-title">Recording</h2>
          <span class="cw-rec-pill" id="recPill" hidden>● REC <span id="recTimer">00:00</span></span>
        </div>
        <?php if (!$isCompleted): ?>
          <label class="cw-consent"><input type="checkbox" id="consentBox"<?= !empty($consultation['RecordingConsent']) ? ' checked' : '' ?>> Patient consented to recording</label>
          <div class="cw-rec-actions">
            <button type="button" class="btn btn-outline btn-sm" id="recStart">● Start recording</button>
            <button type="button" class="btn btn-outline btn-sm" id="recPause" hidden>❚❚ Pause</button>
            <button type="button" class="btn btn-outline btn-sm cw-stop" id="recStop" hidden>■ Stop</button>
          </div>
          <p class="cw-muted cw-small" id="recStatus"><?= !empty($consultation['RecordingConsent']) ? '' : 'Tick consent before recording.' ?></p>
        <?php endif; ?>
        <audio id="recPlayer" controls<?= !empty($consultation['AudioFilePath']) ? ' src="' . HQ_BASE_URL . '/physician/audio-stream.php?appointment_id=' . (int) $appointmentId . '"' : ' hidden' ?>></audio>

        <?php if (!empty($consultation['AudioFilePath'])): ?>
          <details class="cw-transcript">
            <summary>Transcript</summary>
            <?php if (!$latestTranscript): ?>
              <form method="post" data-save-first>
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="form_type" value="generate_transcript">
                <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
                <button type="submit" class="btn btn-outline btn-sm"<?= $isCompleted ? ' disabled' : '' ?>>Generate transcript</button>
              </form>
            <?php else: ?>
              <form method="post" data-save-first>
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="form_type" value="save_corrected_transcript">
                <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
                <textarea name="corrected_transcript" rows="5"<?= $readonly ?>><?= htmlspecialchars($latestCorrected['TranscriptText'] ?? $latestTranscript['TranscriptText']) ?></textarea>
                <?php if (!$isCompleted): ?><button type="submit" class="btn btn-outline btn-sm">Save correction</button><?php endif; ?>
              </form>
            <?php endif; ?>
          </details>
        <?php endif; ?>
      </section>

      <section class="sd-card">
        <div class="cw-card-head">
          <h2 class="cw-card-title">AI summary</h2>
          <span class="cw-ai-pill">Draft · review needed</span>
        </div>
        <?php if ($summaryText !== ''): ?>
          <p class="cw-summary" id="aiSummary"><?= htmlspecialchars($summaryText) ?></p>
        <?php else: ?>
          <p class="cw-muted cw-small">Record the visit or add a stated concern to get a summary.</p>
        <?php endif; ?>
        <?php if (!$isCompleted): ?>
          <div class="cw-rec-actions">
            <button type="button" class="btn btn-outline btn-sm" id="insertSummary"<?= $summaryText === '' ? ' disabled' : '' ?>>Insert into notes</button>
            <form method="post" data-save-first>
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="form_type" value="regenerate_summary">
              <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
              <button type="submit" class="btn btn-outline btn-sm">Regenerate</button>
            </form>
          </div>
        <?php endif; ?>
        <p class="cw-muted cw-small">AI isn't connected yet — this is placeholder text. Always review before using.</p>
      </section>
    </div>

    <form method="post" class="cw-col" id="notesForm" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
      <input type="hidden" name="form_type" value="save_draft" id="notesFormType">

      <section class="sd-card">
        <div class="cw-card-head">
          <h2 class="cw-card-title">Clinical notes</h2>
          <span class="cw-saved" id="savedLabel" data-saved-at="<?= $lastSavedAt ? htmlspecialchars(date('c', strtotime($lastSavedAt))) : '' ?>"><?= $lastSavedAt ? '✓ Saved' : 'Not saved yet' ?></span>
        </div>
        <label class="cw-label">Subjective<textarea name="subjective" id="subjective" rows="3" placeholder="History and symptoms in the patient's words"<?= $readonly ?>><?= htmlspecialchars($notes['subjective']) ?></textarea></label>
        <span class="cw-label">Objective · vitals</span>
        <div class="cw-vitals">
          <input name="vital_bp" placeholder="BP e.g. 120/80" aria-label="Blood pressure" value="<?= htmlspecialchars($notes['vitals']['bp']) ?>"<?= $readonly ?>>
          <input name="vital_temp" placeholder="Temp e.g. 38.2 °C" aria-label="Temperature" value="<?= htmlspecialchars($notes['vitals']['temp']) ?>"<?= $readonly ?>>
          <input name="vital_weight" placeholder="Weight e.g. 58 kg" aria-label="Weight" value="<?= htmlspecialchars($notes['vitals']['weight']) ?>"<?= $readonly ?>>
        </div>
        <label class="cw-label">Assessment<textarea name="assessment" rows="2" placeholder="Diagnosis or impression"<?= $readonly ?>><?= htmlspecialchars($notes['assessment']) ?></textarea></label>
        <label class="cw-label">Plan<textarea name="plan" rows="3" placeholder="Treatment, advice, follow-up"<?= $readonly ?>><?= htmlspecialchars($notes['plan']) ?></textarea></label>
      </section>

      <section class="sd-card">
        <div class="cw-card-head">
          <h2 class="cw-card-title">Prescription</h2>
          <?php if (!$isCompleted): ?><button type="button" class="av-link cw-link" id="addMed">+ Add medicine</button><?php endif; ?>
        </div>
        <div class="cw-meds" id="medList">
          <?php foreach ($notes['meds'] as $med): ?>
            <div class="cw-med">
              <input name="med_name[]" placeholder="Medicine" aria-label="Medicine" value="<?= htmlspecialchars($med['name']) ?>"<?= $readonly ?>>
              <input name="med_frequency[]" placeholder="How often" aria-label="Frequency" value="<?= htmlspecialchars($med['frequency']) ?>"<?= $readonly ?>>
              <input name="med_duration[]" placeholder="How long" aria-label="Duration" value="<?= htmlspecialchars($med['duration']) ?>"<?= $readonly ?>>
              <?php if (!$isCompleted): ?><button type="button" class="cw-med-remove" aria-label="Remove medicine">&times;</button><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if (!$isCompleted): ?>
        <div class="cw-actions">
          <button type="submit" class="btn btn-outline" data-submit="save_draft">Save draft</button>
          <button type="submit" class="btn btn-primary" data-submit="finalize_notes">Finalize consultation</button>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <?php if (count($history) > 1 || $hasFinalized): ?>
    <details class="qm-finished cw-history">
      <summary><span>Revision history</span><span class="ma-pill ma-pill-completed"><?= count($history) ?></span></summary>
      <ul>
        <?php foreach ($history as $version): ?>
          <li>
            <span class="qm-num">R<?= (int) $version['RevisionNumber'] ?></span>
            <span class="cw-history-text"><?= htmlspecialchars(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $version['ClinicalNotes']), 0, 110, '…')) ?></span>
            <em><?= htmlspecialchars($version['Status']) ?> · <?= htmlspecialchars(date('M j, g:i A', strtotime($version['UpdatedAt']))) ?></em>
          </li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
</div></main>

<template id="medTemplate">
  <div class="cw-med">
    <input name="med_name[]" placeholder="Medicine" aria-label="Medicine">
    <input name="med_frequency[]" placeholder="How often" aria-label="Frequency">
    <input name="med_duration[]" placeholder="How long" aria-label="Duration">
    <button type="button" class="cw-med-remove" aria-label="Remove medicine">&times;</button>
  </div>
</template>

<?php if (!$isCompleted): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('notesForm');
  var savedLabel = document.getElementById('savedLabel');
  var pageUrl = '<?= HQ_BASE_URL ?>/physician/consultation.php';
  var csrf = '<?= $csrf ?>';
  var appointmentId = '<?= (int) $appointmentId ?>';
  var dirty = false;
  var savedAt = savedLabel.getAttribute('data-saved-at') ? new Date(savedLabel.getAttribute('data-saved-at')) : null;

  // ---- "Saved Xs ago" + autosave every 20s while there are changes ----
  function ago() {
    if (dirty) { savedLabel.textContent = 'Unsaved changes'; savedLabel.classList.add('is-dirty'); return; }
    savedLabel.classList.remove('is-dirty');
    if (!savedAt) return;
    var s = Math.max(0, Math.round((Date.now() - savedAt.getTime()) / 1000));
    savedLabel.textContent = '✓ Saved ' + (s < 60 ? s + 's' : Math.round(s / 60) + 'm') + ' ago';
  }
  function saveDraft() {
    var data = new FormData(form);
    data.set('form_type', 'save_draft');
    return fetch(pageUrl, { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (json.ok) { dirty = false; savedAt = new Date(); }
        ago();
        return json.ok;
      })
      .catch(function () { savedLabel.textContent = 'Could not save — retrying'; return false; });
  }
  form.addEventListener('input', function () { dirty = true; ago(); });
  setInterval(function () { if (dirty) saveDraft(); }, 20000);
  setInterval(ago, 5000);
  ago();
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // Save / finalize buttons.
  form.querySelectorAll('[data-submit]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      var type = btn.getAttribute('data-submit');
      document.getElementById('notesFormType').value = type;
      if (type === 'finalize_notes' && !confirm('Finalize this consultation? The front desk will then confirm the visit as complete.')) { e.preventDefault(); return; }
      dirty = false; // the full-page submit saves everything
    });
  });

  // Other forms on the page (transcript, regenerate) save notes first.
  document.querySelectorAll('form[data-save-first]').forEach(function (other) {
    other.addEventListener('submit', function (e) {
      if (!dirty) return;
      e.preventDefault();
      saveDraft().then(function () { other.submit(); });
    });
  });

  // ---- Prescription rows ----
  var medList = document.getElementById('medList');
  document.getElementById('addMed').addEventListener('click', function () {
    medList.appendChild(document.getElementById('medTemplate').content.cloneNode(true));
    medList.lastElementChild.querySelector('input').focus();
    dirty = true; ago();
  });
  medList.addEventListener('click', function (e) {
    var remove = e.target.closest('.cw-med-remove');
    if (!remove) return;
    var row = remove.closest('.cw-med');
    if (medList.children.length > 1) row.remove(); else row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    dirty = true; ago();
  });

  // ---- AI summary -> Subjective ----
  var insertBtn = document.getElementById('insertSummary');
  var summary = document.getElementById('aiSummary');
  if (insertBtn && summary) {
    insertBtn.addEventListener('click', function () {
      var subjective = document.getElementById('subjective');
      subjective.value = (subjective.value.trim() ? subjective.value.trim() + '\n' : '') + summary.textContent.trim();
      subjective.focus();
      dirty = true; ago();
    });
  }

  // ---- Recording: consent, start/pause/resume/stop, auto-upload ----
  var consentBox = document.getElementById('consentBox');
  var startBtn = document.getElementById('recStart');
  var pauseBtn = document.getElementById('recPause');
  var stopBtn = document.getElementById('recStop');
  var pill = document.getElementById('recPill');
  var timer = document.getElementById('recTimer');
  var status = document.getElementById('recStatus');
  var player = document.getElementById('recPlayer');
  var recorder = null, chunks = [], elapsed = 0, tickStart = 0, ticker = null;

  function post(fields) {
    var data = new FormData();
    data.set('csrf_token', csrf);
    data.set('appointment_id', appointmentId);
    Object.keys(fields).forEach(function (k) { data.set(k, fields[k]); });
    return fetch(pageUrl, { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch' } }).then(function (r) { return r.json(); });
  }
  function showTime() {
    var total = elapsed + (tickStart ? (Date.now() - tickStart) : 0);
    var s = Math.floor(total / 1000);
    timer.textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
  }
  function syncConsent() { startBtn.disabled = !consentBox.checked; status.textContent = consentBox.checked ? '' : 'Tick consent before recording.'; }

  if (!navigator.mediaDevices || !window.MediaRecorder) {
    startBtn.disabled = true;
    status.textContent = "This browser can't record audio (needs microphone access over localhost/HTTPS).";
  } else {
    syncConsent();
    consentBox.addEventListener('change', function () {
      syncConsent();
      post({ form_type: 'set_consent', consent: consentBox.checked ? '1' : '0' });
    });

    startBtn.addEventListener('click', function () {
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        chunks = []; elapsed = 0;
        recorder = new MediaRecorder(stream);
        recorder.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
        recorder.onstop = function () {
          stream.getTracks().forEach(function (t) { t.stop(); });
          var blob = new Blob(chunks, { type: 'audio/webm' });
          player.src = URL.createObjectURL(blob);
          player.hidden = false;
          status.textContent = 'Saving recording…';
          var data = new FormData();
          data.set('csrf_token', csrf);
          data.set('appointment_id', appointmentId);
          data.set('form_type', 'upload_audio');
          data.set('duration_seconds', Math.round(elapsed / 1000));
          data.set('audio_file', new File([blob], 'consultation.webm', { type: 'audio/webm' }));
          fetch(pageUrl, { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (json) { status.textContent = json.ok ? 'Recording saved. Reload to generate a transcript.' : (json.errors || ['Could not save the recording.']).join(' '); })
            .catch(function () { status.textContent = 'Could not save the recording.'; });
        };
        recorder.start();
        tickStart = Date.now();
        ticker = setInterval(showTime, 250);
        pill.hidden = false; startBtn.hidden = true; pauseBtn.hidden = false; stopBtn.hidden = false;
        consentBox.disabled = true;
      }).catch(function () { status.textContent = 'Microphone access was denied or is unavailable.'; });
    });

    pauseBtn.addEventListener('click', function () {
      if (recorder.state === 'recording') {
        recorder.pause(); elapsed += Date.now() - tickStart; tickStart = 0;
        pauseBtn.textContent = '● Resume'; pill.classList.add('is-paused');
      } else {
        recorder.resume(); tickStart = Date.now();
        pauseBtn.textContent = '❚❚ Pause'; pill.classList.remove('is-paused');
      }
    });

    stopBtn.addEventListener('click', function () {
      if (tickStart) elapsed += Date.now() - tickStart;
      tickStart = 0; clearInterval(ticker); showTime();
      recorder.stop();
      pill.hidden = true; pauseBtn.hidden = true; stopBtn.hidden = true; startBtn.hidden = false;
      startBtn.textContent = '● Record again'; consentBox.disabled = false;
    });
  }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
