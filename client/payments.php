<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
$clientId = lex_user_client_id((int) $user['id']);

$gcashInstructions = trim(lex_site_setting('gcash_instructions'));
$lawyerPaymentAccounts = lex_recent(
    'SELECT l.id, l.gcash_account_name, l.gcash_number, l.gcash_qr_stored_name, u.full_name
     FROM lawyers l
     JOIN users u ON u.id = l.user_id
     WHERE u.is_active = 1
       AND l.status IN ("active", "busy")
       AND l.gcash_account_name IS NOT NULL
       AND l.gcash_account_name <> ""
       AND l.gcash_number IS NOT NULL
       AND l.gcash_number <> ""
       AND l.gcash_qr_stored_name IS NOT NULL
       AND l.gcash_qr_stored_name <> ""
     ORDER BY u.full_name ASC'
);
$selectedLawyerId = lex_sanitize_int($_GET['lawyer_id'] ?? ($_POST['lawyer_id'] ?? 0));
if ($selectedLawyerId <= 0 && $lawyerPaymentAccounts) {
    $selectedLawyerId = (int) $lawyerPaymentAccounts[0]['id'];
}
$selectedLawyer = null;
foreach ($lawyerPaymentAccounts as $paymentLawyer) {
    if ((int) $paymentLawyer['id'] === $selectedLawyerId) {
        $selectedLawyer = $paymentLawyer;
        break;
    }
}
$gcashReady = (bool) $lawyerPaymentAccounts;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $proof = null;
    try {
        if (!$gcashReady) {
            throw new RuntimeException('No lawyer payment QR is configured yet. Please contact your lawyer.');
        }
        $lawyerId = lex_sanitize_int($_POST['lawyer_id'] ?? 0);

        $paymentFor = trim(lex_sanitize_text($_POST['payment_for'] ?? ''));
        $amountInput = str_replace(',', '', trim((string) ($_POST['amount'] ?? '0')));
        $amount = is_numeric($amountInput) ? (float) $amountInput : 0.0;
        $payerName = trim(lex_sanitize_text($_POST['payer_name'] ?? ''));
        $payerContact = trim(lex_sanitize_text($_POST['payer_contact'] ?? ''));
        $referenceNumber = trim(lex_sanitize_text($_POST['reference_number'] ?? ''));
        $notes = trim(lex_sanitize_text($_POST['notes'] ?? ''));

        if ($paymentFor === '') {
            throw new RuntimeException('Tell us what this payment is for.');
        }
        $stmt = $pdo->prepare(
            'SELECT l.id, l.user_id, u.full_name
             FROM lawyers l
             JOIN users u ON u.id = l.user_id
             WHERE l.id = :id
               AND u.is_active = 1
               AND l.status IN ("active", "busy")
               AND l.gcash_account_name IS NOT NULL
               AND l.gcash_account_name <> ""
               AND l.gcash_number IS NOT NULL
               AND l.gcash_number <> ""
               AND l.gcash_qr_stored_name IS NOT NULL
               AND l.gcash_qr_stored_name <> ""
             LIMIT 1'
        );
        $stmt->execute(['id' => $lawyerId]);
        $targetLawyer = $stmt->fetch();
        if (!$targetLawyer) {
            throw new RuntimeException('Choose a lawyer with an active payment QR.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Enter a valid payment amount.');
        }
        if ($payerName === '') {
            $payerName = (string) ($user['full_name'] ?? '');
        }

        $proof = lex_store_payment_proof($_FILES['payment_proof'] ?? []);

        $stmt = $pdo->prepare(
            'INSERT INTO manual_payments
                (client_id, lawyer_id, payment_channel, payment_for, amount, currency, payer_name, payer_contact, reference_number, notes, proof_original_name, proof_stored_name, proof_mime_type, proof_size, status)
             VALUES
                (:client_id, :lawyer_id, "gcash", :payment_for, :amount, "PHP", :payer_name, :payer_contact, :reference_number, :notes, :proof_original_name, :proof_stored_name, :proof_mime_type, :proof_size, "pending")'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'lawyer_id' => (int) $targetLawyer['id'],
            'payment_for' => $paymentFor,
            'amount' => number_format($amount, 2, '.', ''),
            'payer_name' => $payerName,
            'payer_contact' => $payerContact,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'proof_original_name' => $proof['original_name'],
            'proof_stored_name' => $proof['stored_name'],
            'proof_mime_type' => $proof['mime_type'],
            'proof_size' => $proof['size'],
        ]);

        $paymentId = (string) $pdo->lastInsertId();
        lex_audit('submit_manual_payment', 'manual_payments', $paymentId);

        lex_notify((int) $targetLawyer['user_id'], 'payment', 'New GCash payment proof submitted by ' . (string) ($user['full_name'] ?? 'a client') . '.');

        lex_flash_set('success', 'Payment proof submitted. Lawyer verification is now pending.');
        header('Location: ' . lex_app_url('client/payments.php'));
        exit;
    } catch (Throwable $e) {
        if (!empty($proof['path']) && is_file((string) $proof['path'])) {
            @unlink((string) $proof['path']);
        }
        lex_flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to submit your payment proof right now.');
        header('Location: ' . lex_app_url('client/payments.php'));
        exit;
    }
}

