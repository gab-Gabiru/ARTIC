<?php
return [
    'server' => 'localhost\\SQLEXPRESS03',
    'port' => '',
    'database' => 'artic',
    'username' => 'sa',
    'password' => null,
    'trusted_connection' => true,
    'encrypt' => true,
    'trust_server_certificate' => true,
    'login_timeout' => 5,
    'allow_windows_fallback' => true,

    // PayPal Checkout. Keep the client secret out of GitHub. Use sandbox while developing.
    'paypal_mode' => 'sandbox',
    'paypal_client_id' => '',
    'paypal_client_secret' => '',
    'paypal_currency' => 'USD',
];
