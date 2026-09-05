<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
$clientId = lex_user_client_id((int) $user['id']);
$message = '';
$error = '';
$selectedLawyerId = lex_sanitize_int($_GET['lawyer_id'] ?? 0);
$selectedDate = '';
$selectedTime = '';
$selectedAppointmentType = 'Client Intake Consultation';
$selectedNotes = '';

$formatAppointmentDate = static function (?string $value): string {
    if (!$value) {
        return 'Not scheduled';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
};

$formatAppointmentTime = static function (?string $value): string {
    if (!$value) {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('g:i A');
    } catch (Throwable $e) {
        return '';
    }
};

$appointmentStatusClass = static function (string $status): string {
    return match (strtolower(trim($status))) {
        'confirmed' => 'is-confirmed',
        'pending' => 'is-pending',
        'cancelled' => 'is-cancelled',
        default => 'is-neutral',
    };
};

$appointmentStatusLabel = static function (string $status): string {
    $normalized = strtolower(trim($status));
    return $normalized !== '' ? ucwords(str_replace(['_', '-'], ' ', $normalized)) : 'Unknown';
};

$availableLawyers = lex_recent(
     'SELECT l.id, u.full_name, l.specialization
      FROM lawyers l
      JOIN users u ON u.id = l.user_id
     WHERE u.is_active = 1
       AND l.status = "active"
      ORDER BY u.full_name ASC'
  );

if (isset($_GET['availability']) && $_GET['availability'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $lawyerId = lex_sanitize_int($_GET['lawyer_id'] ?? 0);
    $date = lex_sanitize_text($_GET['date'] ?? '');
    $month = lex_sanitize_text($_GET['month'] ?? '');
    $result = ['ok' => false, 'days' => [], 'selected' => null];

    if ($lawyerId > 0 && preg_match('/^\d{4}-\d{2}(?:-\d{2})?$/', $month !== '' ? $month : $date)) {
        $monthKey = substr($month !== '' ? $month : $date, 0, 7);
        $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $monthKey . '-01');
        if ($monthStart) {
            $monthEnd = $monthStart->modify('last day of this month');
            $stmt = $pdo->prepare('SELECT day_of_week, is_available, morning_start, morning_end, afternoon_start, afternoon_end, morning_capacity, afternoon_capacity FROM lawyer_availability WHERE lawyer_id = :lawyer_id');
            $stmt->execute(['lawyer_id' => $lawyerId]);
            $weekly = [];
            foreach ($stmt->fetchAll() as $row) {
                $weekly[(int) $row['day_of_week']] = $row;
            }

            $stmt = $pdo->prepare('SELECT unavailable_date, reason FROM lawyer_unavailable_dates WHERE lawyer_id = :lawyer_id AND unavailable_date BETWEEN :start_date AND :end_date');
            $stmt->execute(['lawyer_id' => $lawyerId, 'start_date' => $monthStart->format('Y-m-d'), 'end_date' => $monthEnd->format('Y-m-d')]);
            $blocked = [];
            foreach ($stmt->fetchAll() as $row) {
                $blocked[(string) $row['unavailable_date']] = (string) ($row['reason'] ?? '');
            }

            $stmt = $pdo->prepare('SELECT DATE(scheduled_at) AS appointment_date, TIME(scheduled_at) AS appointment_time FROM appointments WHERE lawyer_id = :lawyer_id AND scheduled_at >= :start_dt AND scheduled_at < :end_dt AND status IN ("pending", "confirmed")');
            $stmt->execute(['lawyer_id' => $lawyerId, 'start_dt' => $monthStart->format('Y-m-d 00:00:00'), 'end_dt' => $monthEnd->modify('+1 day')->format('Y-m-d 00:00:00')]);
            $bookings = [];
            foreach ($stmt->fetchAll() as $row) {
                $d = (string) $row['appointment_date'];
                $t = substr((string) $row['appointment_time'], 0, 5);
                if (!isset($bookings[$d])) $bookings[$d] = [];
                $bookings[$d][] = $t;
            }

            for ($cursor = $monthStart; $cursor <= $monthEnd; $cursor = $cursor->modify('+1 day')) {
                $key = $cursor->format('Y-m-d');
                $weekday = (int) $cursor->format('w');
                $schedule = $weekly[$weekday] ?? null;
                $available = $schedule && (int) $schedule['is_available'] === 1 && !isset($blocked[$key]);
                $morningCapacity = $schedule ? (int) $schedule['morning_capacity'] : 0;
                $afternoonCapacity = $schedule ? (int) $schedule['afternoon_capacity'] : 0;
                $morningStart = $schedule['morning_start'] ?? null;
                $morningEnd = $schedule['morning_end'] ?? null;
                $afternoonStart = $schedule['afternoon_start'] ?? null;
                $afternoonEnd = $schedule['afternoon_end'] ?? null;
                $morningBooked = 0;
                $afternoonBooked = 0;
                $bookedTimes = $bookings[$key] ?? [];
                foreach ($bookedTimes as $bt) {
                    if ($morningStart && $morningEnd && $bt >= substr($morningStart, 0, 5) && $bt < substr($morningEnd, 0, 5)) $morningBooked++;
                    if ($afternoonStart && $afternoonEnd && $bt >= substr($afternoonStart, 0, 5) && $bt < substr($afternoonEnd, 0, 5)) $afternoonBooked++;
                }
                $result['days'][$key] = [
                    'available' => $available,
                    'blocked_reason' => $blocked[$key] ?? '',
                    'morning' => ['booked' => $morningBooked, 'capacity' => $morningCapacity, 'start' => $morningStart ? substr($morningStart, 0, 5) : null, 'end' => $morningEnd ? substr($morningEnd, 0, 5) : null],
                    'afternoon' => ['booked' => $afternoonBooked, 'capacity' => $afternoonCapacity, 'start' => $afternoonStart ? substr($afternoonStart, 0, 5) : null, 'end' => $afternoonEnd ? substr($afternoonEnd, 0, 5) : null],
                    'booked_times' => $bookedTimes,
                ];
            }
            $result['ok'] = true;
            $result['selected'] = $result['days'][$date] ?? null;
        }
    }
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $lawyerId = lex_sanitize_int($_POST['lawyer_id'] ?? 0);
    $selectedLawyerId = $lawyerId;
    $scheduledDate = lex_sanitize_text($_POST['scheduled_date'] ?? '');
    $scheduledTime = lex_sanitize_text($_POST['scheduled_time'] ?? '');
    $selectedDate = $scheduledDate;
    $selectedTime = $scheduledTime;
    $scheduledAt = ($scheduledDate !== '' && $scheduledTime !== '') ? $scheduledDate . ' ' . $scheduledTime : '';
    $appointmentType = lex_sanitize_text($_POST['appointment_type'] ?? 'Client Intake Consultation');
    $allowedAppointmentTypes = ['Client Intake Consultation', 'Document Review', 'Case Follow-up', 'Legal Advice'];
    if (!in_array($appointmentType, $allowedAppointmentTypes, true)) {
        $appointmentType = 'Client Intake Consultation';
    }
    $selectedAppointmentType = $appointmentType;
    $notes = lex_sanitize_multiline_text($_POST['notes'] ?? '');
    $selectedNotes = $notes;

    $stmt = $pdo->prepare('
        SELECT l.id, u.id AS lawyer_user_id
        FROM lawyers l
        JOIN users u ON u.id = l.user_id
        WHERE l.id = :lawyer_id
          AND u.is_active = 1
          AND l.status = "active"
        LIMIT 1
    ');
    $stmt->execute(['lawyer_id' => $lawyerId]);
    $lawyerRow = $stmt->fetch();
    $lawyerExists = (int) ($lawyerRow['id'] ?? 0);

    $scheduledDateTime = null;
    if ($scheduledAt !== '') {
        try {
            $scheduledDateTime = new DateTimeImmutable($scheduledAt);
        } catch (Throwable $e) {
            $scheduledDateTime = null;
        }
    }

    if (!$lawyerExists) {
        $error = 'Select an available lawyer.';
    } elseif (!$scheduledDateTime) {
        $error = 'Select a valid appointment date and time.';
    } elseif ($scheduledDateTime <= new DateTimeImmutable()) {
        $error = 'Choose a future date and time for the appointment.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate) || !preg_match('/^\d{2}:\d{2}$/', $scheduledTime)) {
        $error = 'Choose a valid consultation date and time.';
    } else {
        $weekday = (int) $scheduledDateTime->format('w');
        $stmt = $pdo->prepare('SELECT * FROM lawyer_availability WHERE lawyer_id = :lawyer_id AND day_of_week = :day_of_week AND is_available = 1 LIMIT 1');
        $stmt->execute(['lawyer_id' => $lawyerId, 'day_of_week' => $weekday]);
        $schedule = $stmt->fetch();
        $stmt = $pdo->prepare('SELECT reason FROM lawyer_unavailable_dates WHERE lawyer_id = :lawyer_id AND unavailable_date = :unavailable_date LIMIT 1');
        $stmt->execute(['lawyer_id' => $lawyerId, 'unavailable_date' => $scheduledDate]);
        $blockedReason = $stmt->fetchColumn();

        $timeInMinutes = static function (string $time): int {
            [$h, $m] = array_map('intval', explode(':', $time));
            return ($h * 60) + $m;
        };
        $selectedMinutes = $timeInMinutes($scheduledTime);
        $session = null;
        foreach (['morning', 'afternoon'] as $candidate) {
            $startTime = $schedule[$candidate . '_start'] ?? null;
            $endTime = $schedule[$candidate . '_end'] ?? null;
            if ($startTime && $endTime) {
                $startMinutes = $timeInMinutes(substr($startTime, 0, 5));
                $endMinutes = $timeInMinutes(substr($endTime, 0, 5));
                if ($selectedMinutes >= $startMinutes && $selectedMinutes < $endMinutes && (($selectedMinutes - $startMinutes) % 30 === 0)) {
                    $session = $candidate;
                    break;
                }
            }
        }

        if (!$schedule) {
            $error = 'The lawyer is not available on this day.';
        } elseif ($blockedReason !== false) {
            $error = 'The lawyer is unavailable on this date. Please choose another date.';
        } elseif (!$session) {
            $error = 'Choose a consultation time within the lawyer\'s available hours.';
        } else {
            $sessionStart = substr((string) $schedule[$session . '_start'], 0, 8);
            $sessionEnd = substr((string) $schedule[$session . '_end'], 0, 8);
            $sessionCapacity = max(0, (int) $schedule[$session . '_capacity']);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE lawyer_id = :lawyer_id AND DATE(scheduled_at) = :date AND TIME(scheduled_at) = :scheduled_time AND status IN ("pending", "confirmed")');
            $stmt->execute(['lawyer_id' => $lawyerId, 'date' => $scheduledDate, 'scheduled_time' => $scheduledTime . ':00']);
            $slotTaken = (int) $stmt->fetchColumn() > 0;
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE lawyer_id = :lawyer_id AND DATE(scheduled_at) = :date AND TIME(scheduled_at) >= :session_start AND TIME(scheduled_at) < :session_end AND status IN ("pending", "confirmed")');
            $stmt->execute(['lawyer_id' => $lawyerId, 'date' => $scheduledDate, 'session_start' => $sessionStart, 'session_end' => $sessionEnd]);
            $sessionCount = (int) $stmt->fetchColumn();
            if ($slotTaken) {
                $error = 'That time slot was just booked. Please choose another time.';
            } elseif ($sessionCapacity <= 0 || $sessionCount >= $sessionCapacity) {
                $error = ucfirst($session) . ' consultation capacity is full for this date. Please choose another session or date.';
            } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                SELECT id
                FROM cases
                WHERE client_id = :client_id
                  AND lawyer_id = :lawyer_id
                ORDER BY CASE WHEN status IN ("open", "ongoing") THEN 0 ELSE 1 END, id DESC
                LIMIT 1
            ');
            $stmt->execute([
                'client_id' => $clientId,
                'lawyer_id' => $lawyerId,
            ]);
            $caseId = (int) ($stmt->fetchColumn() ?: 0);

            if (!$caseId) {
                do {
                    $caseNumber = 'INTAKE-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
                    $stmt = $pdo->prepare('SELECT 1 FROM cases WHERE case_number = :case_number LIMIT 1');
                    $stmt->execute(['case_number' => $caseNumber]);
                } while ($stmt->fetchColumn());

                $pdo->prepare(
                    'INSERT INTO cases (case_number, title, description, lawyer_id, client_id, status, priority, filed_date, closed_date)
                     VALUES (:case_number, :title, :description, :lawyer_id, :client_id, "open", "normal", CURDATE(), NULL)'
                )->execute([
                    'case_number' => $caseNumber,
                    'title' => $appointmentType !== '' ? $appointmentType : 'Client Intake Consultation',
                    'description' => $notes !== '' ? $notes : 'Client appointment request created automatically.',
                    'lawyer_id' => $lawyerId,
                    'client_id' => $clientId,
                ]);
                $caseId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'INSERT INTO appointments (case_id, client_id, lawyer_id, scheduled_at, appointment_type, status, notes)
                 VALUES (:case_id, :client_id, :lawyer_id, :scheduled_at, :appointment_type, "pending", :notes)'
            )->execute([
                'case_id' => $caseId,
                'client_id' => $clientId,
                'lawyer_id' => $lawyerId,
                'scheduled_at' => $scheduledAt,
                'appointment_type' => $appointmentType,
                'notes' => $notes,
            ]);
            $appointmentId = (string) $pdo->lastInsertId();
            lex_audit('book_appointment', 'appointments', $appointmentId);
            $pdo->commit();
            lex_notify((int) ($lawyerRow['lawyer_user_id'] ?? 0), 'appointment', 'New appointment request from ' . (string) ($user['full_name'] ?? 'a client') . '.');
            $message = 'Appointment request submitted. Your lawyer will review it and confirm the schedule.';
            $selectedDate = '';
            $selectedTime = '';
            $selectedAppointmentType = 'Client Intake Consultation';
            $selectedNotes = '';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Client appointment booking failed: ' . $e->getMessage());
            $error = 'Unable to book the appointment. Please try again.';
        }
        }
    }
}
}

