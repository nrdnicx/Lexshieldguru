<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('lawyer');
$pdo = lex_pdo();
$lawyerId = lex_user_lawyer_id((int) $user['id']);
$message = '';
$error = '';
$days = [
    0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
    4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
];

$defaults = [];
foreach ($days as $number => $label) {
    $defaults[$number] = [
        'day_of_week' => $number, 'is_available' => in_array($number, [1,2,3,4,5], true) ? 1 : 0,
        'morning_start' => '08:00', 'morning_end' => '12:30',
        'afternoon_start' => '13:00', 'afternoon_end' => '17:30',
        'morning_capacity' => 10, 'afternoon_capacity' => 10,
    ];
}

$stmt = $pdo->prepare('SELECT * FROM lawyer_availability WHERE lawyer_id = :lawyer_id ORDER BY day_of_week');
$stmt->execute(['lawyer_id' => $lawyerId]);
foreach ($stmt->fetchAll() as $row) {
    $defaults[(int) $row['day_of_week']] = array_merge($defaults[(int) $row['day_of_week']] ?? [], $row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $action = lex_sanitize_text($_POST['action'] ?? 'save_weekly');
    try {
        if ($action === 'save_weekly') {
            $pdo->beginTransaction();
            $upsert = $pdo->prepare('INSERT INTO lawyer_availability (lawyer_id, day_of_week, is_available, morning_start, morning_end, afternoon_start, afternoon_end, morning_capacity, afternoon_capacity)
                VALUES (:lawyer_id, :day_of_week, :is_available, :morning_start, :morning_end, :afternoon_start, :afternoon_end, :morning_capacity, :afternoon_capacity)
                ON DUPLICATE KEY UPDATE is_available=VALUES(is_available), morning_start=VALUES(morning_start), morning_end=VALUES(morning_end), afternoon_start=VALUES(afternoon_start), afternoon_end=VALUES(afternoon_end), morning_capacity=VALUES(morning_capacity), afternoon_capacity=VALUES(afternoon_capacity)');
            foreach ($days as $day => $label) {
                $enabled = isset($_POST['enabled'][$day]) ? 1 : 0;
                $ms = trim((string) ($_POST['morning_start'][$day] ?? '')) ?: null;
                $me = trim((string) ($_POST['morning_end'][$day] ?? '')) ?: null;
                $as = trim((string) ($_POST['afternoon_start'][$day] ?? '')) ?: null;
                $ae = trim((string) ($_POST['afternoon_end'][$day] ?? '')) ?: null;
                $mc = max(0, min(100, (int) ($_POST['morning_capacity'][$day] ?? 10)));
                $ac = max(0, min(100, (int) ($_POST['afternoon_capacity'][$day] ?? 10)));
                $validTime = static fn($v) => $v === null || preg_match('/^\d{2}:\d{2}$/', $v);
                if (!$validTime($ms) || !$validTime($me) || !$validTime($as) || !$validTime($ae)) throw new RuntimeException('Enter valid times for every day.');
                if ($enabled && $mc === 0 && $ac === 0) throw new RuntimeException($label . ' must have at least one consultation capacity.');
                if ($ms !== null && $me !== null && $me <= $ms) throw new RuntimeException($label . ' morning end time must be after the start time.');
                if ($as !== null && $ae !== null && $ae <= $as) throw new RuntimeException($label . ' afternoon end time must be after the start time.');
                $upsert->execute(['lawyer_id'=>$lawyerId,'day_of_week'=>$day,'is_available'=>$enabled,'morning_start'=>$ms,'morning_end'=>$me,'afternoon_start'=>$as,'afternoon_end'=>$ae,'morning_capacity'=>$mc,'afternoon_capacity'=>$ac]);
            }
            $pdo->commit();
            $message = 'Your weekly availability has been saved.';
        } elseif ($action === 'add_block') {
            $date = lex_sanitize_text($_POST['unavailable_date'] ?? '');
            $reason = lex_sanitize_text($_POST['reason'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Choose a valid unavailable date.');
            $pdo->prepare('INSERT INTO lawyer_unavailable_dates (lawyer_id, unavailable_date, reason) VALUES (:lawyer_id, :date, :reason) ON DUPLICATE KEY UPDATE reason=VALUES(reason)')->execute(['lawyer_id'=>$lawyerId,'date'=>$date,'reason'=>$reason !== '' ? $reason : null]);
            $message = 'Unavailable date added.';
        } elseif ($action === 'remove_block') {
            $id = lex_sanitize_int($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM lawyer_unavailable_dates WHERE id = :id AND lawyer_id = :lawyer_id')->execute(['id'=>$id,'lawyer_id'=>$lawyerId]);
            $message = 'Unavailable date removed.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$stmt = $pdo->prepare('SELECT * FROM lawyer_availability WHERE lawyer_id = :lawyer_id ORDER BY day_of_week');
$stmt->execute(['lawyer_id' => $lawyerId]);
foreach ($stmt->fetchAll() as $row) $defaults[(int) $row['day_of_week']] = array_merge($defaults[(int) $row['day_of_week']] ?? [], $row);

$stmt = $pdo->prepare('SELECT * FROM lawyer_unavailable_dates WHERE lawyer_id = :lawyer_id AND unavailable_date >= CURDATE() ORDER BY unavailable_date LIMIT 60');
$stmt->execute(['lawyer_id' => $lawyerId]);
$blockedDates = $stmt->fetchAll();

lex_page_header('Availability', 'availability', $user);
?>
<main class="main-content lawyer-availability-page">
  <section class="card availability-hero">
    <div><span class="eyebrow">Appointments</span><h1>My Availability</h1><p class="muted">Set the days and hours when clients can request consultations. Your schedule controls what clients see on the booking calendar.</p></div>
    <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/appointment.php')) ?>">View appointments</a>
  </section>

  <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= lex_e($error) ?></div><?php endif; ?>

  <section class="card availability-card">
    <div class="card-head"><div><h2>Weekly schedule</h2><p class="muted">Use 30-minute booking intervals. Capacity is the maximum number of active requests allowed in each session.</p></div></div>
    <form method="post" data-no-loading>
      <?= lex_csrf_field() ?><input type="hidden" name="action" value="save_weekly">
      <div class="availability-list">
      <?php foreach ($days as $day => $label): $row = $defaults[$day]; ?>
        <article class="availability-day-row">
          <div class="availability-day-name"><label><input type="checkbox" name="enabled[<?= $day ?>]" value="1"<?= (int)$row['is_available'] === 1 ? ' checked' : '' ?>><strong><?= lex_e($label) ?></strong></label><small>Day <?= $day ?></small></div>
          <div class="availability-session"><strong>Morning</strong><div class="availability-fields"><label>Start<input type="time" name="morning_start[<?= $day ?>]" value="<?= lex_e(substr((string)$row['morning_start'],0,5)) ?>"></label><label>End<input type="time" name="morning_end[<?= $day ?>]" value="<?= lex_e(substr((string)$row['morning_end'],0,5)) ?>"></label><label>Capacity<input type="number" min="0" max="100" name="morning_capacity[<?= $day ?>]" value="<?= (int)$row['morning_capacity'] ?>"></label></div></div>
          <div class="availability-session"><strong>Afternoon</strong><div class="availability-fields"><label>Start<input type="time" name="afternoon_start[<?= $day ?>]" value="<?= lex_e(substr((string)$row['afternoon_start'],0,5)) ?>"></label><label>End<input type="time" name="afternoon_end[<?= $day ?>]" value="<?= lex_e(substr((string)$row['afternoon_end'],0,5)) ?>"></label><label>Capacity<input type="number" min="0" max="100" name="afternoon_capacity[<?= $day ?>]" value="<?= (int)$row['afternoon_capacity'] ?>"></label></div></div>
        </article>
      <?php endforeach; ?>
      </div>
      <div class="availability-actions"><button class="button button-primary" type="submit">Save weekly availability</button></div>
    </form>
  </section>

  <section class="card availability-card">
    <div class="card-head"><div><h2>Unavailable dates</h2><p class="muted">Block a specific day for court appearances, leave, holidays, or other commitments.</p></div></div>
    <form method="post" class="availability-block-form" data-no-loading>
      <?= lex_csrf_field() ?><input type="hidden" name="action" value="add_block">
      <label>Date<input type="date" name="unavailable_date" min="<?= date('Y-m-d') ?>" required></label>
      <label>Reason <span>(Optional)</span><input type="text" name="reason" maxlength="255" placeholder="e.g. Court appearance"></label>
      <button class="button button-secondary" type="submit">Block date</button>
    </form>
    <?php if ($blockedDates): ?><div class="availability-block-list"><?php foreach ($blockedDates as $block): ?><div class="availability-block-item"><div><strong><?= lex_e(date('M j, Y', strtotime((string)$block['unavailable_date']))) ?></strong><span><?= lex_e((string)($block['reason'] ?: 'Unavailable')) ?></span></div><form method="post" data-no-loading><?= lex_csrf_field() ?><input type="hidden" name="action" value="remove_block"><input type="hidden" name="id" value="<?= (int)$block['id'] ?>"><button class="button button-danger" type="submit" data-confirm="Remove this unavailable date?">Remove</button></form></div><?php endforeach; ?></div><?php else: ?><p class="muted">No upcoming unavailable dates.</p><?php endif; ?>
  </section>
</main>
<?php lex_page_footer(); ?>
