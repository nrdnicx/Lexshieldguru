<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('lawyer');
$pdo = lex_pdo();
$lawyerId = lex_user_lawyer_id((int) $user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $paymentId = lex_sanitize_int($_POST['payment_id'] ?? 0);
    $decision = isset($_POST['verify_payment'])
        ? 'verified'
        : (isset($_POST['reject_payment']) ? 'rejected' : trim((string) ($_POST['decision'] ?? '')));
    $reviewNotes = trim(lex_sanitize_text($_POST['review_notes'] ?? ''));

    if ($paymentId <= 0 || !in_array($decision, ['verified', 'rejected'], true)) {
        lex_flash_set('error', 'Choose a valid payment decision.');
        header('Location: ' . lex_app_url('lawyer/payments.php'));
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT mp.id, mp.payment_for, c.user_id AS client_user_id
         FROM manual_payments mp
         JOIN clients c ON c.id = mp.client_id
         WHERE mp.id = :id
           AND mp.lawyer_id = :lawyer_id
         LIMIT 1'
    );
    $stmt->execute(['id' => $paymentId, 'lawyer_id' => $lawyerId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        lex_flash_set('error', 'Payment not found.');
        header('Location: ' . lex_app_url('lawyer/payments.php'));
        exit;
    }

    $pdo->prepare(
        'UPDATE manual_payments
         SET status = :status,
             admin_notes = :review_notes,
             reviewed_by_user_id = :reviewed_by_user_id,
             reviewed_at = NOW()
         WHERE id = :id
           AND lawyer_id = :lawyer_id'
    )->execute([
        'status' => $decision,
        'review_notes' => $reviewNotes,
        'reviewed_by_user_id' => (int) $user['id'],
        'id' => $paymentId,
        'lawyer_id' => $lawyerId,
    ]);

    lex_audit($decision === 'verified' ? 'lawyer_verify_payment' : 'lawyer_reject_payment', 'manual_payments', (string) $paymentId);
    $note = $reviewNotes !== '' ? ' Note: ' . $reviewNotes : '';
    lex_notify((int) $payment['client_user_id'], 'payment', 'Your payment for "' . (string) $payment['payment_for'] . '" was marked ' . $decision . ' by your lawyer.' . $note);
    foreach (lex_recent('SELECT id FROM users WHERE role = "admin" AND is_active = 1') as $admin) {
        lex_notify((int) $admin['id'], 'payment', 'A lawyer marked payment #' . (string) $paymentId . ' as ' . $decision . '.');
    }

    lex_flash_set('success', $decision === 'verified' ? 'Payment verified.' : 'Payment rejected.');
    header('Location: ' . lex_app_url('lawyer/payments.php'));
    exit;
}

$summary = lex_recent(
    'SELECT
        COUNT(*) AS total_payments,
        SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_payments,
        SUM(CASE WHEN status = "verified" THEN 1 ELSE 0 END) AS verified_payments,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) AS rejected_payments,
        SUM(CASE WHEN status = "verified" THEN amount ELSE 0 END) AS verified_total
     FROM manual_payments
     WHERE lawyer_id = :lawyer_id',
    ['lawyer_id' => $lawyerId]
);
$totals = $summary[0] ?? [
    'total_payments' => 0,
    'pending_payments' => 0,
    'verified_payments' => 0,
    'rejected_payments' => 0,
    'verified_total' => 0,
];

$perPage = 10;
$totalPayments = lex_stats(
    'SELECT COUNT(*)
     FROM manual_payments
     WHERE lawyer_id = :lawyer_id',
    ['lawyer_id' => $lawyerId]
);
$totalPaymentPages = max(1, (int) ceil($totalPayments / $perPage));
$currentPage = min(max(1, lex_sanitize_int($_GET['page'] ?? 1)), $totalPaymentPages);
$offset = ($currentPage - 1) * $perPage;

$payments = lex_recent(
    'SELECT mp.*, cu.full_name AS client_name, cu.email AS client_email, cu.avatar_stored_name AS client_avatar_stored_name
     FROM manual_payments mp
     JOIN clients c ON c.id = mp.client_id
     JOIN users cu ON cu.id = c.user_id
     WHERE mp.lawyer_id = :lawyer_id
     ORDER BY mp.status = "pending" DESC, mp.created_at DESC
     LIMIT ' . $perPage . ' OFFSET ' . $offset,
    ['lawyer_id' => $lawyerId]
);

