<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

function paypal_config(): array
{
    $mode = strtolower(trim((string)artic_config_value('PAYPAL_MODE', 'paypal_mode', 'sandbox')));
    if (!in_array($mode, ['sandbox', 'live'], true)) $mode = 'sandbox';
    $currency = strtoupper(trim((string)artic_config_value('PAYPAL_CURRENCY', 'paypal_currency', 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'USD';
    $clientId = trim((string)artic_config_value('PAYPAL_CLIENT_ID', 'paypal_client_id', ''));
    $clientSecret = trim((string)artic_config_value('PAYPAL_CLIENT_SECRET', 'paypal_client_secret', ''));
    return [
        'mode' => $mode,
        'currency' => $currency,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'api_base' => $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com',
    ];
}

function paypal_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for PayPal integration. Enable the cURL extension.');
    }

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Unable to initialize the PayPal HTTP client.');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('PayPal network request failed: ' . ($curlError ?: 'unknown cURL error'));
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('PayPal returned an invalid JSON response (HTTP ' . $status . ').');
    }
    return ['status' => $status, 'body' => $json];
}

function paypal_access_token(array $cfg): string
{
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
        throw new RuntimeException('PayPal is not configured. Add PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET.');
    }
    $basic = base64_encode($cfg['client_id'] . ':' . $cfg['client_secret']);
    $res = paypal_request('POST', $cfg['api_base'] . '/v1/oauth2/token', [
        'Accept: application/json',
        'Accept-Language: en_US',
        'Authorization: Basic ' . $basic,
        'Content-Type: application/x-www-form-urlencoded',
    ], 'grant_type=client_credentials');
    if ($res['status'] < 200 || $res['status'] >= 300 || empty($res['body']['access_token'])) {
        $detail = $res['body']['error_description'] ?? $res['body']['message'] ?? 'OAuth authentication failed.';
        throw new RuntimeException('PayPal authentication failed: ' . $detail);
    }
    return (string)$res['body']['access_token'];
}

function paypal_error_detail(array $body): string
{
    if (!empty($body['details'][0]['description'])) return (string)$body['details'][0]['description'];
    if (!empty($body['message'])) return (string)$body['message'];
    if (!empty($body['name'])) return (string)$body['name'];
    return 'PayPal API request failed.';
}

function paypal_create_order(array $cfg, array $commission): array
{
    $token = paypal_access_token($cfg);
    $amount = number_format((float)$commission['price'], 2, '.', '');
    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'commission-' . (int)$commission['id'],
            'description' => 'Artic commission #' . (int)$commission['id'],
            'amount' => [
                'currency_code' => $cfg['currency'],
                'value' => $amount,
            ],
        ]],
        'application_context' => [
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
        ],
    ];
    $res = paypal_request('POST', $cfg['api_base'] . '/v2/checkout/orders', [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'PayPal-Request-Id: ARTIC-' . $commission['id'] . '-' . bin2hex(random_bytes(8)),
        'Prefer: return=representation',
    ], json_encode($payload, JSON_UNESCAPED_SLASHES));
    if ($res['status'] < 200 || $res['status'] >= 300 || empty($res['body']['id'])) {
        throw new RuntimeException('PayPal create order failed: ' . paypal_error_detail($res['body']));
    }
    return $res['body'];
}