$appointments = lex_recent(
    'SELECT a.*, c.case_number, COALESCE(NULLIF(a.appointment_type, ""), NULLIF(c.title, ""), "Appointment Request") AS appointment_title, u.full_name AS lawyer_name, u.avatar_stored_name AS lawyer_avatar_stored_name
     FROM appointments a
     JOIN cases c ON c.id = a.case_id
     JOIN lawyers l ON l.id = a.lawyer_id
     JOIN users u ON u.id = l.user_id
     WHERE a.client_id = :id
       AND a.status <> "deleted"
     ORDER BY a.scheduled_at DESC',
    ['id' => $clientId]
);

lex_page_header('Appointments', 'appointments', $user);
?>
<section class="client-appointment-page" data-client-appointment-page>
<section class="card client-appointment-card">
  <div class="client-appointment-panel-head">
    <div>
      <h2>Schedule Consultation</h2>
      <p>Book an appointment with your lawyer.</p>
    </div>
  </div>
  <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
  <?php if (!empty($availableLawyers)): ?>
    <form method="post" class="client-appointment-form" data-client-appointment-form data-loading-text="Requesting...">
      <?= lex_csrf_field() ?>
      <div class="client-appointment-form-main">
        <label class="client-appointment-field client-appointment-field--full">Choose Lawyer
          <span class="client-appointment-control">
            <span class="client-appointment-control-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false"><path d="M10.5 4a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13Zm0 1.5a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm4.2 9.26 4.02 4.02-1.06 1.06-4.02-4.02 1.06-1.06Z" fill="currentColor"/></svg>
            </span>
            <select name="lawyer_id" required data-appointment-lawyer-select>
              <option value="">Choose a lawyer</option>
              <?php foreach ($availableLawyers as $lawyer): ?>
                <?php $lawyerSpecialization = trim((string) ($lawyer['specialization'] ?? '')); ?>
                <option value="<?= (int) $lawyer['id'] ?>" data-specialization="<?= lex_e($lawyerSpecialization !== '' ? $lawyerSpecialization : 'General Practice') ?>"<?= (int) $lawyer['id'] === $selectedLawyerId ? ' selected' : '' ?>><?= lex_e($lawyer['full_name'] . ($lawyerSpecialization !== '' ? ' - ' . $lawyerSpecialization : '')) ?></option>
              <?php endforeach; ?>
            </select>
          </span>
        </label>
        <div class="client-appointment-field client-appointment-field--full">
          <span class="client-appointment-label">Date &amp; Consultation Time</span>
          <input type="hidden" name="scheduled_date" value="<?= lex_e($selectedDate) ?>" required data-appointment-date>
          <input type="hidden" name="scheduled_time" value="<?= lex_e($selectedTime) ?>" required data-appointment-time>
          <button class="client-appointment-picker-trigger" type="button" data-appointment-picker-open>
            <span class="client-appointment-picker-icon" aria-hidden="true">📅</span>
            <span>
              <strong data-appointment-picker-label><?= $selectedDate && $selectedTime ? lex_e($formatAppointmentDate($selectedDate . ' ' . $selectedTime) . ' at ' . $formatAppointmentTime($selectedDate . ' ' . $selectedTime)) : 'Choose date and time' ?></strong>
              <small data-appointment-picker-meta>Choose your lawyer first, then select an available session and time.</small>
            </span>
            <span aria-hidden="true">›</span>
          </button>
        </div>
        <label class="client-appointment-field client-appointment-field--full">Appointment Type
          <span class="client-appointment-control">
            <span class="client-appointment-control-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false"><path d="M6.75 4h10.5A1.75 1.75 0 0 1 19 5.75v12.5A1.75 1.75 0 0 1 17.25 20H6.75A1.75 1.75 0 0 1 5 18.25V5.75A1.75 1.75 0 0 1 6.75 4Zm2 4.25a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 4a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z" fill="currentColor"/></svg>
            </span>
            <select name="appointment_type" required data-appointment-type>
              <?php foreach (['Client Intake Consultation', 'Document Review', 'Case Follow-up', 'Legal Advice'] as $type): ?>
                <option value="<?= lex_e($type) ?>"<?= $selectedAppointmentType === $type ? ' selected' : '' ?>><?= lex_e($type) ?></option>
              <?php endforeach; ?>
            </select>
          </span>
        </label>
      </div>
      <div class="client-appointment-form-side">
        <label class="client-appointment-field">Notes <span>(Optional)</span>
          <textarea name="notes" rows="6" maxlength="500" placeholder="Add any notes or details about your appointment..."><?= lex_e($selectedNotes) ?></textarea>
        </label>
        <div class="client-appointment-summary" aria-live="polite">
          <span>Request summary</span>
          <strong data-appointment-summary-lawyer>Choose a lawyer</strong>
          <small data-appointment-summary-meta>Select a date and time to preview this request.</small>
        </div>
        <button class="button button-primary client-appointment-submit" type="submit">
          <span aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M7 3.5a.75.75 0 0 1 .75.75V5h8.5v-.75a.75.75 0 0 1 1.5 0V5h.5A2.25 2.25 0 0 1 20.5 7.25v10.5A2.25 2.25 0 0 1 18.25 20h-12.5A2.25 2.25 0 0 1 3.5 17.75V7.25A2.25 2.25 0 0 1 5.75 5h.5v-.75A.75.75 0 0 1 7 3.5Zm11.25 6h-12v8.25c0 .41.34.75.75.75h10.5c.41 0 .75-.34.75-.75V9.5Z" fill="currentColor"/></svg>
          </span>
          Request Appointment
        </button>
      </div>
    </form>
  <?php else: ?>
    <p class="muted">No active lawyers are available right now.</p>
  <?php endif; ?>