lex_page_header('Payments', 'payments', $user);
?>
<section class="payment-shell payment-lawyer-shell">
  <section class="admin-payments-stats" aria-label="Payment summary">
    <article class="admin-payments-stat-card">
      <div class="admin-payments-stat-icon tone-blue" aria-hidden="true">#</div>
      <div class="admin-payments-stat-copy"><span>Total</span><strong><?= (int) ($totals['total_payments'] ?? 0) ?></strong></div>
    </article>
    <article class="admin-payments-stat-card">
      <div class="admin-payments-stat-icon tone-green" aria-hidden="true">?</div>
      <div class="admin-payments-stat-copy"><span>Pending</span><strong><?= (int) ($totals['pending_payments'] ?? 0) ?></strong></div>
    </article>
    <article class="admin-payments-stat-card">
      <div class="admin-payments-stat-icon tone-purple" aria-hidden="true">OK</div>
      <div class="admin-payments-stat-copy"><span>Verified</span><strong><?= (int) ($totals['verified_payments'] ?? 0) ?></strong></div>
    </article>
    <article class="admin-payments-stat-card">
      <div class="admin-payments-stat-icon tone-gold" aria-hidden="true">PHP</div>
      <div class="admin-payments-stat-copy"><span>Verified earnings</span><strong>PHP <?= lex_e(number_format((float) ($totals['verified_total'] ?? 0), 2)) ?></strong></div>
    </article>
  </section>

  <section class="card payment-page-card admin-payments-review-card lawyer-payments-review-card">
    <div class="card-head admin-payments-review-head">
      <div>
        <h2>Client Payment Proofs</h2>
        <p class="muted payment-section-copy">Confirm only payments that arrived in your own GCash account.</p>
      </div>
    </div>

    <div class="lawyer-payment-mobile-list" aria-label="Client payment proofs">
      <?php if ($payments): ?>
        <?php foreach ($payments as $payment): ?>
          <article class="lawyer-payment-mobile-card">
            <div class="lawyer-payment-mobile-top">
              <div>
                <span class="lawyer-payment-mobile-label">CLIENT</span>
                <strong><?= lex_e((string) $payment['client_name']) ?></strong>
                <small><?= lex_e((string) $payment['client_email']) ?></small>
              </div>
              <span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span>
            </div>
            <div class="lawyer-payment-mobile-amount">
              <span>AMOUNT</span>
              <strong>PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></strong>
              <small><?= lex_e((string) $payment['payment_for']) ?></small>
            </div>
            <div class="lawyer-payment-mobile-grid">
              <div><span>SUBMITTED</span><strong><?= lex_e(date('M j, Y', strtotime((string) $payment['created_at']))) ?></strong><small><?= lex_e(date('g:i A', strtotime((string) $payment['created_at']))) ?></small></div>
              <div><span>REFERENCE</span><strong><?= !empty($payment['reference_number']) ? lex_e((string) $payment['reference_number']) : 'None' ?></strong><small>Payment reference</small></div>
              <div><span>PROOF</span><strong><?= lex_e((string) ($payment['proof_original_name'] ?? 'Uploaded proof')) ?></strong><small>Tap open to inspect</small></div>
              <div><span>DECISION</span><strong><?= ucfirst((string) $payment['status']) ?></strong><small><?= !empty($payment['reviewed_at']) ? 'Reviewed ' . lex_e(date('M j, Y', strtotime((string) $payment['reviewed_at']))) : 'Awaiting review' ?></small></div>
            </div>
            <a class="button button-secondary lawyer-payment-mobile-proof" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'])) ?>" target="_blank" rel="noopener">Open payment proof</a>
            <form method="post" class="lawyer-payment-mobile-decision-form">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
              <input type="hidden" name="decision" value="verified" data-payment-decision-mobile>
              <label>Review note<input type="text" name="review_notes" value="<?= lex_e((string) ($payment['admin_notes'] ?? '')) ?>" placeholder="Optional note"></label>
              <div class="lawyer-payment-mobile-actions">
                <button class="button button-primary" type="submit" name="verify_payment" value="1" onclick="this.form.querySelector('[data-payment-decision-mobile]').value='verified'">Verify</button>
                <button class="button button-secondary" type="submit" name="reject_payment" value="1" onclick="this.form.querySelector('[data-payment-decision-mobile]').value='rejected'">Reject</button>
              </div>
            </form>
          </article>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="lawyer-payment-mobile-empty">No client payment proofs have been submitted to you yet.</div>
      <?php endif; ?>
    </div>

    <div class="table-wrap">
      <table class="data-table payment-history-table">
        <thead>
          <tr>
            <th>Submitted</th>
            <th>Client</th>
            <th>Payment for</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Proof</th>
            <th>Decision</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($payments): ?>
            <?php foreach ($payments as $payment): ?>
              <tr>
                <td data-label="Submitted">
                  <strong class="admin-payments-date"><?= lex_e(date('M j, Y', strtotime((string) $payment['created_at']))) ?></strong>
                  <span class="admin-payments-time"><?= lex_e(date('g:i A', strtotime((string) $payment['created_at']))) ?></span>
                </td>
                <td data-label="Client">
                  <div class="admin-payments-client-cell">
                    <div>
                      <strong><?= lex_e((string) $payment['client_name']) ?></strong>
                      <span><?= lex_e((string) $payment['client_email']) ?></span>
                    </div>
                  </div>
                </td>
                <td data-label="Payment for"><?= lex_e((string) $payment['payment_for']) ?></td>
                <td data-label="Amount">PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></td>
                <td data-label="Status"><span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span></td>
                <td data-label="Proof"><a class="payment-download-link" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'])) ?>" target="_blank" rel="noopener">Open</a></td>
                <td data-label="Decision">
                  <form method="post" class="lawyer-payment-decision-form">
                    <?= lex_csrf_field() ?>
                    <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                    <input type="hidden" name="decision" value="verified" data-payment-decision>
                    <input type="text" name="review_notes" value="<?= lex_e((string) ($payment['admin_notes'] ?? '')) ?>" placeholder="Optional note">
                    <div class="inline-actions">
                      <button class="button button-primary" type="submit" name="verify_payment" value="1" onclick="this.form.querySelector('[data-payment-decision]').value='verified'">Verify</button>
                      <button class="button button-secondary" type="submit" name="reject_payment" value="1" onclick="this.form.querySelector('[data-payment-decision]').value='rejected'">Reject</button>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="muted">No client payment proofs have been submitted to you yet.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= lex_admin_pagination('lawyer/payments.php', [], $totalPayments, $currentPage, $perPage) ?>
  </section>
</section>
<?php lex_page_footer(); ?>
