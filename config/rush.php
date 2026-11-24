<?php

return [
    'auth' => [
        // When true, non-test Google/Apple tokens are accepted as opaque hashes (local only).
        'allow_unsigned_social_tokens' => (bool) env('RUSH_ALLOW_UNSIGNED_SOCIAL_TOKENS', env('APP_ENV') !== 'production'),

        // Require OTP in `token` for phone register/login.
        'phone_otp_required' => (bool) env('RUSH_PHONE_OTP_REQUIRED', false),

        // Demo OTP accepted when phone_otp_required=true (also last-2-digits×2 works).
        'phone_otp_demo' => env('RUSH_PHONE_OTP_DEMO', '1234'),
    ],
];