$clientProfile = lex_recent(
    'SELECT contact_number FROM clients WHERE id = :id LIMIT 1',
    ['id' => $clientId]
);
$defaultContact = (string) ($clientProfile[0]['contact_number'] ?? '');

$payments = lex_recent(
    'SELECT mp.*, ru.full_name AS reviewed_by_name, lu.full_name AS lawyer_name
     FROM manual_payments mp
     LEFT JOIN lawyers l ON l.id = mp.lawyer_id
     LEFT JOIN users lu ON lu.id = l.user_id
     LEFT JOIN users ru ON ru.id = mp.reviewed_by_user_id
     WHERE mp.client_id = :client_id
     ORDER BY mp.created_at DESC',
    ['client_id' => $clientId]
);
$paymentCount = count($payments);
$paymentAccountOptions = array_map(static fn (array $lawyer): array => [
    'id' => (int) $lawyer['id'],
    'name' => (string) $lawyer['full_name'],
    'account' => (string) $lawyer['gcash_account_name'],
    'number' => (string) $lawyer['gcash_number'],
    'qrUrl' => lex_app_url('payment_qr_image.php?lawyer_id=' . (int) $lawyer['id']),
], $lawyerPaymentAccounts);

lex_page_header('Payments', 'payments', $user);
?>
<section class="payment-shell">
  <script type="application/json" id="lawyer-payment-accounts"><?= json_encode($paymentAccountOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
  <section class="card payment-hero-card">
    <div class="payment-hero-copy">
      <h2>Submit Your Payment</h2>
      <p class="muted">Choose your lawyer, scan their GCash QR, then upload your receipt for lawyer verification.</p>
    </div>
    <div class="payment-progress" aria-label="Payment steps">
      <span class="payment-progress-pill is-done"><strong>1</strong> Order placed</span>
      <span class="payment-progress-pill is-active"><strong>2</strong> Send payment</span>
      <span class="payment-progress-pill"><strong>3</strong> Lawyer verifies</span>
    </div>
  </section>

  <div class="payment-layout">
    <div class="payment-main">
      <section class="card payment-page-card payment-instructions-card">
        <div class="card-head payment-card-head">
          <div>
            <h3>How it works</h3>
            <p class="muted payment-section-copy">3 simple steps to complete your payment</p>
          </div>
        </div>

        <div class="payment-step-list">
          <article class="payment-step-card">
            <span class="payment-step-number">1</span>
            <div>
              <strong>Choose the lawyer and scan their QR</strong>
              <p>Open your GCash app and scan the QR code on the right, or manually send to the listed lawyer number.</p>
            </div>
          </article>
          <article class="payment-step-card">
            <span class="payment-step-number">2</span>
            <div>
              <strong>Complete the payment in your GCash app</strong>
              <p>Enter the exact amount and confirm. Note the reference number shown after success.</p>
            </div>
          </article>
          <article class="payment-step-card">
            <span class="payment-step-number">3</span>
            <div>
              <strong>Upload your screenshot or PDF receipt</strong>
              <p>Use the form below to submit your proof so your lawyer can verify and confirm your payment.</p>
            </div>
          </article>
        </div>

        <div class="payment-note-box">
          <?php if ($gcashInstructions !== ''): ?>
            <?= nl2br(lex_e($gcashInstructions)) ?>
          <?php else: ?>
            Optional notes for the lawyer reviewer can be added in the form below if you need to explain the transfer.
          <?php endif; ?>
        </div>
      </section>

      <section class="card payment-page-card payment-form-card">
        <div class="card-head payment-card-head">
          <div>
            <h3>Payment Details</h3>
            <p class="muted payment-section-copy">Fill in payer info and attach your receipt.</p>
          </div>
        </div>

        <?php if ($gcashReady): ?>
          <form method="post" enctype="multipart/form-data" class="form-grid payment-form">
            <?= lex_csrf_field() ?>
            <label class="full">Lawyer to pay
              <select name="lawyer_id" required data-lawyer-payment-select>
                <?php foreach ($lawyerPaymentAccounts as $paymentLawyer): ?>
                  <option value="<?= (int) $paymentLawyer['id'] ?>"<?= (int) $paymentLawyer['id'] === $selectedLawyerId ? ' selected' : '' ?>><?= lex_e((string) $paymentLawyer['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="full">Payment for
              <input type="text" name="payment_for" placeholder="e.g. Consultation fee, retainer, filing fee..." required>
            </label>
            <label>Payer name
              <input type="text" name="payer_name" value="<?= lex_e((string) ($user['full_name'] ?? '')) ?>" required>
            </label>
            <label>Payer contact
              <input type="text" name="payer_contact" value="<?= lex_e($defaultContact) ?>" placeholder="09XXXXXXXXX">
            </label>
            <label>Amount (PHP)
              <input type="number" name="amount" min="0.01" step="0.01" placeholder="1500.00" required>
            </label>
            <label>GCash reference no.
              <input type="text" name="reference_number" placeholder="Optional, from GCash receipt">
            </label>
            <label class="full">Proof of payment
              <div class="payment-upload-box">
                <input class="payment-file-input" type="file" name="payment_proof" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf" required data-payment-proof-input>
                <div class="payment-upload-copy">
                  <strong>Drop file here or click to browse</strong>
                  <span>JPG • PNG • WEBP • PDF • Max 8 MB</span>
                </div>
              </div>
              <div class="payment-file-preview" data-payment-proof-preview hidden>
                <span class="payment-file-preview-icon" aria-hidden="true"></span>
                <span class="payment-file-preview-copy">
                  <strong data-payment-proof-name></strong>
                  <small data-payment-proof-size></small>
                </span>
                <button class="payment-file-remove" type="button" aria-label="Remove selected proof" data-payment-proof-remove>&times;</button>
              </div>
            </label>
            <label class="full">Notes
              <textarea name="notes" rows="3" placeholder="Optional note for the lawyer reviewer"></textarea>
            </label>
            <div class="alert payment-security-note">
              Security note: Please upload a valid, unedited GCash receipt screenshot. Submitting false or altered proof will result in immediate account restriction.
            </div>
            <button class="button button-primary payment-submit-button" type="submit">Upload a receipt to submit</button>
          </form>
        <?php else: ?>
          <div class="alert alert-error">This form will be enabled once at least one lawyer uploads a GCash QR and account details.</div>
        <?php endif; ?>
      </section>
    </div>

    <aside class="payment-sidebar">
      <section class="card payment-side-card">
        <div class="payment-side-top">
          <span class="payment-side-label">Pay via GCash</span>
          <strong class="payment-side-amount">Manual transfer</strong>
          <p><?= $gcashReady ? 'Scan the selected lawyer QR or send to the account below, then upload your proof.' : 'GCash details will appear here once a lawyer configures them.' ?></p>
        </div>

        <?php if ($gcashReady): ?>
          <div class="payment-qr-wrap compact">
            <img class="payment-qr-image" src="<?= lex_e(lex_app_url('payment_qr_image.php?lawyer_id=' . $selectedLawyerId)) ?>" alt="Selected lawyer GCash QR code" data-lawyer-payment-qr>
          </div>

          <dl class="payment-side-facts">
            <div>
              <dt>Account name</dt>
              <dd data-lawyer-payment-account><?= lex_e((string) ($selectedLawyer['gcash_account_name'] ?? '')) ?></dd>
            </div>
            <div>
              <dt>GCash number</dt>
              <dd data-lawyer-payment-number><?= lex_e((string) ($selectedLawyer['gcash_number'] ?? '')) ?></dd>
            </div>
            <div>
              <dt>Lawyer</dt>
              <dd data-lawyer-payment-name><?= lex_e((string) ($selectedLawyer['full_name'] ?? '')) ?></dd>
            </div>
            <div>
              <dt>Channel</dt>
              <dd>GCash manual transfer <span class="payment-inline-badge">Active</span></dd>
            </div>
          </dl>
        <?php else: ?>
          <div class="alert alert-error">No lawyer GCash payment details have been configured yet.</div>
        <?php endif; ?>
      </section>
    </aside>
  </div>

  <section class="card payment-page-card payment-history-card">
    <div class="card-head payment-card-head">
      <div>
        <h3>Payment History</h3>
        <p class="muted payment-section-copy">Track submissions and review status.</p>
      </div>
      <span class="payment-history-badge"><?= (int) $paymentCount ?> <?= $paymentCount === 1 ? 'record' : 'records' ?></span>
    </div>

    <div class="table-wrap payment-history-wrap">
      <table class="data-table payment-history-table">
        <thead>
          <tr>
            <th>Date &amp; time</th>
            <th>For</th>
            <th>Lawyer</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Proof</th>
            <th>Review note</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($payments): ?>
            <?php foreach ($payments as $payment): ?>
              <tr>
                <td data-label="Date &amp; time"><?= lex_e(date('M j, Y g:i A', strtotime((string) $payment['created_at']))) ?></td>
                <td data-label="For"><?= lex_e((string) $payment['payment_for']) ?></td>
                <td data-label="Lawyer"><?= !empty($payment['lawyer_name']) ? lex_e((string) $payment['lawyer_name']) : '<span class="muted">Unassigned</span>' ?></td>
                <td data-label="Amount" class="payment-amount-cell">PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></td>
                <td data-label="Status"><span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span></td>
                <td data-label="Proof"><a class="payment-download-link" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'] . '&download=1')) ?>">Download</a></td>
                <td data-label="Admin note">
                  <?php if (!empty($payment['admin_notes'])): ?>
                    <?= lex_e((string) $payment['admin_notes']) ?>
                  <?php elseif (!empty($payment['reviewed_by_name'])): ?>
                    Reviewed by <?= lex_e((string) $payment['reviewed_by_name']) ?>
                  <?php else: ?>
                    <span class="muted">Waiting for lawyer review</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="muted">No payments submitted yet.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="client-payment-history-mobile" aria-label="Payment history cards">
      <?php if ($payments): ?>
        <?php foreach ($payments as $payment): ?>
          <article class="client-payment-mobile-card">
            <div class="client-payment-mobile-top">
              <div class="client-payment-mobile-title">
                <span class="client-payment-mobile-icon" aria-hidden="true">₱</span>
                <div>
                  <strong><?= lex_e((string) $payment['payment_for']) ?></strong>
                  <span><?= lex_e(date('M j, Y · g:i A', strtotime((string) $payment['created_at']))) ?></span>
                </div>
              </div>
              <span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span>
            </div>
            <div class="client-payment-mobile-amount">
              <span>AMOUNT</span>
              <strong>PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></strong>
            </div>
            <div class="client-payment-mobile-grid">
              <div><span>LAWYER</span><strong><?= !empty($payment['lawyer_name']) ? lex_e((string) $payment['lawyer_name']) : 'Unassigned' ?></strong></div>
              <div><span>REFERENCE</span><strong><?= (string) ($payment['reference_number'] ?? '') !== '' ? lex_e((string) $payment['reference_number']) : 'None' ?></strong></div>
            </div>
            <div class="client-payment-mobile-footer">
              <div>
                <span>REVIEW</span>
                <strong><?= !empty($payment['admin_notes']) ? lex_e((string) $payment['admin_notes']) : (!empty($payment['reviewed_by_name']) ? 'Reviewed by ' . lex_e((string) $payment['reviewed_by_name']) : 'Waiting for review') ?></strong>
              </div>
              <a class="button button-secondary" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'] . '&download=1')) ?>">Proof</a>
            </div>
          </article>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="client-payment-mobile-empty">No payments submitted yet.</div>
      <?php endif; ?>
    </div>
  </section>
</section>
<script>
(() => {
  const select = document.querySelector('[data-lawyer-payment-select]');
  const dataEl = document.getElementById('lawyer-payment-accounts');
  if (select && dataEl) {
    const accounts = JSON.parse(dataEl.textContent || '[]');
    const qr = document.querySelector('[data-lawyer-payment-qr]');
    const account = document.querySelector('[data-lawyer-payment-account]');
    const number = document.querySelector('[data-lawyer-payment-number]');
    const name = document.querySelector('[data-lawyer-payment-name]');
    const render = () => {
      const selected = accounts.find((item) => String(item.id) === String(select.value));
      if (!selected) return;
      if (qr) qr.src = selected.qrUrl;
      if (account) account.textContent = selected.account;
      if (number) number.textContent = selected.number;
      if (name) name.textContent = selected.name;
    };
    select.addEventListener('change', render);
  }

  const proofInput = document.querySelector('[data-payment-proof-input]');
  const proofPreview = document.querySelector('[data-payment-proof-preview]');
  const proofName = document.querySelector('[data-payment-proof-name]');
  const proofSize = document.querySelector('[data-payment-proof-size]');
  const proofRemove = document.querySelector('[data-payment-proof-remove]');
  if (!proofInput || !proofPreview || !proofName || !proofSize || !proofRemove) return;

  const formatSize = (size) => {
    if (!Number.isFinite(size) || size <= 0) return '0 KB';
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`;
    return `${(size / 1024 / 1024).toFixed(1)} MB`;
  };

  const syncProofPreview = () => {
    const file = proofInput.files && proofInput.files[0] ? proofInput.files[0] : null;
    if (!file) {
      proofPreview.hidden = true;
      proofName.textContent = '';
      proofSize.textContent = '';
      return;
    }

    proofName.textContent = file.name;
    proofSize.textContent = formatSize(file.size);
    proofPreview.hidden = false;
  };

  proofInput.addEventListener('change', syncProofPreview);
  proofRemove.addEventListener('click', () => {
    proofInput.value = '';
    syncProofPreview();
    proofInput.focus();
  });
})();
</script>
<?php lex_page_footer(); ?>