function paypal_capture_order(array $cfg, string $orderId): array
{
    if (!preg_match('/^[A-Z0-9-]{5,64}$/', $orderId)) {
        throw new RuntimeException('Invalid PayPal order ID.');
    }
    $token = paypal_access_token($cfg);
    $res = paypal_request('POST', $cfg['api_base'] . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'Prefer: return=representation',
    ], '{}');
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException('PayPal capture failed: ' . paypal_error_detail($res['body']));
    }
    return $res['body'];
}

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';
    $user = require_auth($pdo);
    $cfg = paypal_config();

    if ($action === 'config') {
        json_response(true, 'PayPal configuration loaded.', [
            'enabled' => $cfg['client_id'] !== '' && $cfg['client_secret'] !== '',
            'clientId' => $cfg['client_id'],
            'currency' => $cfg['currency'],
            'mode' => $cfg['mode'],
        ]);
    }

    if ($action === 'create_order') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $user = require_role($pdo, 'client');
        $commissionId = int_input($input, 'id', 1);

        $stmt = $pdo->prepare('SELECT TOP (1) id, client_id, artist_id, status, payment_status, price FROM dbo.commissions WHERE id=:id');
        $stmt->execute(['id'=>$commissionId]);
        $commission = $stmt->fetch();
        if (!$commission || (int)$commission['client_id'] !== (int)$user['id']) json_response(false, 'Commission not found or access denied.', null, 404);
        if ($commission['status'] !== 'accepted' || $commission['payment_status'] !== 'pending') json_response(false, 'PayPal payment is only available for an accepted, unpaid commission.', null, 409);

        $existing = $pdo->prepare('SELECT TOP (1) id, paypal_order_id, status, amount, currency FROM dbo.paypal_transactions WHERE commission_id=:commission_id');
        $existing->execute(['commission_id'=>$commissionId]);
        $tx = $existing->fetch();
        if ($tx && $tx['paypal_order_id'] && in_array(strtoupper((string)$tx['status']), ['CREATED', 'APPROVED'], true) && abs((float)$tx['amount'] - (float)$commission['price']) < 0.001 && strtoupper((string)$tx['currency']) === $cfg['currency']) {
            json_response(true, 'Existing PayPal order ready.', ['paypalOrderId'=>$tx['paypal_order_id'], 'currency'=>$cfg['currency'], 'amount'=>(float)$commission['price']]);
        }

        $order = paypal_create_order($cfg, $commission);
        $orderId = (string)$order['id'];
        $status = (string)($order['status'] ?? 'CREATED');
        $now = date('Y-m-d H:i:s');

        if ($tx) {
            $update = $pdo->prepare('UPDATE dbo.paypal_transactions SET client_id=:client_id, artist_id=:artist_id, paypal_order_id=:order_id, paypal_capture_id=NULL, environment=:environment, currency=:currency, amount=:amount, status=:status, payer_email=NULL, updated_at=SYSUTCDATETIME() WHERE id=:id');
            $update->execute(['client_id'=>(int)$commission['client_id'],'artist_id'=>(int)$commission['artist_id'],'order_id'=>$orderId,'environment'=>$cfg['mode'],'currency'=>$cfg['currency'],'amount'=>(float)$commission['price'],'status'=>$status,'id'=>(int)$tx['id']]);
        } else {
            $insert = $pdo->prepare('INSERT INTO dbo.paypal_transactions (commission_id,client_id,artist_id,paypal_order_id,environment,currency,amount,status,created_at,updated_at) OUTPUT INSERTED.id VALUES (:commission_id,:client_id,:artist_id,:order_id,:environment,:currency,:amount,:status,SYSUTCDATETIME(),SYSUTCDATETIME())');
            $insert->execute(['commission_id'=>$commissionId,'client_id'=>(int)$commission['client_id'],'artist_id'=>(int)$commission['artist_id'],'order_id'=>$orderId,'environment'=>$cfg['mode'],'currency'=>$cfg['currency'],'amount'=>(float)$commission['price'],'status'=>$status]);
        }

        safe_integration_log($pdo, 'POST /api/paypal/create_order', 201, ['commissionId'=>$commissionId,'paypalOrderId'=>$orderId,'mode'=>$cfg['mode']]);
        json_response(true, 'PayPal order created.', ['paypalOrderId'=>$orderId,'currency'=>$cfg['currency'],'amount'=>(float)$commission['price']]);
    }

    if ($action === 'capture_order') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $user = require_role($pdo, 'client');
        $commissionId = int_input($input, 'id', 1);
        $orderId = str_input($input, 'orderId', 64);

        $stmt = $pdo->prepare('SELECT TOP (1) c.id, c.client_id, c.artist_id, c.status, c.payment_status, c.price, t.id AS tx_id, t.paypal_order_id, t.status AS tx_status, t.currency, t.amount FROM dbo.commissions c INNER JOIN dbo.paypal_transactions t ON t.commission_id=c.id WHERE c.id=:id');
        $stmt->execute(['id'=>$commissionId]);
        $commission = $stmt->fetch();
        if (!$commission || (int)$commission['client_id'] !== (int)$user['id']) json_response(false, 'Commission or PayPal transaction not found.', null, 404);
        if ((string)$commission['paypal_order_id'] !== $orderId) json_response(false, 'PayPal order does not belong to this commission.', null, 409);
        if ($commission['payment_status'] === 'paid') json_response(true, 'Commission payment is already recorded.', ['status'=>'COMPLETED']);

        $capture = paypal_capture_order($cfg, $orderId);
        $captureStatus = strtoupper((string)($capture['status'] ?? ''));
        if ($captureStatus !== 'COMPLETED') json_response(false, 'PayPal payment was not completed. Current PayPal status: ' . ($captureStatus ?: 'UNKNOWN'), ['paypal'=>$capture], 409);

        $captureId = (string)($capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? '');
        $captureAmount = (string)($capture['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? '');
        $captureCurrency = strtoupper((string)($capture['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] ?? ''));
        if ($captureId === '' || $captureAmount === '' || $captureCurrency !== strtoupper((string)$commission['currency'])) {
            json_response(false, 'PayPal capture verification failed. The transaction currency or capture data did not match the commission.', null, 409);
        }
        if (abs((float)$captureAmount - (float)$commission['price']) > 0.001) {
            json_response(false, 'PayPal capture amount does not match the commission total.', null, 409);
        }

        $payerEmail = $capture['payer']['email_address'] ?? null;
        $pdo->beginTransaction();
        try {
            $updateCommission = $pdo->prepare('UPDATE dbo.commissions SET payment_status=:new_status, updated_at=SYSUTCDATETIME() WHERE id=:id AND payment_status=:old_status');
            $updateCommission->execute(['new_status'=>'paid','old_status'=>'pending','id'=>$commissionId]);
            if ($updateCommission->rowCount() !== 1) throw new RuntimeException('Commission payment state changed before capture could be recorded.');

            $invoiceCheck = $pdo->prepare('SELECT TOP (1) id FROM dbo.invoices WHERE commission_id=:commission_id');
            $invoiceCheck->execute(['commission_id'=>$commissionId]);
            $invoiceId = (int)$invoiceCheck->fetchColumn();
            if (!$invoiceId) {
                $invoiceNo = 'ARTIC-' . $cfg['mode'] . '-' . date('Ymd') . '-' . str_pad((string)$commissionId, 6, '0', STR_PAD_LEFT);
                $invoiceInsert = $pdo->prepare("INSERT INTO dbo.invoices (commission_id, invoice_number, client_id, artist_id, subtotal, platform_fee, total, status, issued_at, paid_at) VALUES (:commission_id,:invoice_number,:client_id,:artist_id,:subtotal,0,:total,N'paid',SYSUTCDATETIME(),SYSUTCDATETIME()) OUTPUT INSERTED.id");
                $invoiceInsert->execute(['commission_id'=>$commissionId,'invoice_number'=>$invoiceNo,'client_id'=>(int)$commission['client_id'],'artist_id'=>(int)$commission['artist_id'],'subtotal'=>(float)$commission['price'],'total'=>(float)$commission['price']]);
                $invoiceId = (int)$invoiceInsert->fetchColumn();
            } else {
                $invoiceUpdate = $pdo->prepare("UPDATE dbo.invoices SET status=N'paid', paid_at=COALESCE(paid_at,SYSUTCDATETIME()) WHERE id=:id");
                $invoiceUpdate->execute(['id'=>$invoiceId]);
            }

            $txUpdate = $pdo->prepare('UPDATE dbo.paypal_transactions SET paypal_capture_id=:capture_id,status=:status,payer_email=:payer_email,updated_at=SYSUTCDATETIME() WHERE id=:id');
            $txUpdate->execute(['capture_id'=>$captureId,'status'=>$captureStatus,'payer_email'=>$payerEmail,'id'=>(int)$commission['tx_id']]);

            if (table_exists($pdo, 'payouts')) {
                $payoutCheck = $pdo->prepare('SELECT TOP (1) id FROM dbo.payouts WHERE commission_id=:commission_id');
                $payoutCheck->execute(['commission_id'=>$commissionId]);
                if (!$payoutCheck->fetchColumn()) {
                    $payoutRef = 'ARTIC-PENDING-' . $commissionId . '-' . strtoupper(bin2hex(random_bytes(3)));
                    $payoutInsert = $pdo->prepare("INSERT INTO dbo.payouts (commission_id, invoice_id, artist_id, amount, status, payout_reference, created_at) VALUES (:commission_id,:invoice_id,:artist_id,:amount,N'pending',:reference,SYSUTCDATETIME())");
                    $payoutInsert->execute(['commission_id'=>$commissionId,'invoice_id'=>$invoiceId,'artist_id'=>(int)$commission['artist_id'],'amount'=>(float)$commission['price'],'reference'=>$payoutRef]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        safe_audit_log($pdo, (int)$user['id'], actor_name($user), 'PayPal Payment Captured', 'Commission #' . $commissionId . ' amount: ' . $captureCurrency . ' ' . number_format((float)$captureAmount, 2));
        safe_integration_log($pdo, 'POST /api/paypal/capture_order', 200, ['commissionId'=>$commissionId,'paypalOrderId'=>$orderId,'paypalCaptureId'=>$captureId,'mode'=>$cfg['mode']]);
        safe_notify($pdo, (int)$commission['artist_id'], "PayPal payment captured for commission #{$commissionId}. Production can start!", $commissionId);

        json_response(true, 'PayPal payment captured and recorded.', ['status'=>'COMPLETED','paypalOrderId'=>$orderId,'paypalCaptureId'=>$captureId]);
    }

    json_response(false, 'Unknown PayPal action.', null, 404);
} catch (Throwable $e) {
    json_response(false, safe_error_message($e), null, 500);
}
