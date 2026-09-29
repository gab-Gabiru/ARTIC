<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';
    $user = require_auth($pdo);

    if ($action === 'invoice') {
        $commissionId = int_input($_GET, 'id', 1);
        $stmt = $pdo->prepare('SELECT TOP (1) * FROM dbo.invoices WHERE commission_id = :commission_id');
        $stmt->execute(['commission_id' => $commissionId]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, 'No invoice has been generated for this commission yet.', null, 404);
        if ((int)$row['client_id'] !== (int)$user['id'] && (int)$row['artist_id'] !== (int)$user['id'] && $user['role'] !== 'admin') json_response(false, 'Invoice access denied.', null, 403);
        json_response(true, 'Invoice loaded.', ['invoice' => invoice_view($row)]);
    }

    if ($action === 'simulate_payout') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        if ($user['role'] !== 'artist') json_response(false, 'Artist access required.', null, 403);
        $commissionId = int_input($input, 'id', 1);
        $stmt = $pdo->prepare('SELECT TOP (1) c.id, c.artist_id, c.client_id, c.price, c.payment_status, i.id AS invoice_id FROM dbo.commissions c LEFT JOIN dbo.invoices i ON i.commission_id=c.id WHERE c.id=:id');
        $stmt->execute(['id'=>$commissionId]); $c=$stmt->fetch();
        if (!$c || (int)$c['artist_id'] !== (int)$user['id']) json_response(false, 'Commission not found.', null, 404);
        if ($c['payment_status'] !== 'released') json_response(false, 'The demo payout can only be completed after the client releases the payment.', null, 409);
        $existing=$pdo->prepare('SELECT TOP (1) id, commission_id, invoice_id, artist_id, amount, status, payout_reference, created_at, paid_at FROM dbo.payouts WHERE commission_id=:id');
        $existing->execute(['id'=>$commissionId]); $p=$existing->fetch();
        if ($p) json_response(true, 'Demo payout already completed.', ['payout'=>payout_view($p)]);
        $ref='ARTIC-DEMO-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
        $ins=$pdo->prepare('INSERT INTO dbo.payouts (commission_id, invoice_id, artist_id, amount, status, payout_reference, created_at, paid_at) OUTPUT INSERTED.id VALUES (:commission_id,:invoice_id,:artist_id,:amount,N\'paid\',:ref,SYSUTCDATETIME(),SYSUTCDATETIME())');
        $ins->execute(['commission_id'=>$commissionId,'invoice_id'=>(int)$c['invoice_id'],'artist_id'=>(int)$user['id'],'amount'=>(float)$c['price'],'ref'=>$ref]);
        $id=(int)$ins->fetchColumn();
        $q=$pdo->prepare('SELECT TOP (1) id, commission_id, invoice_id, artist_id, amount, status, payout_reference, created_at, paid_at FROM dbo.payouts WHERE id=:id');
        $q->execute(['id'=>$id]); $p=$q->fetch();
        json_response(true, 'Demo payout recorded. No real money was transferred.', ['payout'=>payout_view($p)]);
    }

    json_response(false, 'Unknown finance action.', null, 404);
} catch (Throwable $e) { json_response(false, safe_error_message($e), null, 500); }
