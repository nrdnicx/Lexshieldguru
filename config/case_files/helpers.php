<?php
declare(strict_types=1);

function lex_case_files_seed_from_cases(PDO $pdo): void
{
    $seedCount = lex_stats('SELECT COUNT(*) FROM case_files');
    if ($seedCount > 0) {
        return;
    }

    $seedCases = lex_recent(
        'SELECT c.case_number, c.title, c.description, c.status, c.filed_date,
                cu.user_id AS client_user_id, cuu.full_name AS full_name,
                lu.user_id AS lawyer_user_id
         FROM cases c
         JOIN clients cu ON cu.id = c.client_id
         JOIN users cuu ON cuu.id = cu.user_id
         JOIN lawyers lu ON lu.id = c.lawyer_id
         ORDER BY c.id ASC'
    );

    foreach ($seedCases as $seed) {
        $fullName = (string) $seed['full_name'];
        $caseTitle = (string) $seed['title'];
        $caseNumber = (string) $seed['case_number'];
        $folderName = lex_case_files_folder_name($fullName, $caseTitle, $caseNumber);
        $pdo->prepare(
            'INSERT INTO case_files
                (full_name, case_identifier, case_file_title, description, date_created, client_user_id, assigned_lawyer_user_id, status, folder_name, attachments_json, created_by_user_id, updated_by_user_id)
             VALUES
                (:full_name, :case_identifier, :case_file_title, :description, :date_created, :client_user_id, :assigned_lawyer_user_id, :status, :folder_name, :attachments_json, :created_by_user_id, :updated_by_user_id)'
        )->execute([
            'full_name' => $fullName,
            'case_identifier' => $caseNumber,
            'case_file_title' => $caseTitle,
            'description' => (string) $seed['description'],
            'date_created' => (string) $seed['filed_date'],
            'client_user_id' => (int) $seed['client_user_id'],
            'assigned_lawyer_user_id' => (int) $seed['lawyer_user_id'],
            'status' => in_array((string) $seed['status'], ['open', 'ongoing', 'closed'], true) ? $seed['status'] : 'open',
            'folder_name' => $folderName,
            'attachments_json' => '[]',
            'created_by_user_id' => (int) $seed['lawyer_user_id'],
            'updated_by_user_id' => (int) $seed['lawyer_user_id'],
        ]);

        $seedId = (int) $pdo->lastInsertId();
        $record = lex_recent(
            'SELECT cf.*, lu.full_name AS assigned_lawyer_name, cu.full_name AS client_name, cu.email AS client_email, cb.full_name AS created_by_name, ub.full_name AS updated_by_name
             FROM case_files cf
             JOIN users cu ON cu.id = cf.client_user_id
             LEFT JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
             LEFT JOIN users cb ON cb.id = cf.created_by_user_id
             LEFT JOIN users ub ON ub.id = cf.updated_by_user_id
             WHERE cf.id = :id LIMIT 1',
            ['id' => $seedId]
        )[0] ?? null;

        if ($record) {
            lex_case_files_sync_record($record);
        }
    }
}

function lex_case_files_status_label(string $status): string
{
    return match ($status) {
        'open' => 'Open',
        'ongoing' => 'Ongoing',
        'closed' => 'Closed',
        default => ucfirst($status),
    };
}

function lex_case_files_status_class(string $status): string
{
    return match ($status) {
        'open' => 'is-open',
        'ongoing' => 'is-ongoing',
        'closed' => 'is-closed',
        default => 'is-default',
    };
}

function lex_case_files_highlight(string $value, string $query): string
{
    $safeValue = lex_e($value);
    $safeQuery = trim(lex_e($query));
    if ($safeQuery === '') {
        return $safeValue;
    }

    $pattern = '/' . preg_quote($safeQuery, '/') . '/i';
    return (string) preg_replace($pattern, '<mark>$0</mark>', $safeValue);
}

function lex_case_files_parse_attachments(?string $json): array
{
    $attachments = json_decode((string) $json, true);
    return is_array($attachments) ? $attachments : [];
}

function lex_case_files_format_size(int $size): string
{
    if ($size <= 0) {
        return '0 KB';
    }

    if ($size < 1024) {
        return $size . ' B';
    }

    if ($size < 1024 * 1024) {
        return round($size / 1024) . ' KB';
    }

    return round($size / 1024 / 1024, 1) . ' MB';
}

function lex_case_files_build_filters(array $filters, array &$params): string
{
    $where = [];

    if (!empty($filters['is_client'])) {
        $where[] = 'cf.client_user_id = :current_client_user_id';
        $params['current_client_user_id'] = (int) $filters['current_client_user_id'];
    } elseif (!empty($filters['is_lawyer'])) {
        $where[] = '(cf.created_by_user_id = :current_lawyer_user_id_created OR cf.assigned_lawyer_user_id = :current_lawyer_user_id_assigned)';
        $params['current_lawyer_user_id_created'] = (int) $filters['current_lawyer_user_id'];
        $params['current_lawyer_user_id_assigned'] = (int) $filters['current_lawyer_user_id'];
    }

    if (!empty($filters['search'])) {
        $where[] = '(cf.full_name LIKE :search1 OR cf.case_file_title LIKE :search2 OR cf.case_identifier LIKE :search3 OR cu.full_name LIKE :search4)';
        $searchValue = '%' . $filters['search'] . '%';
        $params['search1'] = $searchValue;
        $params['search2'] = $searchValue;
        $params['search3'] = $searchValue;
        $params['search4'] = $searchValue;
    }

    if (!empty($filters['status']) && in_array($filters['status'], ['open', 'ongoing', 'closed'], true)) {
        $where[] = 'cf.status = :status';
        $params['status'] = $filters['status'];
    }

    return $where ? ' WHERE ' . implode(' AND ', $where) : '';
}

