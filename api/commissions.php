<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';

    if ($action === 'create') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $user = require_role($pdo, 'client');

        $listingId = int_input($input, 'listingId', 1);
        $brief = str_input($input, 'brief', 10000);

        $pdo->beginTransaction();

        $listingStmt = $pdo->prepare(
            'SELECT TOP (1) id, artist_id, title, price, slots_total, slots_used
             FROM dbo.listings WITH (UPDLOCK, ROWLOCK)
             WHERE id = :id'
        );
        $listingStmt->execute(['id' => $listingId]);
        $listing = $listingStmt->fetch();

        if (!$listing) {
            $pdo->rollBack();
            json_response(false, 'Listing not found.', null, 404);
        }

        if ((int)$listing['slots_used'] >= (int)$listing['slots_total']) {
            $pdo->rollBack();
            json_response(false, 'This listing has no open slots.', null, 409);
        }

        $claimSlot = $pdo->prepare(
            'UPDATE dbo.listings
             SET slots_used = slots_used + 1,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id
               AND slots_used < slots_total'
        );
        $claimSlot->execute(['id' => $listingId]);

        if ($claimSlot->rowCount() !== 1) {
            $pdo->rollBack();
            json_response(false, 'The listing slot was just taken. Please choose another slot.', null, 409);
        }

        $insert = $pdo->prepare(
            'INSERT INTO dbo.commissions
             (listing_id, client_id, artist_id, status, payment_status, price, brief, revisions, created_at, updated_at)
             OUTPUT INSERTED.id
             VALUES
             (:listing_id, :client_id, :artist_id, :status, :payment_status, :price, :brief, 0, SYSUTCDATETIME(), SYSUTCDATETIME())'
        );
        $insert->execute([
            'listing_id' => (int)$listing['id'],
            'client_id' => (int)$user['id'],
            'artist_id' => (int)$listing['artist_id'],
            'status' => 'requested',
            'payment_status' => 'pending',
            'price' => (float)$listing['price'],
            'brief' => $brief,
        ]);

        $commissionId = (int)$insert->fetchColumn();
        if ($commissionId <= 0) {
            throw new RuntimeException('Commission was inserted but SQL Server did not return its new ID.');
        }

        if (table_exists($pdo, 'commission_status_history')) {
            $history = $pdo->prepare(
                'INSERT INTO dbo.commission_status_history
                 (commission_id, from_status, to_status, actor_id, note, created_at)
                 VALUES (:commission_id, NULL, :to_status, :actor_id, :note, SYSUTCDATETIME())'
            );
            $history->execute([
                'commission_id' => $commissionId,
                'to_status' => 'requested',
                'actor_id' => (int)$user['id'],
                'note' => 'Client submitted a commission request.',
            ]);
        }

        // Commit the actual commission before optional audit/notification side effects.
        // This prevents a logging/notification schema mismatch from making the commission itself fail.
        $pdo->commit();

        try {
            audit_log($pdo, (int)$user['id'], actor_name($user), 'Commission Requested', $listing['title']);
        } catch (Throwable $sideError) {
            error_log($sideError->getMessage());
        }
        try {
            integration_log($pdo, 'POST /api/commissions/create', 201, [
                'id' => $commissionId,
                'price' => (float)$listing['price'],
            ]);
        } catch (Throwable $sideError) {
            error_log($sideError->getMessage());
        }

        $artistLookup = $pdo->prepare('SELECT TOP (1) name FROM dbo.users WHERE id = :id');
        $artistLookup->execute(['id' => (int)$listing['artist_id']]);
        $artistName = (string)($artistLookup->fetchColumn() ?: 'the artist');

        if (table_exists($pdo, 'notifications')) {
            try {
                notify($pdo, (int)$listing['artist_id'], "New commission request from {$user['name']}!", $commissionId);
                notify($pdo, (int)$user['id'], "Your commission request for {$listing['title']} was sent to {$artistName}.", $commissionId);
            } catch (Throwable $sideError) {
                error_log($sideError->getMessage());
            }
        }

        $commissionStmt = $pdo->prepare(
            'SELECT TOP (1) id, listing_id, client_id, artist_id, status, payment_status, price, brief, revisions, created_at, updated_at
             FROM dbo.commissions WHERE id = :id'
        );
        $commissionStmt->execute(['id' => $commissionId]);
        $createdCommission = $commissionStmt->fetch();

        json_response(true, 'Commission request submitted.', [
            'id' => $commissionId,
            'commission' => $createdCommission ? commission_view($createdCommission, [], [], [[
                'id' => 0,
                'from' => null,
                'to' => 'requested',
                'actorId' => (int)$user['id'],
                'note' => 'Client submitted a commission request.',
                'at' => iso_utc((string)$createdCommission['created_at']),
            ]]) : null,
        ], 201);
    }

    if ($action === 'status') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $user = require_auth($pdo);
        $commissionId = int_input($input, 'id', 1);
        $newStatus = str_input($input, 'status', 30);
        $isRevision = !empty($input['revision']);

        $validStatuses = ['accepted', 'declined', 'inprogress', 'inreview', 'delivered'];
        if (!in_array($newStatus, $validStatuses, true)) {
            json_response(false, 'Invalid workflow status.', null, 400);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT TOP (1) id, listing_id, client_id, artist_id, status, payment_status, price, revisions
             FROM dbo.commissions WITH (UPDLOCK, ROWLOCK)
             WHERE id = :id'
        );
        $stmt->execute(['id' => $commissionId]);
        $commission = $stmt->fetch();

        if (!$commission) {
            $pdo->rollBack();
            json_response(false, 'Commission record not found.', null, 404);
        }

        $allowed = false;
        if ($user['role'] === 'artist' && (int)$commission['artist_id'] === (int)$user['id']) {
            $allowed = (
                $newStatus === 'accepted' && $commission['status'] === 'requested' && !$isRevision
            ) || (
                $newStatus === 'declined' && $commission['status'] === 'requested' && !$isRevision
            ) || (
                $newStatus === 'inprogress' && $commission['status'] === 'accepted' && $commission['payment_status'] === 'paid' && !$isRevision
            ) || (
                $newStatus === 'inreview' && $commission['status'] === 'inprogress' && !$isRevision
            );
        }

        if ($user['role'] === 'client' && (int)$commission['client_id'] === (int)$user['id']) {
            $allowed = (
                $newStatus === 'delivered' && $commission['status'] === 'inreview' && $commission['payment_status'] === 'paid' && !$isRevision
            ) || (
                $newStatus === 'inprogress' && $commission['status'] === 'inreview' && $isRevision
            );
        }

        if (!$allowed) {
            $pdo->rollBack();
            json_response(false, 'That status transition is not allowed for your role or the current workflow state.', null, 403);
        }

        $newRevisions = (int)$commission['revisions'] + ($isRevision ? 1 : 0);

        $update = $pdo->prepare(
            'UPDATE dbo.commissions
             SET status = :status,
                 revisions = :revisions,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id'
        );
        $update->execute([
            'status' => $newStatus,
            'revisions' => $newRevisions,
            'id' => $commissionId,
        ]);

        if ($newStatus === 'declined' && $commission['status'] === 'requested') {
            $releaseSlot = $pdo->prepare(
                'UPDATE dbo.listings
                 SET slots_used = CASE WHEN slots_used > 0 THEN slots_used - 1 ELSE 0 END,
                     updated_at = SYSUTCDATETIME()
                 WHERE id = :id'
            );
            $releaseSlot->execute(['id' => (int)$commission['listing_id']]);
        }

        if (table_exists($pdo, 'commission_status_history')) {
            $note = $isRevision ? 'Client requested a revision.' : "Commission status changed to {$newStatus}.";
            $history = $pdo->prepare(
                'INSERT INTO dbo.commission_status_history
                 (commission_id, from_status, to_status, actor_id, note, created_at)
                 VALUES (:commission_id, :from_status, :to_status, :actor_id, :note, SYSUTCDATETIME())'
            );
            $history->execute([
                'commission_id' => $commissionId,
                'from_status' => $commission['status'],
                'to_status' => $newStatus,
                'actor_id' => (int)$user['id'],
                'note' => $note,
            ]);
        }

        // Commit the workflow state first. Logging/notifications are
        // integration side effects and must never make a valid transition
        // appear to fail after the commission was already changed.
        $pdo->commit();

        safe_audit_log(
            $pdo,
            (int)$user['id'],
            actor_name($user),
            'Updated Status to ' . strtoupper($newStatus),
            "Commission #{$commissionId}"
        );
        safe_integration_log($pdo, 'POST /api/commissions/status', 200, [
            'id' => $commissionId,
            'status' => $newStatus,
            'revision' => $isRevision,
        ]);

        $notifyUserId = (int)$user['id'] === (int)$commission['artist_id']
            ? (int)$commission['client_id']
            : (int)$commission['artist_id'];
        safe_notify($pdo, $notifyUserId, "Commission #{$commissionId} status updated to: {$newStatus}", $commissionId);

        json_response(true, 'Commission status updated.', [
            'id' => $commissionId,
            'status' => $newStatus,
            'revisions' => $newRevisions,
        ]);
    }

    if ($action === 'pay_escrow') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $user = require_role($pdo, 'client');
        $commissionId = int_input($input, 'id', 1);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT TOP (1) id, client_id, artist_id, status, payment_status, price
             FROM dbo.commissions WITH (UPDLOCK, ROWLOCK)
             WHERE id = :id'
        );
        $stmt->execute(['id' => $commissionId]);
        $commission = $stmt->fetch();

        if (!$commission) { $pdo->rollBack(); json_response(false, 'Commission record not found.', null, 404); }
        if ((int)$commission['client_id'] !== (int)$user['id']) { $pdo->rollBack(); json_response(false, 'You do not own this commission.', null, 403); }
        if ($commission['status'] !== 'accepted' || $commission['payment_status'] !== 'pending') {
            $pdo->rollBack();
            json_response(false, 'Escrow can only be locked after the artist accepts the commission.', null, 409);
        }

        $update = $pdo->prepare(
            'UPDATE dbo.commissions
             SET payment_status = :new_status, updated_at = SYSUTCDATETIME()
             WHERE id = :id AND payment_status = :old_status'
        );
        $update->execute([
            'new_status' => 'paid',
            'old_status' => 'pending',
            'id' => $commissionId,
        ]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            json_response(false, 'Escrow could not be locked because the payment state changed. Reload and try again.', null, 409);
        }

        if (table_exists($pdo, 'invoices')) {
            $invoiceCheck = $pdo->prepare('SELECT TOP (1) id FROM dbo.invoices WHERE commission_id = :commission_id');
            $invoiceCheck->execute(['commission_id' => $commissionId]);
            if (!$invoiceCheck->fetchColumn()) {
                $invoiceNo = 'ARTIC-DEMO-' . date('Ymd') . '-' . str_pad((string)$commissionId, 6, '0', STR_PAD_LEFT);
                $invoiceInsert = $pdo->prepare("INSERT INTO dbo.invoices (commission_id, invoice_number, client_id, artist_id, subtotal, platform_fee, total, status, issued_at, paid_at) VALUES (:commission_id,:invoice_number,:client_id,:artist_id,:subtotal,0,:total,N'paid',SYSUTCDATETIME(),SYSUTCDATETIME())");
                $invoiceInsert->execute([
                    'commission_id'=>$commissionId, 'invoice_number'=>$invoiceNo, 'client_id'=>(int)$commission['client_id'],
                    'artist_id'=>(int)$commission['artist_id'], 'subtotal'=>(float)$commission['price'], 'total'=>(float)$commission['price']
                ]);
            }
            if (table_exists($pdo, 'payouts')) {
                $invIdStmt = $pdo->prepare('SELECT TOP (1) id FROM dbo.invoices WHERE commission_id=:commission_id');
                $invIdStmt->execute(['commission_id'=>$commissionId]); $invoiceId=(int)$invIdStmt->fetchColumn();
                $payoutCheck=$pdo->prepare('SELECT TOP (1) id FROM dbo.payouts WHERE commission_id=:commission_id');
                $payoutCheck->execute(['commission_id'=>$commissionId]);
                if (!$payoutCheck->fetchColumn()) {
                    $payoutRef='ARTIC-PENDING-'.$commissionId.'-'.strtoupper(bin2hex(random_bytes(3)));
                    $payoutInsert=$pdo->prepare("INSERT INTO dbo.payouts (commission_id, invoice_id, artist_id, amount, status, payout_reference, created_at) VALUES (:commission_id,:invoice_id,:artist_id,:amount,N'pending',:reference,SYSUTCDATETIME())");
                    $payoutInsert->execute(['commission_id'=>$commissionId,'invoice_id'=>$invoiceId,'artist_id'=>(int)$commission['artist_id'],'amount'=>(float)$commission['price'],'reference'=>$payoutRef]);
                }
            }
        }

        $pdo->commit();

        safe_audit_log($pdo, (int)$user['id'], actor_name($user), 'Escrow Payment Locked', 'Amount: $' . number_format((float)$commission['price'], 2));
        safe_integration_log($pdo, 'POST /api/payments/escrow-lock', 200, ['id' => $commissionId, 'price' => (float)$commission['price']]);
        safe_notify($pdo, (int)$commission['artist_id'], "Escrow payment locked for commission #{$commissionId}. Production can start!", $commissionId);

        json_response(true, 'Escrow payment locked.');
    }

    if ($action === 'release_escrow') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $user = require_role($pdo, 'client');
        $commissionId = int_input($input, 'id', 1);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT TOP (1) id, client_id, artist_id, status, payment_status, price
             FROM dbo.commissions WITH (UPDLOCK, ROWLOCK)
             WHERE id = :id'
        );
        $stmt->execute(['id' => $commissionId]);
        $commission = $stmt->fetch();

        if (!$commission) { $pdo->rollBack(); json_response(false, 'Commission record not found.', null, 404); }
        if ((int)$commission['client_id'] !== (int)$user['id']) { $pdo->rollBack(); json_response(false, 'You do not own this commission.', null, 403); }
        if ($commission['status'] !== 'delivered' || $commission['payment_status'] !== 'paid') {
            $pdo->rollBack();
            json_response(false, 'Escrow can only be released after deliverables are approved.', null, 409);
        }

        $update = $pdo->prepare(
            'UPDATE dbo.commissions
             SET payment_status = :new_status, updated_at = SYSUTCDATETIME()
             WHERE id = :id AND payment_status = :old_status'
        );
        $update->execute([
            'new_status' => 'released',
            'old_status' => 'paid',
            'id' => $commissionId,
        ]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            json_response(false, 'Escrow could not be released because the payment state changed. Reload and try again.', null, 409);
        }

        $pdo->commit();

        safe_audit_log($pdo, (int)$user['id'], actor_name($user), 'Escrow Payment Released', 'Amount: $' . number_format((float)$commission['price'], 2));
        safe_integration_log($pdo, 'POST /api/payments/escrow-release', 200, ['id' => $commissionId, 'price' => (float)$commission['price']]);
        safe_notify($pdo, (int)$commission['artist_id'], "Escrow payment released for commission #{$commissionId}.", $commissionId);

        json_response(true, 'Escrow payment released.');
    }

    if ($action === 'add_file') {
        request_method('POST');
        $input = $_POST;
        require_csrf($input);
        $user = require_role($pdo, 'artist');

        $commissionId = int_input($input, 'commissionId', 1);
        $type = str_input($input, 'type', 10);
        $label = str_input($input, 'label', 180);
        $mode = strtolower(trim((string)($input['mode'] ?? 'upload')));

        if (!in_array($type, ['wip', 'final'], true)) {
            json_response(false, 'Invalid asset type.', null, 400);
        }
        if (!in_array($mode, ['upload', 'link'], true)) {
            json_response(false, 'Invalid asset submission mode.', null, 400);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT TOP (1) id, client_id, artist_id, status
             FROM dbo.commissions WITH (UPDLOCK, ROWLOCK)
             WHERE id = :id'
        );
        $stmt->execute(['id' => $commissionId]);
        $commission = $stmt->fetch();

        if (!$commission) {
            $pdo->rollBack();
            json_response(false, 'Commission record not found.', null, 404);
        }
        if ((int)$commission['artist_id'] !== (int)$user['id']) {
            $pdo->rollBack();
            json_response(false, 'You do not own this commission.', null, 403);
        }
        if (!in_array($commission['status'], ['accepted', 'inprogress', 'inreview'], true)) {
            $pdo->rollBack();
            json_response(false, 'Files cannot be added in the current workflow state.', null, 409);
        }

        $versionStmt = $pdo->prepare(
            'SELECT ISNULL(MAX(version_no), 0) + 1
             FROM dbo.commission_files
             WHERE commission_id = :commission_id'
        );
        $versionStmt->execute(['commission_id' => $commissionId]);
        $versionNo = (int)$versionStmt->fetchColumn();

        $url = '';
        $fileName = null;
        $mimeType = null;
        $fileSize = null;

        if ($mode === 'upload') {
            if (!isset($_FILES['file'])) {
                $pdo->rollBack();
                json_response(false, 'Choose an image or PSD file first.', null, 400);
            }
            $asset = validate_uploaded_art_asset($_FILES['file']);
            $url = save_uploaded_art_asset($asset, $commissionId);
            $fileName = $asset['originalName'];
            $mimeType = $asset['mimeType'];
            $fileSize = $asset['size'];
            if ($label === '') {
                $label = $fileName;
            }
        } else {
            $url = str_input($input, 'url', 2048);
            if (!allowed_http_url($url)) {
                $pdo->rollBack();
                json_response(false, 'File link must be a valid HTTP or HTTPS URL.', null, 400);
            }
        }

        $hasFileColumns = column_exists($pdo, 'commission_files', 'file_name');
        if ($hasFileColumns) {
            $insert = $pdo->prepare(
                'INSERT INTO dbo.commission_files
                 (commission_id, type, label, url, added_by, version_no, file_name, mime_type, file_size, created_at, updated_at)
                 OUTPUT INSERTED.id
                 VALUES (:commission_id, :type, :label, :url, :added_by, :version_no, :file_name, :mime_type, :file_size, SYSUTCDATETIME(), SYSUTCDATETIME())'
            );
            $insert->execute([
                'commission_id' => $commissionId,
                'type' => $type,
                'label' => $label,
                'url' => $url,
                'added_by' => (int)$user['id'],
                'version_no' => $versionNo,
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
            ]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO dbo.commission_files
                 (commission_id, type, label, url, added_by, version_no, created_at, updated_at)
                 OUTPUT INSERTED.id
                 VALUES (:commission_id, :type, :label, :url, :added_by, :version_no, SYSUTCDATETIME(), SYSUTCDATETIME())'
            );
            $insert->execute([
                'commission_id' => $commissionId,
                'type' => $type,
                'label' => $label,
                'url' => $url,
                'added_by' => (int)$user['id'],
                'version_no' => $versionNo,
            ]);
        }

        $fileId = (int)$insert->fetchColumn();
        if ($fileId <= 0) {
            throw new RuntimeException('The asset was inserted but SQL Server did not return its new ID.');
        }

        $touch = $pdo->prepare('UPDATE dbo.commissions SET updated_at = SYSUTCDATETIME() WHERE id = :id');
        $touch->execute(['id' => $commissionId]);

        $pdo->commit();

        safe_audit_log($pdo, (int)$user['id'], actor_name($user), 'Uploaded Deliverable Asset', "v{$versionNo} — {$label}");
        safe_integration_log($pdo, 'POST /api/commissions/add_file', 201, ['commissionId' => $commissionId, 'type' => $type, 'version' => $versionNo, 'mode' => $mode]);
        safe_notify($pdo, (int)$commission['client_id'], "New v{$versionNo} deliverable uploaded: {$label}", $commissionId);

        json_response(true, 'Deliverable uploaded.', [
            'id' => $fileId,
            'version' => $versionNo,
            'url' => $url,
            'fileName' => $fileName,
            'mimeType' => $mimeType,
        ], 201);
    }

    if ($action === 'add_comment') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $user = require_auth($pdo);

        $commissionId = int_input($input, 'commissionId', 1);
        $text = str_input($input, 'text', 5000);

        $stmt = $pdo->prepare(
            'SELECT TOP (1) id, client_id, artist_id
             FROM dbo.commissions
             WHERE id = :id'
        );
        $stmt->execute(['id' => $commissionId]);
        $commission = $stmt->fetch();

        if (!$commission) { json_response(false, 'Commission record not found.', null, 404); }
        if ((int)$commission['client_id'] !== (int)$user['id'] && (int)$commission['artist_id'] !== (int)$user['id']) {
            json_response(false, 'You are not a participant in this commission.', null, 403);
        }

        $pdo->beginTransaction();

        $insert = $pdo->prepare(
            'INSERT INTO dbo.commission_comments
             (commission_id, author_id, [text], created_at, updated_at)
             OUTPUT INSERTED.id
             VALUES (:commission_id, :author_id, :text, SYSUTCDATETIME(), SYSUTCDATETIME())'
        );
        $insert->execute([
            'commission_id' => $commissionId,
            'author_id' => (int)$user['id'],
            'text' => $text,
        ]);
        $commentId = (int)$insert->fetchColumn();

        $touch = $pdo->prepare('UPDATE dbo.commissions SET updated_at = SYSUTCDATETIME() WHERE id = :id');
        $touch->execute(['id' => $commissionId]);

        $pdo->commit();

        $notifyUserId = (int)$commission['client_id'] === (int)$user['id']
            ? (int)$commission['artist_id']
            : (int)$commission['client_id'];
        safe_notify($pdo, $notifyUserId, "New comment on commission #{$commissionId}", $commissionId);

        safe_audit_log($pdo, (int)$user['id'], actor_name($user), 'Posted Commission Comment', "Commission #{$commissionId}");
        safe_integration_log($pdo, 'POST /api/commissions/comment', 201, ['commissionId' => $commissionId]);

        json_response(true, 'Comment posted.', ['id' => $commentId], 201);
    }

    json_response(false, 'Unknown commission action.', null, 404);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(false, safe_error_message($e), null, 500);
}
