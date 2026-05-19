<?php
// JajaninId - Configuration

return [
    // Google OAuth - Ganti dengan credentials Anda dari https://console.cloud.google.com
    'google' => [
        'client_id'     => 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com',
        'client_secret' => 'YOUR_GOOGLE_CLIENT_SECRET',
        'redirect_uri'  => 'http://localhost:8000/backend/auth.php?action=callback',
    ],

    // Platform settings
    'platform' => [
        'name'            => 'JajaninId',
        'fee_percentage'  => 0.01, // 1%
        'min_gift_amount' => 1000,
        'max_gift_amount' => 10000000,
    ],

    // Base URL
    'base_url' => 'http://localhost:8000',
];