function lex_case_files_fetch_state(array $filters, int $pageSize): array
{
    $pdo = lex_pdo();
    $sortMap = [
        'updated_at' => 'cf.updated_at',
        'date_created' => 'cf.date_created',
        'full_name' => 'cf.full_name',
        'case_file_title' => 'cf.case_file_title',
        'status' => 'cf.status',
    ];
    $sortColumn = $sortMap[$filters['sort']] ?? 'cf.updated_at';
    $sortDirection = $filters['dir'] === 'asc' ? 'ASC' : 'DESC';

    $params = [];
    $where = lex_case_files_build_filters($filters, $params);

    $baseSelect = ' FROM case_files cf
        JOIN users cu ON cu.id = cf.client_user_id
        LEFT JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
        LEFT JOIN users cb ON cb.id = cf.created_by_user_id
        LEFT JOIN users ub ON ub.id = cf.updated_by_user_id';

    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $baseSelect . $where);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $pageCount = max(1, (int) ceil(max(1, $total) / max(1, $pageSize)));
    $page = min(max(1, $filters['page']), $pageCount);
    $offset = ($page - 1) * $pageSize;

    $listParams = $params;
    $listSql = 'SELECT cf.*, cu.full_name AS client_name, cu.email AS client_email, cu.avatar_stored_name AS client_avatar_stored_name, lu.full_name AS assigned_lawyer_name,
                       cb.full_name AS created_by_name, ub.full_name AS updated_by_name'
        . $baseSelect
        . $where
        . ' ORDER BY ' . $sortColumn . ' ' . $sortDirection
        . ' LIMIT ' . (int) $pageSize
        . ' OFFSET ' . (int) $offset;
    $records = lex_recent($listSql, $listParams);

    $selectedRecord = null;
    $selectedError = '';
    if (!empty($filters['record'])) {
        $selectedParams = $params;
        $selectedParams['record_id'] = (int) $filters['record'];
        $selectedWhere = $where . ($where ? ' AND ' : ' WHERE ') . 'cf.id = :record_id';
        $selectedStmt = $pdo->prepare('SELECT cf.*, cu.full_name AS client_name, cu.email AS client_email, cu.avatar_stored_name AS client_avatar_stored_name, lu.full_name AS assigned_lawyer_name,
                                               cb.full_name AS created_by_name, ub.full_name AS updated_by_name'
            . $baseSelect
            . $selectedWhere
            . ' LIMIT 1');
        $selectedStmt->execute($selectedParams);
        $selectedRecord = $selectedStmt->fetch() ?: null;
        if (!$selectedRecord) {
            $selectedError = 'Case file not found.';
        }
    }

    if (!$selectedRecord && !empty($records[0])) {
        $selectedRecord = $records[0];
    }

    $activityStmt = $pdo->prepare('SELECT cf.id, cf.full_name, cf.case_file_title, cf.status, cf.updated_at, cf.date_created,
                                          lu.full_name AS assigned_lawyer_name, cu.full_name AS client_name
                                   ' . $baseSelect . $where . '
                                   ORDER BY cf.updated_at DESC
                                   LIMIT 5');
    $activityStmt->execute($params);
    $activity = $activityStmt->fetchAll();

    $countsStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN cf.status = "open" THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN cf.status = "ongoing" THEN 1 ELSE 0 END) AS ongoing_count,
            SUM(CASE WHEN cf.status = "closed" THEN 1 ELSE 0 END) AS closed_count
         ' . $baseSelect . $where
    );
    $countsStmt->execute($params);
    $counts = $countsStmt->fetch() ?: ['open_count' => 0, 'ongoing_count' => 0, 'closed_count' => 0];

    return [
        'records' => $records,
        'selected' => $selectedRecord,
        'selected_error' => $selectedError,
        'activity' => $activity,
        'counts' => [
            'total' => $total,
            'open' => (int) ($counts['open_count'] ?? 0),
            'ongoing' => (int) ($counts['ongoing_count'] ?? 0),
            'closed' => (int) ($counts['closed_count'] ?? 0),
        ],
        'page' => $page,
        'page_count' => $pageCount,
        'page_size' => $pageSize,
        'sort' => $filters['sort'],
        'dir' => $filters['dir'],
        'search' => $filters['search'],
        'status' => $filters['status'],
        'is_client' => !empty($filters['is_client']),
    ];
}

function lex_case_files_render_status_pill(string $status): string
{
    $class = lex_case_files_status_class($status);
    return '<span class="pill status-pill ' . lex_e($class) . '">' . lex_e(lex_case_files_status_label($status)) . '</span>';
}

function lex_case_files_render_upload_status(string $status): string
{
    $label = match ($status) {
        'pending' => 'Pending approval',
        'rejected' => 'Rejected',
        default => 'Approved',
    };
    return '<span class="pill vault-status vault-status-' . lex_e($status) . '">' . lex_e($label) . '</span>';
}

function lex_case_files_is_default_vault_folder(string $slug): bool
{
    return in_array($slug, array_map('lex_case_file_vault_slug', lex_case_file_vault_default_folders()), true);
}

function lex_case_files_client_initials(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'CF';
}

function lex_case_files_vault_folder_description(array $folder, bool $isClient): string
{
    $slug = (string) ($folder['slug'] ?? '');
    return match ($slug) {
        'CLIENT_UPLOADS' => $isClient ? 'Files you submit for lawyer review.' : 'Files submitted by the client for review.',
        'CORRESPONDENCE' => 'Emails, letters, and written communications.',
        'COURT_FILINGS' => 'Motions, pleadings, and court submissions.',
        'DOCUMENTS' => 'General case documents and records.',
        'EVIDENCE' => 'Supporting evidence and exhibits.',
        'PHOTOS' => 'Images and photographic evidence.',
        default => 'Organized case materials stored in this folder.',
    };
}

