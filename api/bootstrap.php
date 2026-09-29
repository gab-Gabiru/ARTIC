<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $pdo = db();
    $user = current_user($pdo);

    $userStmt = $pdo->query(
        'SELECT id, name, email, role, bio, profile_image_url, profile_image_name, profile_image_mime, profile_image_size, is_verified, verified_by_code, created_at, updated_at
         FROM dbo.users
         ORDER BY created_at ASC, id ASC'
    );

    $users = [];
    foreach ($userStmt as $row) {
        $users[] = public_user($row, $user && $user['role'] === 'admin');
    }

    if (table_exists($pdo, 'artist_portfolio')) {
        $portfolioStmt = $pdo->query('SELECT id, artist_id, title, description, url, file_name, mime_type, file_size, created_at FROM dbo.artist_portfolio ORDER BY created_at DESC, id DESC');
        $portfolioByArtist = [];
        foreach ($portfolioStmt as $pRow) {
            $mime = (string)($pRow['mime_type'] ?? '');
            $portfolioByArtist[(int)$pRow['artist_id']][] = [
                'id' => (int)$pRow['id'], 'artistId' => (int)$pRow['artist_id'],
                'title' => (string)$pRow['title'], 'description' => (string)($pRow['description'] ?? ''),
                'url' => (string)$pRow['url'], 'fileName' => $pRow['file_name'] ? (string)$pRow['file_name'] : null,
                'mimeType' => $pRow['mime_type'] ? $mime : null, 'fileSize' => $pRow['file_size'] === null ? null : (int)$pRow['file_size'],
                'isImage' => str_starts_with($mime, 'image/') && $mime !== 'image/vnd.adobe.photoshop',
                'isPsd' => in_array(strtolower($mime), ['image/vnd.adobe.photoshop','image/x-photoshop','application/photoshop'], true),
                'createdAt' => iso_utc((string)$pRow['created_at'])
            ];
        }
        foreach ($users as &$uRow) { $uRow['portfolio'] = $portfolioByArtist[(int)$uRow['id']] ?? []; }
        unset($uRow);
    }

    $listingStmt = $pdo->query(
        'SELECT id, artist_id, title, category, price, delivery_days,
                slots_total, slots_used, description, cover_image_url, cover_image_name, cover_image_mime, cover_image_size, created_at, updated_at
         FROM dbo.listings
         ORDER BY created_at DESC, id DESC'
    );

    $listings = [];
    foreach ($listingStmt as $row) {
        $listings[] = listing_view($row);
    }

    $commissions = [];
    $messagesByCommission = [];

    if ($user) {
        if ($user['role'] === 'admin') {
            $commissionStmt = $pdo->query(
                'SELECT id, listing_id, client_id, artist_id, status, payment_status,
                        price, brief, revisions, created_at, updated_at
                 FROM dbo.commissions
                 ORDER BY created_at DESC, id DESC'
            );
        } else {
            $commissionStmt = $pdo->prepare(
                'SELECT id, listing_id, client_id, artist_id, status, payment_status,
                        price, brief, revisions, created_at, updated_at
                 FROM dbo.commissions
                 WHERE client_id = :client_user_id OR artist_id = :artist_user_id
                 ORDER BY created_at DESC, id DESC'
            );
            $commissionStmt->execute(['client_user_id' => (int)$user['id'], 'artist_user_id' => (int)$user['id']]);
        }

        $commissionRows = $commissionStmt->fetchAll();
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $commissionRows);
        $filesByCommission = [];
        $commentsByCommission = [];
        $historyByCommission = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            if (table_exists($pdo, 'commission_files')) {
                                // Older Artic databases may not have version_no yet.
                try {
                    $fileSelect = 'id, commission_id, type, label, url, added_by, version_no, created_at';
                    if (column_exists($pdo, 'commission_files', 'file_name')) {
                        $fileSelect .= ', file_name';
                    } else {
                        $fileSelect .= ', CAST(NULL AS NVARCHAR(255)) AS file_name';
                    }
                    if (column_exists($pdo, 'commission_files', 'mime_type')) {
                        $fileSelect .= ', mime_type';
                    } else {
                        $fileSelect .= ', CAST(NULL AS NVARCHAR(120)) AS mime_type';
                    }
                    if (column_exists($pdo, 'commission_files', 'file_size')) {
                        $fileSelect .= ', file_size';
                    } else {
                        $fileSelect .= ', CAST(NULL AS BIGINT) AS file_size';
                    }
                    $fileStmt = $pdo->prepare(
                        "SELECT {$fileSelect}
                         FROM dbo.commission_files
                         WHERE commission_id IN ({$placeholders})
                         ORDER BY created_at ASC, id ASC"
                    );
                    $fileStmt->execute($ids);
                    foreach ($fileStmt as $row) {
                        $commissionId = (int)$row['commission_id'];
                        $filesByCommission[$commissionId][] = file_view($row);
                    }
                } catch (Throwable $fileError) {
                    error_log($fileError->getMessage());
                }
            }

            if (table_exists($pdo, 'commission_comments')) {
                $commentStmt = $pdo->prepare(
                    "SELECT id, commission_id, author_id, [text], created_at
                     FROM dbo.commission_comments
                     WHERE commission_id IN ({$placeholders})
                     ORDER BY created_at ASC, id ASC"
                );
                $commentStmt->execute($ids);
                foreach ($commentStmt as $row) {
                    $commentsByCommission[(int)$row['commission_id']][] = [
                        'id' => (int)$row['id'],
                        'authorId' => (int)$row['author_id'],
                        'text' => $row['text'],
                        'at' => iso_utc((string)$row['created_at']),
                    ];
                }
            }

            if (table_exists($pdo, 'commission_messages')) {
                $messageStmt = $pdo->prepare(
                    "SELECT id, commission_id, sender_id, recipient_id, [message], sent_at, read_at, attachment_url, attachment_name, attachment_mime, attachment_size
                     FROM dbo.commission_messages
                     WHERE commission_id IN ({$placeholders})
                     ORDER BY sent_at ASC, id ASC"
                );
                $messageStmt->execute($ids);
                foreach ($messageStmt as $row) {
                    $messagesByCommission[(int)$row['commission_id']][] = message_view($row);
                }
            }

            if (table_exists($pdo, 'commission_status_history')) {
                $historyStmt = $pdo->prepare(
                    "SELECT id, commission_id, from_status, to_status, actor_id, note, created_at
                     FROM dbo.commission_status_history
                     WHERE commission_id IN ({$placeholders})
                     ORDER BY created_at ASC, id ASC"
                );
                $historyStmt->execute($ids);
                foreach ($historyStmt as $row) {
                    $historyByCommission[(int)$row['commission_id']][] = [
                        'id' => (int)$row['id'],
                        'from' => $row['from_status'],
                        'to' => $row['to_status'],
                        'actorId' => $row['actor_id'] === null ? null : (int)$row['actor_id'],
                        'note' => $row['note'],
                        'at' => iso_utc((string)$row['created_at']),
                    ];
                }
            }
        }

        $invoiceByCommission = []; $payoutByCommission = []; $paypalByCommission = [];
        if ($ids && table_exists($pdo, 'invoices')) {
            $invoiceStmt = $pdo->prepare("SELECT id, commission_id, invoice_number, client_id, artist_id, subtotal, platform_fee, total, status, issued_at, paid_at FROM dbo.invoices WHERE commission_id IN ({$placeholders})");
            $invoiceStmt->execute($ids); foreach ($invoiceStmt as $ir) { $invoiceByCommission[(int)$ir['commission_id']] = invoice_view($ir); }
        }
        if ($ids && table_exists($pdo, 'payouts')) {
            $payoutStmt = $pdo->prepare("SELECT id, commission_id, invoice_id, artist_id, amount, status, payout_reference, created_at, paid_at FROM dbo.payouts WHERE commission_id IN ({$placeholders})");
            $payoutStmt->execute($ids); foreach ($payoutStmt as $pr) { $payoutByCommission[(int)$pr['commission_id']] = payout_view($pr); }
        }
        if ($ids && table_exists($pdo, 'paypal_transactions')) {
            $paypalStmt = $pdo->prepare("SELECT id, commission_id, client_id, artist_id, paypal_order_id, paypal_capture_id, environment, currency, amount, status, payer_email, created_at, updated_at FROM dbo.paypal_transactions WHERE commission_id IN ({$placeholders})");
            $paypalStmt->execute($ids); foreach ($paypalStmt as $pr) { $paypalByCommission[(int)$pr['commission_id']] = [
                'id'=>(int)$pr['id'], 'commissionId'=>(int)$pr['commission_id'], 'paypalOrderId'=>(string)$pr['paypal_order_id'], 'paypalCaptureId'=>$pr['paypal_capture_id'], 'environment'=>(string)$pr['environment'], 'currency'=>(string)$pr['currency'], 'amount'=>(float)$pr['amount'], 'status'=>(string)$pr['status'], 'payerEmail'=>$pr['payer_email'], 'createdAt'=>iso_utc((string)$pr['created_at']), 'updatedAt'=>iso_utc((string)$pr['updated_at'])
            ]; }
        }

        foreach ($commissionRows as $row) {
            $id = (int)$row['id'];
            $row['invoice'] = $invoiceByCommission[$id] ?? null;
            $row['payout'] = $payoutByCommission[$id] ?? null;
            $row['paypalTransaction'] = $paypalByCommission[$id] ?? null;
            $commissions[] = commission_view(
                $row,
                $filesByCommission[$id] ?? [],
                $commentsByCommission[$id] ?? [],
                $historyByCommission[$id] ?? []
            );
        }
    }

    $notifications = [];
    $inviteCodes = [];
    $auditlog = [];
    $integrationLogs = [];
    $currentUser = null;

    if ($user) {
        $currentUser = public_user($user, true);
        foreach ($users as $publicUserRow) { if ((int)$publicUserRow['id'] === (int)$user['id']) { $currentUser['portfolio'] = $publicUserRow['portfolio'] ?? []; break; } }

        if ($user['role'] === 'artist') {
            $currentUser['inviteCodes'] = table_exists($pdo, 'invite_codes')
                ? account_invite_codes($pdo, (int)$user['id'])
                : [];
        }

        if (table_exists($pdo, 'notifications')) {
            $notificationStmt = $pdo->prepare(
                'SELECT TOP (100) id, commission_id, message, is_read, created_at
                 FROM dbo.notifications
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC, id DESC'
            );
            $notificationStmt->execute(['user_id' => (int)$user['id']]);

            foreach ($notificationStmt as $row) {
                $notifications[] = [
                    'id' => (int)$row['id'],
                    'userId' => (int)$user['id'],
                    'message' => $row['message'],
                    'commissionId' => $row['commission_id'] === null ? null : (int)$row['commission_id'],
                    'read' => (bool)$row['is_read'],
                    'at' => iso_utc((string)$row['created_at']),
                ];
            }
        }

        if ($user['role'] === 'admin') {
            if (table_exists($pdo, 'invite_codes')) {
                $codeStmt = $pdo->query(
                    'SELECT id, code, created_by, is_used, used_by, created_at, used_at
                     FROM dbo.invite_codes
                     ORDER BY created_at DESC, id DESC'
                );
                foreach ($codeStmt as $row) {
                    $inviteCodes[] = [
                        'id' => (int)$row['id'],
                        'code' => $row['code'],
                        'createdBy' => $row['created_by'] === null ? null : (int)$row['created_by'],
                        'isUsed' => (bool)$row['is_used'],
                        'usedBy' => $row['used_by'] === null ? null : (int)$row['used_by'],
                        'createdAt' => iso_utc((string)$row['created_at']),
                        'usedAt' => $row['used_at'] ? iso_utc((string)$row['used_at']) : null,
                    ];
                }
            }

            if (table_exists($pdo, 'audit_logs')) {
                $auditStmt = $pdo->query(
                    'SELECT TOP (500) id, actor_name, action, target, created_at
                     FROM dbo.audit_logs
                     ORDER BY created_at DESC, id DESC'
                );
                foreach ($auditStmt as $row) {
                    $auditlog[] = [
                        'id' => (int)$row['id'],
                        'actor' => $row['actor_name'],
                        'action' => $row['action'],
                        'target' => $row['target'],
                        'at' => iso_utc((string)$row['created_at']),
                    ];
                }
            }

            if (table_exists($pdo, 'integration_logs')) {
                $apiStmt = $pdo->query(
                    'SELECT TOP (500) id, endpoint, status_code, payload, created_at
                     FROM dbo.integration_logs
                     ORDER BY created_at DESC, id DESC'
                );
                foreach ($apiStmt as $row) {
                    $integrationLogs[] = [
                        'id' => (int)$row['id'],
                        'endpoint' => $row['endpoint'],
                        'status' => (int)$row['status_code'],
                        'payload' => $row['payload'],
                        'at' => iso_utc((string)$row['created_at']),
                    ];
                }
            }
        }
    }

    json_response(true, 'Bootstrap data loaded.', [
        'csrf' => ensure_csrf_token(),
        'currentUser' => $currentUser,
        'db' => [
            'users' => $users,
            'listings' => $listings,
            'commissions' => $commissions,
            'inviteCodes' => $inviteCodes,
            'notifications' => $notifications,
            'auditlog' => $auditlog,
            'integrationLogs' => $integrationLogs,
            'messages' => array_merge([], ...array_values($messagesByCommission)),
        ],
    ]);
} catch (Throwable $e) {
    json_response(false, safe_error_message($e), null, 500);
}
