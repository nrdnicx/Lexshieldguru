<?php
declare(strict_types=1);

function lex_case_files_handle_post(PDO $pdo, array $user, array $filters, array $clients, array $lawyers): array
{
    $isLawyer = $user['role'] === 'lawyer';
    $isClient = $user['role'] === 'client';
    $error = '';
    $failedAction = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('case_files/actions');
        $error = 'Invalid CSRF token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $caseFileId = lex_sanitize_int($_POST['case_file_id'] ?? 0);
        $returnUrl = lex_case_files_redirect_url($filters, $caseFileId > 0 ? $caseFileId : null);

        if (in_array($action, ['create_folder', 'delete_folder', 'upload_document', 'approve_document', 'reject_document', 'rename_document', 'delete_document'], true) && $caseFileId > 0) {
            $stmt = $pdo->prepare('SELECT cf.* FROM case_files cf WHERE cf.id = :id LIMIT 1');
            $stmt->execute(['id' => $caseFileId]);
            $caseFile = $stmt->fetch();
            $access = $caseFile ? lex_case_file_vault_access($caseFile, $user) : 'none';
            if (!$caseFile || $access === 'none') {
                lex_audit('denied_case_file_vault_action', 'case_files', $caseFileId > 0 ? (string) $caseFileId : null);
                $error = 'Case file not found.';
            } elseif ($action === 'create_folder') {
                if ($access !== 'manage') {
                    lex_audit('denied_create_case_file_folder', 'case_files', (string) $caseFileId);
                    $error = 'Only the assigned lawyer can manage vault folders.';
                } else {
                    $folderName = lex_sanitize_text($_POST['folder_name'] ?? '');
                    if ($folderName === '') {
                        $error = 'Folder name is required.';
                    } else {
                        lex_case_file_vault_ensure_defaults($caseFileId, (int) $user['id']);
                        $slug = lex_case_file_vault_slug($folderName);
                        $folderExists = $pdo->prepare('SELECT id FROM case_file_folders WHERE case_file_id = :case_file_id AND slug = :slug LIMIT 1');
                        $folderExists->execute(['case_file_id' => $caseFileId, 'slug' => $slug]);
                        if ($folderExists->fetchColumn()) {
                            $error = 'That vault folder already exists.';
                        } else {
                        $pdo->prepare(
                            'INSERT IGNORE INTO case_file_folders (case_file_id, parent_folder_id, name, slug, created_by_user_id)
                             VALUES (:case_file_id, NULL, :name, :slug, :created_by_user_id)'
                        )->execute([
                            'case_file_id' => $caseFileId,
                            'name' => $folderName,
                            'slug' => $slug,
                            'created_by_user_id' => (int) $user['id'],
                        ]);
                        $folderPath = lex_case_files_folder_path((string) $caseFile['folder_name']) . DIRECTORY_SEPARATOR . $slug;
                        if (!is_dir($folderPath)) {
                            @mkdir($folderPath, 0775, true);
                        }
                        lex_audit('create_case_file_folder', 'case_file_folders', (string) $caseFileId);
                        lex_flash_set('success', 'Vault folder added.');
                        header('Location: ' . $returnUrl);
                        exit;
                        }
                    }
                }
            } elseif ($action === 'delete_folder') {
                if ($access !== 'manage') {
                    lex_audit('denied_delete_case_file_folder', 'case_files', (string) $caseFileId);
                    $error = 'Only the assigned lawyer can manage vault folders.';
                } else {
                    $folderId = lex_sanitize_int($_POST['folder_id'] ?? 0);
                    $folderStmt = $pdo->prepare(
                        'SELECT * FROM case_file_folders
                         WHERE id = :id AND case_file_id = :case_file_id
                         LIMIT 1'
                    );
                    $folderStmt->execute(['id' => $folderId, 'case_file_id' => $caseFileId]);
                    $folder = $folderStmt->fetch();
                    if (!$folder) {
                        $error = 'Vault folder not found.';
                    } elseif (lex_case_files_is_default_vault_folder((string) $folder['slug'])) {
                        $error = 'Default vault folders cannot be deleted.';
                    } else {
                        try {
                            $documents = lex_recent(
                                'SELECT d.*, f.slug AS folder_slug, f.name AS folder_name
                                 FROM case_file_documents d
                                 JOIN case_file_folders f ON f.id = d.folder_id
                                 WHERE d.folder_id = :folder_id AND d.case_file_id = :case_file_id',
                                ['folder_id' => $folderId, 'case_file_id' => $caseFileId]
                            );
                            foreach ($documents as $document) {
                                lex_case_file_vault_delete_document_object($caseFile, $document);
                            }
                            $folderPath = lex_case_file_vault_folder_dir($caseFile, $folder);
                            lex_case_files_recursive_delete($folderPath);
                            $pdo->prepare('DELETE FROM case_file_folders WHERE id = :id')->execute(['id' => $folderId]);
                            lex_audit('delete_case_file_folder', 'case_file_folders', (string) $folderId);
                            lex_flash_set('success', 'Vault folder deleted.');
                            header('Location: ' . $returnUrl);
                            exit;
                        } catch (Throwable $e) {
                            $error = 'Unable to delete every document in this folder.';
                        }
                    }
                }
            } elseif ($action === 'upload_document') {
                $folderId = lex_sanitize_int($_POST['folder_id'] ?? 0);
                try {
                    lex_case_file_vault_ensure_defaults($caseFileId, (int) $user['id']);
                    if ($access === 'client') {
                        $clientFolderStmt = $pdo->prepare('SELECT id FROM case_file_folders WHERE case_file_id = :case_file_id AND slug = "CLIENT_UPLOADS" LIMIT 1');
                        $clientFolderStmt->execute(['case_file_id' => $caseFileId]);
                        $folderId = (int) ($clientFolderStmt->fetchColumn() ?: $folderId);
                    }
                    $status = $access === 'manage' ? 'approved' : 'pending';
                    $document = lex_case_file_vault_store_document($caseFile, $folderId, $_FILES['vault_document'] ?? [], $user, $status);
                    $pdo->prepare('UPDATE case_files SET updated_by_user_id = :updated_by_user_id WHERE id = :id')->execute([
                        'updated_by_user_id' => (int) $user['id'],
                        'id' => $caseFileId,
                    ]);
                    lex_audit($status === 'pending' ? 'submit_case_file_document' : 'upload_case_file_document', 'case_file_documents', (string) $document['id']);
                    if ($status === 'pending' && !empty($caseFile['assigned_lawyer_user_id'])) {
                        lex_notify((int) $caseFile['assigned_lawyer_user_id'], 'case_file', 'A client submitted a document for approval.');
                    } elseif ($status === 'approved') {
                        lex_notify((int) $caseFile['client_user_id'], 'case_file', 'A new case document is available in your vault.');
                    }
                    lex_flash_set('success', $status === 'pending' ? 'Document submitted for lawyer approval.' : 'Document uploaded to the vault.');
                    header('Location: ' . $returnUrl);
                    exit;
                } catch (Throwable $e) {
                    error_log('[CASE_FILE_STORAGE] Upload failed: ' . $e->getMessage());
                    $error = 'STORAGE ERROR: ' . $e->getMessage();
                }
            } elseif (in_array($action, ['approve_document', 'reject_document', 'rename_document', 'delete_document'], true)) {
                if ($access !== 'manage') {
                    lex_audit('denied_review_case_file_document', 'case_files', (string) $caseFileId);
                    $error = 'Only the assigned lawyer can review vault documents.';
                } else {
                    $documentId = lex_sanitize_int($_POST['document_id'] ?? 0);
                    $docStmt = $pdo->prepare(
                        'SELECT d.*, f.slug AS folder_slug, f.name AS folder_name
                         FROM case_file_documents d
                         JOIN case_file_folders f ON f.id = d.folder_id
                         WHERE d.id = :id AND d.case_file_id = :case_file_id
                         LIMIT 1'
                    );
                    $docStmt->execute(['id' => $documentId, 'case_file_id' => $caseFileId]);
                    $document = $docStmt->fetch();
                    if (!$document) {
                        $error = 'Vault document not found.';
                    } elseif ($action === 'approve_document') {
                        $pdo->prepare(
                            'UPDATE case_file_documents
                             SET upload_status = "approved", approved_by_user_id = :approved_by_user_id, approved_at = NOW(), rejection_reason = NULL
                             WHERE id = :id'
                        )->execute(['approved_by_user_id' => (int) $user['id'], 'id' => $documentId]);
                        lex_audit('approve_case_file_document', 'case_file_documents', (string) $documentId);
                        lex_notify((int) $caseFile['client_user_id'], 'case_file', 'Your submitted case document was approved.');
                        lex_flash_set('success', 'Document approved.');
                        header('Location: ' . $returnUrl);
                        exit;
                    } elseif ($action === 'reject_document') {
                        $rejectionReason = lex_sanitize_multiline_text($_POST['rejection_reason'] ?? '');
                        if ($rejectionReason === '') {
                            $error = 'Add a reason before rejecting the document.';
                        } else {
                            $rejectionReason = substr($rejectionReason, 0, 500);
                        $pdo->prepare(
                            'UPDATE case_file_documents
                             SET upload_status = "rejected", approved_by_user_id = :approved_by_user_id, approved_at = NOW(), rejection_reason = :rejection_reason
                             WHERE id = :id'
                        )->execute([
                            'approved_by_user_id' => (int) $user['id'],
                            'rejection_reason' => $rejectionReason,
                            'id' => $documentId,
                        ]);
                        lex_audit('reject_case_file_document', 'case_file_documents', (string) $documentId);
                        lex_notify((int) $caseFile['client_user_id'], 'case_file', 'Your submitted case document was rejected: ' . $rejectionReason);
                        lex_flash_set('success', 'Document rejected.');
                        header('Location: ' . $returnUrl);
                        exit;
                        }
                    } elseif ($action === 'rename_document') {
                        $newName = lex_sanitize_text($_POST['document_name'] ?? '');
                        if ($newName === '') {
                            $error = 'Document name is required.';
                        } else {
                            $newName = substr($newName, 0, 255);
                            $pdo->prepare(
                                'UPDATE case_file_documents
                                 SET original_name = :original_name
                                 WHERE id = :id'
                            )->execute(['original_name' => $newName, 'id' => $documentId]);
                            lex_audit('rename_case_file_document', 'case_file_documents', (string) $documentId);
                            lex_flash_set('success', 'Document renamed.');
                            header('Location: ' . $returnUrl);
                            exit;
                        }
                    } else {
                        try {
                            lex_case_file_vault_delete_document_object($caseFile, $document);
                            $pdo->prepare('DELETE FROM case_file_documents WHERE id = :id')->execute(['id' => $documentId]);
                            lex_audit('delete_case_file_document', 'case_file_documents', (string) $documentId);
                            lex_flash_set('success', 'Vault document deleted.');
                            header('Location: ' . $returnUrl);
                            exit;
                        } catch (Throwable $e) {
                            $error = 'Unable to delete the vault document.';
                        }
                    }
                }
            }
        } elseif ($isClient) {
            lex_audit('denied_case_file_mutation', 'case_files', $caseFileId > 0 ? (string) $caseFileId : null);
            $error = 'Clients can view only their own case files.';
        } elseif ($action === 'create') {
            $clientUserId = lex_sanitize_int($_POST['client_user_id'] ?? 0);
            $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
            $caseFileTitle = lex_sanitize_text($_POST['case_file_title'] ?? '');
            $description = lex_sanitize_text($_POST['description'] ?? '');
            $status = (string) ($_POST['status'] ?? 'open');
            $assignedLawyerUserId = lex_sanitize_int($_POST['assigned_lawyer_user_id'] ?? 0);
            if ($assignedLawyerUserId === 0) {
                $assignedLawyerUserId = (int) $user['id'];
            }

            $clientCheck = $pdo->prepare('SELECT id, full_name FROM users WHERE id = :id AND role = "client" AND is_active = 1 LIMIT 1');
            $clientCheck->execute(['id' => $clientUserId]);
            $clientRow = $clientCheck->fetch();
            $lawyerCheck = $pdo->prepare('SELECT id, full_name FROM users WHERE id = :id AND role = "lawyer" AND is_active = 1 LIMIT 1');
            $lawyerCheck->execute(['id' => $assignedLawyerUserId]);
            $lawyerRow = $lawyerCheck->fetch();

            if (!$clientRow) {
                $error = 'Select a valid client.';
            } elseif ($fullName === '') {
                $error = 'FULLNAME is required.';
            } elseif ($caseFileTitle === '') {
                $error = 'CASE FILE is required.';
            } elseif (!$lawyerRow) {
                $error = 'Select a valid assigned lawyer.';
            } elseif (!in_array($status, ['open', 'ongoing', 'closed'], true)) {
                $error = 'Select a valid status.';
            } else {
                $caseIdentifier = 'CF-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $folderName = lex_case_files_folder_name($fullName, $caseFileTitle, $caseIdentifier);
                $pdo->prepare(
                    'INSERT INTO case_files
                        (full_name, case_identifier, case_file_title, description, date_created, client_user_id, assigned_lawyer_user_id, status, folder_name, attachments_json, created_by_user_id, updated_by_user_id)
                     VALUES
                        (:full_name, :case_identifier, :case_file_title, :description, CURDATE(), :client_user_id, :assigned_lawyer_user_id, :status, :folder_name, :attachments_json, :created_by_user_id, :updated_by_user_id)'
                )->execute([
                    'full_name' => $fullName,
                    'case_identifier' => $caseIdentifier,
                    'case_file_title' => $caseFileTitle,
                    'description' => $description,
                    'client_user_id' => (int) $clientRow['id'],
                    'assigned_lawyer_user_id' => (int) $lawyerRow['id'],
                    'status' => $status,
                    'folder_name' => $folderName,
                    'attachments_json' => '[]',
                    'created_by_user_id' => (int) $user['id'],
                    'updated_by_user_id' => (int) $user['id'],
                ]);
                $newId = (int) $pdo->lastInsertId();
                $record = lex_recent(
                    'SELECT cf.*, lu.full_name AS assigned_lawyer_name, cu.full_name AS client_name, cu.email AS client_email, cb.full_name AS created_by_name, ub.full_name AS updated_by_name
                     FROM case_files cf
                     JOIN users cu ON cu.id = cf.client_user_id
                     LEFT JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
                     LEFT JOIN users cb ON cb.id = cf.created_by_user_id
                     LEFT JOIN users ub ON ub.id = cf.updated_by_user_id
                     WHERE cf.id = :id LIMIT 1',
                    ['id' => $newId]
                )[0] ?? null;
                if ($record) {
                    lex_case_file_vault_ensure_defaults($newId, (int) $user['id']);
                    $attachmentError = '';
                    if (!empty($_FILES['attachment']['name'])) {
                        $category = strtoupper(trim((string) ($_POST['upload_category'] ?? 'DOCUMENTS')));
                        $allowedCategories = ['DOCUMENTS', 'PHOTOS', 'EVIDENCE', 'COURT_FILINGS', 'CORRESPONDENCE'];
                        if (!in_array($category, $allowedCategories, true)) {
                            $category = 'DOCUMENTS';
                        }
                        $folderStmt = $pdo->prepare('SELECT id FROM case_file_folders WHERE case_file_id = :case_file_id AND slug = :slug LIMIT 1');
                        $folderStmt->execute(['case_file_id' => $newId, 'slug' => $category]);
                        $folderId = (int) ($folderStmt->fetchColumn() ?: 0);
                        try {
                            $document = lex_case_file_vault_store_document($record, $folderId, $_FILES['attachment'], $user, 'approved');
                            lex_audit('upload_case_file_document', 'case_file_documents', (string) $document['id']);
                        } catch (Throwable $e) {
                            $attachmentError = $e->getMessage();
                        }
                    }
                    if ($attachmentError !== '') {
                        lex_case_files_recursive_delete(lex_case_files_folder_path((string) $record['folder_name']));
                        $pdo->prepare('DELETE FROM case_files WHERE id = :id')->execute(['id' => $newId]);
                        $error = $attachmentError;
                    }
                    lex_case_files_sync_record($record);
                }
                if ($error === '') {
                    lex_audit('create_case_file', 'case_files', (string) $newId);
                    lex_notify((int) $clientRow['id'], 'case_file', 'A case file was created for your account.');
                    lex_flash_set('success', 'Case file created.');
                    $redirectFilters = $filters;
                    $redirectFilters['search'] = '';
                    $redirectFilters['status'] = 'all';
                    $redirectFilters['page'] = 1;
                    $redirectFilters['sort'] = 'updated_at';
                    $redirectFilters['dir'] = 'desc';
                    header('Location: ' . lex_case_files_redirect_url($redirectFilters, $newId));
                    exit;
                }
            }
        } elseif ($action === 'update' && $isLawyer && $caseFileId > 0) {
            $stmt = $pdo->prepare('SELECT cf.* FROM case_files cf WHERE cf.id = :id LIMIT 1');
            $stmt->execute(['id' => $caseFileId]);
            $existing = $stmt->fetch();
            $canEdit = $existing && (
                (int) $existing['created_by_user_id'] === (int) $user['id']
            );
            if (!$existing || !$canEdit) {
                lex_audit('denied_update_case_file', 'case_files', (string) $caseFileId);
                $error = 'Case file not found.';
            } else {
                $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
                $caseFileTitle = lex_sanitize_text($_POST['case_file_title'] ?? '');
                $description = lex_sanitize_text($_POST['description'] ?? '');
                $status = (string) ($_POST['status'] ?? 'open');
                $assignedLawyerUserId = lex_sanitize_int($_POST['assigned_lawyer_user_id'] ?? 0);
                $clientUserId = lex_sanitize_int($_POST['client_user_id'] ?? 0);

                $clientCheck = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role = "client" AND is_active = 1 LIMIT 1');
                $clientCheck->execute(['id' => $clientUserId]);
                $lawyerCheck = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role = "lawyer" AND is_active = 1 LIMIT 1');
                $lawyerCheck->execute(['id' => $assignedLawyerUserId]);

                if ($fullName === '') {
                    $error = 'FULLNAME is required.';
                } elseif ($caseFileTitle === '') {
                    $error = 'CASE FILE is required.';
                } elseif (!$clientCheck->fetchColumn()) {
                    $error = 'Select a valid client.';
                } elseif (!$lawyerCheck->fetchColumn()) {
                    $error = 'Select a valid assigned lawyer.';
                } elseif (!in_array($status, ['open', 'ongoing', 'closed'], true)) {
                    $error = 'Select a valid status.';
                } else {
                    $pdo->prepare(
                        'UPDATE case_files
                         SET full_name = :full_name,
                             case_file_title = :case_file_title,
                             description = :description,
                             client_user_id = :client_user_id,
                             assigned_lawyer_user_id = :assigned_lawyer_user_id,
                             status = :status,
                             updated_by_user_id = :updated_by_user_id
                         WHERE id = :id'
                    )->execute([
                        'full_name' => $fullName,
                        'case_file_title' => $caseFileTitle,
                        'description' => $description,
                        'client_user_id' => $clientUserId,
                        'assigned_lawyer_user_id' => $assignedLawyerUserId,
                        'status' => $status,
                        'updated_by_user_id' => (int) $user['id'],
                        'id' => $caseFileId,
                    ]);
                    $record = lex_recent(
                        'SELECT cf.*, lu.full_name AS assigned_lawyer_name, cu.full_name AS client_name, cu.email AS client_email, cb.full_name AS created_by_name, ub.full_name AS updated_by_name
                         FROM case_files cf
                         JOIN users cu ON cu.id = cf.client_user_id
                         LEFT JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
                         LEFT JOIN users cb ON cb.id = cf.created_by_user_id
                         LEFT JOIN users ub ON ub.id = cf.updated_by_user_id
                         WHERE cf.id = :id LIMIT 1',
                        ['id' => $caseFileId]
                    )[0] ?? null;
                    if ($record) {
                        lex_case_files_sync_record($record);
                    }
                    lex_audit('update_case_file', 'case_files', (string) $caseFileId);
                    lex_notify($clientUserId, 'case_file', 'Your case file was updated.');
                    lex_flash_set('success', 'Case file updated.');
                    header('Location: ' . lex_case_files_redirect_url($filters, $caseFileId));
                    exit;
                }
            }
        } elseif ($action === 'delete' && $isLawyer && $caseFileId > 0) {
            $stmt = $pdo->prepare('SELECT cf.* FROM case_files cf WHERE cf.id = :id LIMIT 1');
            $stmt->execute(['id' => $caseFileId]);
            $caseFile = $stmt->fetch();
            $access = $caseFile ? lex_case_file_vault_access($caseFile, $user) : 'none';
            $folderName = (string) ($caseFile['folder_name'] ?? '');
            if (!$caseFile || $access !== 'manage' || $folderName === '') {
                lex_audit('denied_delete_case_file', 'case_files', (string) $caseFileId);
                $error = 'Case file not found.';
            } else {
                $caseFolderPath = lex_case_files_folder_path($folderName);
                try {
                    $documents = lex_recent(
                        'SELECT d.*, f.slug AS folder_slug, f.name AS folder_name
                         FROM case_file_documents d
                         JOIN case_file_folders f ON f.id = d.folder_id
                         WHERE d.case_file_id = :case_file_id',
                        ['case_file_id' => $caseFileId]
                    );
                    foreach ($documents as $document) {
                        lex_case_file_vault_delete_document_object($caseFile, $document);
                    }

                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM case_file_documents WHERE case_file_id = :case_file_id')->execute(['case_file_id' => $caseFileId]);
                    $pdo->prepare('DELETE FROM case_file_folders WHERE case_file_id = :case_file_id')->execute(['case_file_id' => $caseFileId]);
                    $pdo->prepare('DELETE FROM case_files WHERE id = :id')->execute(['id' => $caseFileId]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Unable to permanently delete the case file.';
                }

                if ($error === '') {
                    lex_case_files_recursive_delete($caseFolderPath);
                    lex_audit('hard_delete_case_file', 'case_files', (string) $caseFileId);
                    lex_flash_set('success', 'Case file permanently deleted.');
                    $redirectFilters = $filters;
                    $redirectFilters['record'] = 0;
                    header('Location: ' . lex_case_files_redirect_url($redirectFilters));
                    exit;
                }
            }
        }
    }
}

if ($error !== '') {
    $failedAction = (string) ($_POST['action'] ?? '');
    if ($failedAction !== '') {
        $safeAction = preg_replace('/[^a-z0-9_]+/i', '_', $failedAction) ?: 'unknown';
        lex_audit('failed_case_file_' . strtolower(substr($safeAction, 0, 80)), 'case_files', isset($_POST['case_file_id']) ? (string) lex_sanitize_int($_POST['case_file_id']) : null);
    }
}

    return [
        'error' => $error,
        'failed_action' => $failedAction,
    ];
}