function lex_case_files_render_summary(array $state): string
{
    ob_start();
    ?>
    <section class="kpi-grid case-stats" aria-label="Case file summary">
      <article class="kpi-card case-stat-card is-total">
        <span class="case-stat-label"><i class="case-stat-icon" aria-hidden="true"><?= lex_case_files_summary_icon('total') ?></i><em>Total case files</em></span>
        <strong><?= number_format((int) $state['counts']['total']) ?></strong>
      </article>
      <article class="kpi-card case-stat-card is-open">
        <span class="case-stat-label"><i class="case-stat-icon" aria-hidden="true"><?= lex_case_files_summary_icon('open') ?></i><em>Open</em></span>
        <strong><?= number_format((int) $state['counts']['open']) ?></strong>
      </article>
      <article class="kpi-card case-stat-card is-ongoing">
        <span class="case-stat-label"><i class="case-stat-icon" aria-hidden="true"><?= lex_case_files_summary_icon('ongoing') ?></i><em>Ongoing</em></span>
        <strong><?= number_format((int) $state['counts']['ongoing']) ?></strong>
      </article>
      <article class="kpi-card case-stat-card is-closed">
        <span class="case-stat-label"><i class="case-stat-icon" aria-hidden="true"><?= lex_case_files_summary_icon('closed') ?></i><em>Closed</em></span>
        <strong><?= number_format((int) $state['counts']['closed']) ?></strong>
      </article>
    </section>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_list(array $state, array $filters): string
{
    $records = $state['records'];
    $search = (string) $state['search'];
    $selectedId = (int) (($state['selected']['id'] ?? 0) ?: 0);
    ob_start();
    ?>
    <article class="card case-panel case-list-panel case-compact-panel">
      <div class="card-head case-panel-header case-compact-header">
        <div>
          <h2>Case File List</h2>
          
        </div>
        <span class="pill"><?= number_format((int) $state['counts']['total']) ?> records</span>
      </div>

      <div class="table-wrap case-table-wrap">
        <table class="data-table case-list-table">
          <thead>
            <tr>
              <th>Full name</th>
              <th>CASE FILE</th>
              <th>Status</th>
              <th>Assigned Lawyer</th>
              <th>Date Created</th>
              <th class="case-table-action-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($records)): ?>
              <tr>
                <td colspan="5">
                  <div class="case-empty-state">
                    <strong>No case files yet.</strong>
                    <p class="muted">Create the first file or loosen your filters.</p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($records as $record): ?>
                <?php
                  $recordId = (int) $record['id'];
                  $isSelected = $recordId === $selectedId;
                ?>
                <tr class="<?= $isSelected ? 'is-active' : '' ?>" data-case-row data-case-id="<?= $recordId ?>">
                  <td>
                    <button class="case-folder-link" type="button" data-case-open-vault data-case-id="<?= $recordId ?>">
                      <?= lex_case_files_highlight((string) $record['full_name'], $search) ?>
                    </button>
                  </td>
                  <td>
                    <div class="case-file-cell">
                      <strong><?= lex_case_files_highlight((string) $record['case_file_title'], $search) ?></strong>
                      <span class="muted">#<?= lex_e((string) $record['case_identifier']) ?></span>
                    </div>
                  </td>
                  <td><?= lex_case_files_render_status_pill((string) $record['status']) ?></td>
                  <td><?= lex_e((string) ($record['assigned_lawyer_name'] ?: 'Unassigned')) ?></td>
                  <td><?= lex_e((string) $record['date_created']) ?></td>
                  <td class="case-table-action-col">
                    <div class="case-row-actions">
                      <button class="button button-secondary case-select-button" type="button" data-case-open-vault data-case-id="<?= $recordId ?>" aria-label="Open folder for <?= lex_e((string) $record['full_name']) ?>" title="Open folder">
                        <span aria-hidden="true"><?= lex_case_files_action_icon('folder') ?></span>
                      </button>
                      <?php if (!empty($filters['is_lawyer'])): ?>
                        <form method="post" class="case-inline-delete-form" data-persist-form="casefile-delete-row-<?= $recordId ?>">
                          <?= lex_csrf_field() ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="case_file_id" value="<?= $recordId ?>">
                          <button class="button button-danger case-delete-button" type="submit" aria-label="Delete case file for <?= lex_e((string) $record['full_name']) ?>" title="Delete case file" data-confirm="Permanently delete this case file, its vault folders, documents, attachments, and metadata? This cannot be undone." data-confirm-text="CONFIRM">
                            <span aria-hidden="true"><?= lex_case_files_action_icon('delete') ?></span>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="case-pagination" data-case-pagination-container>
        <?php echo lex_case_files_render_pagination($state); ?>
      </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_pagination(array $state): string
{
    $page = (int) $state['page'];
    $pageCount = (int) $state['page_count'];
    $total = (int) $state['counts']['total'];
    $pageSize = max(1, (int) $state['page_size']);
    $start = $total > 0 ? (($page - 1) * $pageSize) + 1 : 0;
    $end = min($total, $page * $pageSize);

    $buttons = [];
    $prevDisabled = $page <= 1 ? ' aria-disabled="true" disabled' : '';
    $nextDisabled = $page >= $pageCount ? ' aria-disabled="true" disabled' : '';
    $buttons[] = '<button class="pagination-box pagination-arrow" type="button" data-case-page="' . max(1, $page - 1) . '"' . $prevDisabled . '>&lsaquo;</button>';
    for ($i = 1; $i <= $pageCount; $i++) {
        $isActive = $i === $page ? ' is-active' : '';
        $buttons[] = '<button class="pagination-box' . $isActive . '" type="button" data-case-page="' . $i . '">' . $i . '</button>';
    }
    $buttons[] = '<button class="pagination-box pagination-arrow" type="button" data-case-page="' . min($pageCount, $page + 1) . '"' . $nextDisabled . '>&rsaquo;</button>';

    $info = $total > 0
        ? 'Showing ' . number_format($start) . '-' . number_format($end) . ' of ' . number_format($total)
        : 'No results';

    return '<div class="case-pagination-bar"><span class="page-info">' . $info . '</span><div class="page-nav">' . implode('', $buttons) . '</div></div>';
}

function lex_case_files_summary_icon(string $type): string
{
    return match ($type) {
        'open' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M4.75 5.5h4.34c.39 0 .76.15 1.03.42l1.1 1.08c.14.14.32.21.51.21h7.52c.41 0 .75.34.75.75v8.79A2.25 2.25 0 0 1 17.75 19H6.25A2.25 2.25 0 0 1 4 16.75V6.25c0-.41.34-.75.75-.75Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
        'ongoing' => '<svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="7.25" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 8.2v4.4l2.8 1.7" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'closed' => '<svg viewBox="0 0 24 24" focusable="false"><rect x="4.75" y="6.5" width="14.5" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8.2 6V5.6A2.8 2.8 0 0 1 11 2.8h2A2.8 2.8 0 0 1 15.8 5.6V6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
        default => '<svg viewBox="0 0 24 24" focusable="false"><path d="M5.25 4.75h4.2c.43 0 .84.17 1.15.48l.98.97c.16.16.37.25.6.25h6.57c.69 0 1.25.56 1.25 1.25v10.05c0 .83-.67 1.5-1.5 1.5H5.5c-.83 0-1.5-.67-1.5-1.5V6.25c0-.83.67-1.5 1.5-1.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    };
}

function lex_case_files_action_icon(string $type): string
{
    return match ($type) {
        'delete' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M8.5 5.5h7M9.5 5.5v-1a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v1M7.25 8.25v9a1.5 1.5 0 0 0 1.5 1.5h6.5a1.5 1.5 0 0 0 1.5-1.5v-9M10 10.5v5.25M14 10.5v5.25" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        default => '<svg viewBox="0 0 24 24" focusable="false"><path d="M4.75 5.5h4.34c.39 0 .76.15 1.03.42l1.1 1.08c.14.14.32.21.51.21h7.52c.41 0 .75.34.75.75v8.79A2.25 2.25 0 0 1 17.75 19H6.25A2.25 2.25 0 0 1 4 16.75V6.25c0-.41.34-.75.75-.75Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    };
}

function lex_case_files_render_vault(array $record, array $filters, array $user): string
{
    $vault = lex_case_file_vault_fetch((int) $record['id'], $user);
    $folders = $vault['folders'];
    $documents = $vault['documents'];
    $documentsByFolder = [];
    foreach ($documents as $document) {
        $documentsByFolder[(int) $document['folder_id']][] = $document;
    }
    $isLawyer = !empty($filters['is_lawyer']);
    $isClient = !empty($filters['is_client']);
    $folderCount = count($folders);
    $documentCount = count($documents);
    $pendingCount = 0;
    foreach ($folders as $folder) {
        $pendingCount += (int) ($folder['pending_count'] ?? 0);
    }
    $clientAvatarUrl = lex_profile_avatar_url((string) ($record['client_avatar_stored_name'] ?? ''));
    $clientName = (string) ($record['full_name'] ?? $record['client_name'] ?? 'Client');
    ob_start();
    ?>
    <div class="case-vault-shell">
      <div class="case-vault-topbar case-vault-overview">
        <div class="case-vault-heading">
          <div class="case-vault-identity">
            <?php if ($clientAvatarUrl !== ''): ?>
              <img class="case-vault-avatar" src="<?= lex_e($clientAvatarUrl) ?>" alt="Avatar for <?= lex_e($clientName) ?>">
            <?php else: ?>
              <span class="case-vault-avatar"><?= lex_e(lex_case_files_client_initials($clientName)) ?></span>
            <?php endif; ?>
            <div class="case-vault-identity-copy">
              <h3><?= lex_e($clientName) ?></h3>
              <p>#<?= lex_e((string) $record['case_identifier']) ?></p>
              <div class="case-vault-chip-row">
                <?= lex_case_files_render_status_pill((string) $record['status']) ?>
                <span class="pill case-vault-chip"><?= lex_e((string) $record['case_file_title']) ?></span>
                <span class="pill case-vault-chip">Shared workspace</span>
              </div>
            </div>
          </div>
        </div>
        <div class="case-vault-summary-strip" aria-label="Vault summary">
          <div class="case-vault-summary-actions">
            <button class="button button-secondary" type="button" data-case-select data-case-id="<?= (int) $record['id'] ?>">Open details</button>
          </div>
          <div class="case-vault-stat">
            <strong><?= $folderCount ?></strong>
            <span>Folders</span>
          </div>
          <div class="case-vault-stat">
            <strong><?= $documentCount ?></strong>
            <span>Files</span>
          </div>
          <div class="case-vault-stat">
            <strong><?= $pendingCount ?></strong>
            <span>Pending</span>
          </div>
        </div>
      </div>

      <div class="case-vault-grid">
        <div class="case-vault-actions">
          <?php if ($isLawyer): ?>
            <form method="post" class="case-vault-folder-form case-vault-card">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="create_folder">
              <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
              <div class="case-vault-card-head">
                <h4>New folder</h4>
              </div>
              <label>
                <span>Folder name</span>
                <input type="text" name="folder_name" placeholder="e.g. Discovery" maxlength="150" required>
              </label>
              <button class="button button-secondary" type="submit">Create folder</button>
            </form>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data" class="case-vault-upload-form case-vault-card">
            <?= lex_csrf_field() ?>
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
            <div class="case-vault-card-head">
              <h4>Upload document</h4>
            </div>
            <label>
              <span>Destination folder</span>
              <select name="folder_id" required>
                <?php foreach ($folders as $folder): ?>
                  <option value="<?= (int) $folder['id'] ?>"<?= !$isLawyer && (string) $folder['slug'] === 'CLIENT_UPLOADS' ? ' selected' : '' ?>><?= lex_e((string) $folder['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="case-vault-file-input">
              <span class="case-vault-upload-drop">
                <span class="case-vault-upload-drop-title">Choose a file or drag and drop</span>
                <span class="case-vault-upload-drop-copy"><?= $isClient ? 'PDF, DOCX, JPG' : 'Upload approved or reviewable case files' ?></span>
              </span>
              <input type="file" name="vault_document" required>
            </label>
            <button class="button button-primary" type="submit"><?= $isClient ? 'Submit for approval' : 'Upload document' ?></button>
            <p class="case-vault-note"><?= $isClient ? 'Uploads are reviewed by your lawyer before joining the vault.' : 'Pending client uploads stay marked for review until you approve them.' ?></p>
          </form>
        </div>

        <div class="case-vault-documents">
          <div class="case-vault-list-head">
            <h4>Folders</h4>
            <span><?= $folderCount ?> folder<?= $folderCount === 1 ? '' : 's' ?> &middot; <?= $documentCount ?> file<?= $documentCount === 1 ? '' : 's' ?> total</span>
          </div>
          <?php foreach ($folders as $folder): ?>
            <?php $folderDocuments = $documentsByFolder[(int) $folder['id']] ?? []; ?>
            <details class="case-vault-folder-section" id="vault-folder-<?= (int) $folder['id'] ?>">
              <summary class="case-vault-section-head">
                <div class="case-vault-section-summary">
                  <span class="case-vault-section-folder-icon" aria-hidden="true"></span>
                  <div>
                    <h4><?= lex_e((string) $folder['name']) ?></h4>
                    <p class="muted"><?= lex_e(lex_case_files_vault_folder_description($folder, $isClient)) ?></p>
                  </div>
                </div>
                <span class="pill case-vault-folder-count"><?= count($folderDocuments) ?> file<?= count($folderDocuments) === 1 ? '' : 's' ?></span>
              </summary>
              <div class="case-vault-folder-content">
                <?php if ($isLawyer && !lex_case_files_is_default_vault_folder((string) $folder['slug'])): ?>
                  <div class="case-vault-folder-tools">
                    <form method="post" class="case-vault-inline-form">
                      <?= lex_csrf_field() ?>
                      <input type="hidden" name="action" value="delete_folder">
                      <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                      <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                      <button class="button button-danger" type="submit" data-confirm="Delete this folder and all documents inside it?">Delete Folder</button>
                    </form>
                  </div>
                <?php endif; ?>
                <?php if (empty($folderDocuments)): ?>
                  <div class="case-empty-state case-subempty">
                    <strong>No documents in this folder.</strong>
                    <p class="muted"><?= $isClient ? 'Approved files will appear here.' : 'Upload or approve documents to fill this folder.' ?></p>
                  </div>
                <?php else: ?>
                  <div class="case-vault-doc-list">
                    <div class="case-vault-doc-table-head" aria-hidden="true">
                      <span>Name</span>
                      <span>Owner</span>
                      <span>Last modified</span>
                      <span>File size</span>
                      <span></span>
                    </div>
                    <?php foreach ($folderDocuments as $document): ?>
                    <?php
                      $documentUrl = lex_app_url('case_document_file.php?document_id=' . (int) $document['id']);
                      $previewUrl = $documentUrl . '&preview=1';
                      $isPreviewableImage = str_starts_with((string) ($document['mime_type'] ?? ''), 'image/');
                      $hasDocumentMenu = (string) $document['upload_status'] === 'approved' || $isLawyer;
                    ?>
                    <article class="case-vault-doc">
                      <a class="case-vault-doc-thumb" href="<?= lex_e($previewUrl) ?>" target="_blank" rel="noopener" aria-label="Preview <?= lex_e((string) $document['original_name']) ?>">
                        <?php if ($isPreviewableImage): ?>
                          <img src="<?= lex_e($previewUrl) ?>" alt="">
                        <?php else: ?>
                          <span class="case-vault-doc-icon" aria-hidden="true"></span>
                        <?php endif; ?>
                      </a>
                      <div class="case-vault-doc-main">
                        <strong><?= lex_e((string) $document['original_name']) ?></strong>
                        <?php if ((string) $document['upload_status'] === 'rejected' && trim((string) ($document['rejection_reason'] ?? '')) !== ''): ?>
                          <small class="case-vault-rejection-reason">Reason: <?= lex_e((string) $document['rejection_reason']) ?></small>
                        <?php endif; ?>
                      </div>
                      <div class="case-vault-doc-owner">
                        <span class="case-vault-owner-icon" aria-hidden="true"></span>
                        <span><?= lex_e((string) $document['uploaded_by_name']) ?></span>
                      </div>
                      <time class="case-vault-doc-date" datetime="<?= lex_e((string) $document['created_at']) ?>">
                        <strong><?= lex_e(date('M j, Y', strtotime((string) $document['created_at']))) ?></strong>
                        <span><?= lex_e(date('g:i:s A', strtotime((string) $document['created_at']))) ?></span>
                      </time>
                      <span class="case-vault-doc-size"><?= lex_e(lex_case_files_format_size((int) ($document['file_size'] ?? 0))) ?></span>
                        <div class="case-vault-doc-actions">
                          <?php if ((string) $document['upload_status'] !== 'approved'): ?>
                            <?= lex_case_files_render_upload_status((string) $document['upload_status']) ?>
                          <?php endif; ?>
                          <?php if ($hasDocumentMenu): ?>
                            <details class="case-vault-doc-menu">
                              <summary aria-label="Document actions">
                                <span></span><span></span><span></span>
                              </summary>
                              <div class="case-vault-doc-menu-panel">
                                <a href="<?= lex_e($previewUrl) ?>" target="_blank" rel="noopener">Preview</a>
                                <a href="<?= lex_e($documentUrl) ?>">Download</a>
                                <?php if ($isLawyer): ?>
                                  <form method="post" class="case-vault-menu-rename">
                                    <?= lex_csrf_field() ?>
                                    <input type="hidden" name="action" value="rename_document">
                                    <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                                    <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                    <label>
                                      <span>Rename</span>
                                      <input type="text" name="document_name" value="<?= lex_e((string) $document['original_name']) ?>" maxlength="255" required>
                                    </label>
                                    <button type="submit">Save name</button>
                                  </form>
                                  <?php if ((string) $document['upload_status'] === 'pending'): ?>
                                    <form method="post">
                                      <?= lex_csrf_field() ?>
                                      <input type="hidden" name="action" value="approve_document">
                                      <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                                      <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                      <button type="submit">Approve</button>
                                    </form>
                                    <form method="post">
                                      <?= lex_csrf_field() ?>
                                      <input type="hidden" name="action" value="reject_document">
                                      <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                                      <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                      <label class="case-vault-reject-field">
                                        <span>Reject reason</span>
                                        <textarea name="rejection_reason" rows="3" maxlength="500" required placeholder="Example: blurry image or wrong document"></textarea>
                                      </label>
                                      <button class="is-danger" type="submit">Reject</button>
                                    </form>
                                  <?php endif; ?>
                                  <form method="post">
                                    <?= lex_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_document">
                                    <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                                    <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                    <button class="is-danger" type="submit" data-confirm="Delete this vault document?">Delete</button>
                                  </form>
                                <?php endif; ?>
                              </div>
                            </details>
                          <?php endif; ?>
                        </div>
                      </article>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_vault_panel(array $state, array $filters, array $user): string
{
    $record = $state['selected'];
    ob_start();
    ?>
    <article class="card case-panel case-vault-panel case-compact-panel">
      <?php if (!$record): ?>
        <div class="case-vault-panel-empty">
          <div>
            <h2>Document Vault</h2>
            <p class="muted">Select a case file from the list above to open its folders and files.</p>
          </div>
        </div>
      <?php else: ?>
        <?= lex_case_files_render_vault($record, $filters, $user) ?>
      <?php endif; ?>
    </article>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_detail(array $state, array $filters, array $user): string
{
    $record = $state['selected'];
    $error = $state['selected_error'];
    $search = (string) $state['search'];
    ob_start();
    ?>
    <div class="modal-overlay case-detail-modal-overlay" id="caseFileDetailModal" data-case-detail-modal aria-hidden="true">
      <div class="modal-card case-detail-modal wide case-compact-modal" role="dialog" aria-modal="true" aria-labelledby="caseFileDetailTitle">
        <div class="modal-header">
          <div>
            <h2 id="caseFileDetailTitle">Case File Details</h2>
            <p class="modal-note">Clear sections for client information, description, and status.</p>
          </div>
          <button class="close-button" type="button" data-case-detail-close aria-label="Close">&times;</button>
        </div>

        <div class="modal-body case-detail-modal-body">
          <?php if ($error): ?>
            <div class="alert alert-error"><?= lex_e($error) ?></div>
          <?php endif; ?>

          <?php if (!$record): ?>
            <div class="case-empty-state case-detail-empty">
              <strong>No case file selected.</strong>
              <p class="muted">Choose a record from the list to review the details.</p>
            </div>
          <?php else: ?>
            <div class="case-detail-head">
              <div>
                <div class="case-overline">FULLNAME</div>
                <h3><?= lex_case_files_highlight((string) $record['full_name'], $search) ?></h3>
                <p class="muted"><?= lex_case_files_highlight((string) $record['case_file_title'], $search) ?></p>
              </div>
              <div class="case-detail-head-actions">
                <?= lex_case_files_render_status_pill((string) $record['status']) ?>
                <?php if (!empty($filters['is_lawyer'])): ?>
                  <button
                    class="icon-chip case-edit-chip"
                    type="button"
                    data-case-edit-open
                    data-case-id="<?= (int) $record['id'] ?>"
                    data-full-name="<?= lex_e((string) $record['full_name']) ?>"
                    data-case-file-title="<?= lex_e((string) $record['case_file_title']) ?>"
                    data-description="<?= lex_e((string) $record['description']) ?>"
                    data-status="<?= lex_e((string) $record['status']) ?>"
                    data-client-user-id="<?= (int) $record['client_user_id'] ?>"
                    data-assigned-lawyer-user-id="<?= (int) $record['assigned_lawyer_user_id'] ?>"
                    aria-label="Edit Case File"
                    title="Edit Case File"
                  >&#9998;</button>
                <?php endif; ?>
                <button class="button button-secondary" type="button" data-case-detail-close>Back to list</button>
              </div>
            </div>

            <details class="case-accordion" open>
              <summary>Client Info</summary>
              <div class="case-detail-grid">
                <div class="case-info-card"><span>Client Name</span><strong><?= lex_e((string) $record['client_name']) ?></strong></div>
                <div class="case-info-card"><span>Client Email</span><strong><?= lex_e((string) $record['client_email']) ?></strong></div>
                <div class="case-info-card"><span>Assigned Lawyer</span><strong><?= lex_e((string) ($record['assigned_lawyer_name'] ?: 'Unassigned')) ?></strong></div>
                <div class="case-info-card"><span>Case Identifier</span><strong><?= lex_e((string) $record['case_identifier']) ?></strong></div>
              </div>
            </details>

            <details class="case-accordion" open>
              <summary>Case Description</summary>
              <div class="case-body-copy">
                <?= nl2br(lex_e((string) ($record['description'] ?: 'No description has been added.'))) ?>
              </div>
            </details>

            <details class="case-accordion" open>
              <summary>Status</summary>
              <div class="case-detail-grid">
                <div class="case-info-card"><span>Status</span><strong><?= lex_e(lex_case_files_status_label((string) $record['status'])) ?></strong></div>
                <div class="case-info-card"><span>Date Created</span><strong><?= lex_e((string) $record['date_created']) ?></strong></div>
                <div class="case-info-card"><span>Created By</span><strong><?= lex_e((string) ($record['created_by_name'] ?: 'System')) ?></strong></div>
                <div class="case-info-card"><span>Updated By</span><strong><?= lex_e((string) ($record['updated_by_name'] ?: 'System')) ?></strong></div>
              </div>
            </details>

          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_editor(array $state, array $filters, array $clients, array $lawyers, array $user): string
{
    ob_start();
    ?>
    <?php if (!empty($filters['is_lawyer'])): ?>
      <div class="modal-overlay case-create-modal-overlay" id="caseFileCreateModal" data-case-create-modal aria-hidden="true">
        <div class="modal-card case-create-modal wide case-compact-modal" role="dialog" aria-modal="true" aria-labelledby="caseFileCreateTitle">
          <div class="modal-header">
            <div>
              <h2 id="caseFileCreateTitle">Create Case File</h2>
              <p class="modal-note">FULLNAME is required. The form stores a local draft until submission succeeds.</p>
            </div>
            <button class="close-button" type="button" data-case-create-close aria-label="Close">&times;</button>
          </div>
          <div class="modal-body case-create-modal-body">
            <form method="post" enctype="multipart/form-data" class="case-form" data-casefile-form data-persist-form="casefile-create" novalidate>
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="create">
              <div class="form-grid">
                <label class="full">Client
                  <select name="client_user_id" required>
                    <option value="">Select client</option>
                    <?php foreach ($clients as $client): ?>
                      <option value="<?= (int) $client['id'] ?>"><?= lex_e((string) $client['full_name']) ?> - Client</option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="full">FULLNAME
                  <input type="text" name="full_name" required minlength="2" placeholder="Client or entity full name">
                </label>
                <label class="full">CASE FILE
                  <input type="text" name="case_file_title" required minlength="2" placeholder="Case file title">
                </label>
                <label class="full">Assigned Lawyer
                  <select name="assigned_lawyer_user_id" required>
                    <?php foreach ($lawyers as $lawyer): ?>
                      <option value="<?= (int) $lawyer['id'] ?>"<?= (int) $lawyer['id'] === (int) $user['id'] ? ' selected' : '' ?>><?= lex_e((string) $lawyer['full_name']) ?> - Lawyer</option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="full">Status
                  <select name="status" required>
                    <option value="open">Open</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="closed">Closed</option>
                  </select>
                </label>
                <label class="full">Case Description
                  <textarea name="description" rows="4" placeholder="Brief legal summary, risks, and notes"></textarea>
                </label>
                <div class="case-upload-inline full">
                  <div class="case-subpanel-head">
                    <h3>Upload Attachment</h3>
                    <p class="muted">Optional file saved with the new case file.</p>
                  </div>
                  <label class="full">Category
                    <select name="upload_category">
                      <option value="DOCUMENTS">Documents</option>
                      <option value="PHOTOS">Photos</option>
                      <option value="EVIDENCE">Evidence</option>
                      <option value="COURT_FILINGS">Court Filings</option>
                      <option value="CORRESPONDENCE">Correspondence</option>
                    </select>
                  </label>
                  <label class="full">Attachment
                    <input type="file" name="attachment">
                  </label>
                </div>
              </div>
              <p class="field-error" data-form-errors aria-live="polite"></p>
              <div class="action-group">
                <button class="button button-primary" type="submit">Create Case File</button>
                <button class="button button-secondary" type="reset">Clear</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php if (!empty($state['selected'])): ?>
        <div class="modal-overlay case-create-modal-overlay" id="caseFileEditModal" data-case-edit-modal aria-hidden="true">
          <div class="modal-card case-create-modal wide case-compact-modal" role="dialog" aria-modal="true" aria-labelledby="caseFileEditTitle">
            <div class="modal-header">
              <div>
                <h2 id="caseFileEditTitle">Edit Case File</h2>
                <p class="modal-note">Lawyers can update the case details, status, and assignment.</p>
              </div>
              <button class="close-button" type="button" data-case-edit-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body case-create-modal-body">
              <form method="post" class="case-form" data-casefile-form novalidate data-persist-form="casefile-edit-<?= (int) $state['selected']['id'] ?>">
                <?= lex_csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="case_file_id" value="<?= (int) $state['selected']['id'] ?>" data-case-edit-id>
                <div class="form-grid">
                  <label class="full">Client
                    <select name="client_user_id" required data-case-edit-client>
                      <option value="">Select client</option>
                      <?php foreach ($clients as $client): ?>
                        <option value="<?= (int) $client['id'] ?>"<?= (int) $state['selected']['client_user_id'] === (int) $client['id'] ? ' selected' : '' ?>><?= lex_e((string) $client['full_name']) ?> - Client</option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="full">FULLNAME
                    <input type="text" name="full_name" required minlength="2" placeholder="Client or entity full name" value="<?= lex_e((string) $state['selected']['full_name']) ?>" data-case-edit-full-name>
                  </label>
                  <label class="full">CASE FILE
                    <input type="text" name="case_file_title" required minlength="2" placeholder="Case file title" value="<?= lex_e((string) $state['selected']['case_file_title']) ?>" data-case-edit-case-title>
                  </label>
                  <label class="full">Assigned Lawyer
                    <select name="assigned_lawyer_user_id" required data-case-edit-lawyer>
                      <?php foreach ($lawyers as $lawyer): ?>
                        <option value="<?= (int) $lawyer['id'] ?>"<?= (int) $state['selected']['assigned_lawyer_user_id'] === (int) $lawyer['id'] ? ' selected' : '' ?>><?= lex_e((string) $lawyer['full_name']) ?> - Lawyer</option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="full">Status
                    <select name="status" required data-case-edit-status>
                      <option value="open"<?= (string) $state['selected']['status'] === 'open' ? ' selected' : '' ?>>Open</option>
                      <option value="ongoing"<?= (string) $state['selected']['status'] === 'ongoing' ? ' selected' : '' ?>>Ongoing</option>
                      <option value="closed"<?= (string) $state['selected']['status'] === 'closed' ? ' selected' : '' ?>>Closed</option>
                    </select>
                  </label>
                  <label class="full">Case Description
                    <textarea name="description" rows="4" placeholder="Brief legal summary, risks, and notes" data-case-edit-description><?= lex_e((string) $state['selected']['description']) ?></textarea>
                  </label>
                </div>
                <p class="field-error" data-form-errors aria-live="polite"></p>
                <div class="action-group">
                  <button class="button button-primary" type="submit">Save Changes</button>
                  <button class="button button-secondary" type="reset">Reset</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_activity(array $state): string
{
    $activity = $state['activity'];
    ob_start();
    ?>
    <article class="card case-panel case-activity-card">
      <div class="card-head case-panel-header">
        <div>
          <h2>Recent Activity</h2>
          <p class="muted case-activity-note">Freshly updated records from the visible set.</p>
        </div>
        <span class="pill">Latest 5</span>
      </div>
      <div class="activity-feed case-activity-feed">
        <?php if (empty($activity)): ?>
          <div class="case-empty-state">
            <strong>No recent activity.</strong>
            <p class="muted">Activity will appear once case files are created or updated.</p>
          </div>
        <?php else: ?>
          <?php foreach ($activity as $item): ?>
            <div class="activity-item case-activity-item">
              <div>
                <strong><?= lex_e((string) $item['full_name']) ?></strong>
                <span><?= lex_e((string) $item['case_file_title']) ?></span>
              </div>
              <div class="case-activity-meta">
                <?= lex_case_files_render_status_pill((string) $item['status']) ?>
                <small><?= lex_e((string) $item['updated_at']) ?></small>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_collect_request_filters(array $user): array
{
    $status = (string) ($_GET['status'] ?? 'all');
    $sort = (string) ($_GET['sort'] ?? 'updated_at');
    $dir = strtolower((string) ($_GET['dir'] ?? 'desc'));
    $page = max(1, lex_sanitize_int($_GET['page'] ?? 1));
    $record = lex_sanitize_int($_GET['record'] ?? 0);
    $search = trim((string) ($_GET['q'] ?? ''));

    return [
        'search' => $search,
        'status' => in_array($status, ['all', 'open', 'ongoing', 'closed'], true) ? $status : 'all',
        'sort' => in_array($sort, ['updated_at', 'date_created', 'full_name', 'case_file_title', 'status'], true) ? $sort : 'updated_at',
        'dir' => $dir === 'asc' ? 'asc' : 'desc',
        'page' => $page,
        'record' => $record,
        'is_client' => $user['role'] === 'client',
        'is_lawyer' => $user['role'] === 'lawyer',
        'current_client_user_id' => $user['role'] === 'client' ? (int) $user['id'] : 0,
        'current_lawyer_user_id' => $user['role'] === 'lawyer' ? (int) $user['id'] : 0,
    ];
}

function lex_case_files_redirect_url(array $filters, ?int $recordId = null): string
{
    $params = [];
    if (!empty($filters['search'])) {
        $params['q'] = $filters['search'];
    }
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['sort']) && $filters['sort'] !== 'updated_at') {
        $params['sort'] = $filters['sort'];
    }
    if (!empty($filters['dir']) && $filters['dir'] !== 'desc') {
        $params['dir'] = $filters['dir'];
    }
    if (!empty($filters['page']) && (int) $filters['page'] > 1) {
        $params['page'] = (int) $filters['page'];
    }
    if ($recordId !== null) {
        $params['record'] = $recordId;
    }
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return 'case_files.php' . ($query !== '' ? '?' . $query : '');
}

function lex_case_files_send_json(array $state, array $filters, array $clients, array $lawyers, array $user): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'summaryHtml' => lex_case_files_render_summary($state),
        'listHtml' => lex_case_files_render_list($state, $filters),
        'detailHtml' => lex_case_files_render_detail($state, $filters, $user),
        'vaultHtml' => lex_case_files_render_vault_panel($state, $filters, $user),
        'activityHtml' => lex_case_files_render_activity($state),
        'editorHtml' => lex_case_files_render_editor($state, $filters, $clients, $lawyers, $user),
        'paginationHtml' => lex_case_files_render_pagination($state),
        'meta' => [
            'page' => (int) $state['page'],
            'pageCount' => (int) $state['page_count'],
            'total' => (int) $state['counts']['total'],
            'selectedId' => (int) (($state['selected']['id'] ?? 0) ?: 0),
            'selectedError' => (string) $state['selected_error'],
            'search' => (string) $state['search'],
            'status' => (string) $state['status'],
            'sort' => (string) $state['sort'],
            'dir' => (string) $state['dir'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