</section>

<section class="card client-appointment-requests-card">
  <div class="card-head client-appointment-requests-head">
    <div>
      <h2>Appointment Requests</h2>
      <p class="muted client-appointment-requests-note">This page shows your appointment requests and their current status.</p>
    </div>
    <span class="pill"><?= count($appointments) ?> Total</span>
  </div>
  <div class="table-wrap">
    <table class="data-table client-appointment-requests-table">
      <thead><tr><th>Case</th><th>Lawyer</th><th>Scheduled</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ($appointments as $appointment): ?>
        <?php
          $lawyerName = (string) ($appointment['lawyer_name'] ?? 'Lawyer');
          $lawyerAvatarUrl = lex_profile_avatar_url((string) ($appointment['lawyer_avatar_stored_name'] ?? ''));
          $appointmentNotes = trim((string) ($appointment['notes'] ?? ''));
        ?>
        <tr>
          <td data-label="Case">
            <strong class="client-appointment-case-title"><?= lex_e((string) $appointment['appointment_title']) ?></strong>
          </td>
          <td data-label="Lawyer">
            <div class="client-appointment-lawyer-cell">
              <?php if ($lawyerAvatarUrl !== ''): ?>
                <img class="client-appointment-lawyer-avatar" src="<?= lex_e($lawyerAvatarUrl) ?>" alt="Avatar for <?= lex_e($lawyerName) ?>">
              <?php else: ?>
                <span class="client-appointment-lawyer-avatar client-appointment-lawyer-avatar--fallback" aria-hidden="true"><?= lex_e(strtoupper(substr($lawyerName, 0, 1))) ?></span>
              <?php endif; ?>
              <strong><?= lex_e($lawyerName) ?></strong>
            </div>
          </td>
          <td data-label="Scheduled">
            <strong class="client-appointment-date"><?= lex_e($formatAppointmentDate((string) $appointment['scheduled_at'])) ?></strong>
            <span class="client-appointment-time"><?= lex_e($formatAppointmentTime((string) $appointment['scheduled_at'])) ?></span>
          </td>
          <td data-label="Status"><span class="appointment-badge <?= lex_e($appointmentStatusClass((string) $appointment['status'])) ?>"><?= lex_e($appointmentStatusLabel((string) $appointment['status'])) ?></span></td>
          <td data-label="Notes">
            <button
              class="client-appointment-note-button"
              type="button"
              data-client-note-open
              data-note="<?= lex_e($appointmentNotes !== '' ? $appointmentNotes : 'No notes were added for this appointment.') ?>"
              aria-label="Read appointment notes"
              title="Read notes"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M6.75 3.75h10.5A2.25 2.25 0 0 1 19.5 6v12a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 18V6a2.25 2.25 0 0 1 2.25-2.25Zm1.5 4.5a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.25a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.25a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Z" fill="currentColor"/>
              </svg>
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$appointments): ?>
        <tr><td colspan="5" class="muted">No appointment requests yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="modal-overlay client-appointment-picker-modal" data-appointment-picker-modal aria-hidden="true">
  <article class="modal-card client-appointment-picker-card" role="dialog" aria-modal="true" aria-labelledby="appointmentPickerTitle">
    <header class="modal-header">
      <div>
        <h2 id="appointmentPickerTitle">Choose Consultation Schedule</h2>
        <p class="muted" data-appointment-picker-lawyer>Choose a lawyer first.</p>
      </div>
      <button class="close-button" type="button" data-appointment-picker-close aria-label="Close calendar">&times;</button>
    </header>
    <div class="client-appointment-picker-body">
      <div class="client-appointment-calendar-head">
        <button type="button" class="button" data-calendar-prev aria-label="Previous month">‹</button>
        <strong data-calendar-title></strong>
        <button type="button" class="button" data-calendar-next aria-label="Next month">›</button>
      </div>
      <div class="client-appointment-calendar-weekdays" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
      <div class="client-appointment-calendar-grid" data-calendar-grid></div>
      <div class="client-appointment-session-area" data-session-area hidden>
        <div class="client-appointment-selected-date" data-selected-date-label></div>
        <div class="client-appointment-session-grid">
          <button type="button" class="client-appointment-session-card" data-session="morning"><span>Morning</span><strong data-morning-count>10/10</strong><small>8:00 AM – 12:30 PM</small></button>
          <button type="button" class="client-appointment-session-card" data-session="afternoon"><span>Afternoon</span><strong data-afternoon-count>10/10</strong><small>1:00 PM – 5:30 PM</small></button>
        </div>
        <div class="client-appointment-time-area" data-time-area hidden>
          <h3>Choose a time</h3>
          <div class="client-appointment-time-grid" data-time-grid></div>
        </div>
      </div>
    </div>
  </article>
</div>

<div class="modal-overlay client-appointment-note-modal" data-client-note-modal aria-hidden="true">
  <article class="modal-card client-appointment-note-card" role="dialog" aria-modal="true" aria-labelledby="clientAppointmentNoteTitle">
    <header class="modal-header">
      <h2 id="clientAppointmentNoteTitle">Appointment Notes</h2>
      <button class="close-button" type="button" data-client-note-close aria-label="Close notes">&times;</button>
    </header>
    <div class="modal-body">
      <p class="client-appointment-note-text" data-client-note-text></p>
    </div>
  </article>
</div>
</section>
<?php lex_page_footer(); ?>
