<?php

return [
    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@ticket-management.test'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'demo' => [
        'email_domain' => env('DEMO_EMAIL_DOMAIN', 'demo.test'),
        'password' => env('DEMO_PASSWORD', 'password'),
        'admins' => (int) env('DEMO_ADMINS', 2),
        'agents' => (int) env('DEMO_AGENTS', 6),
        'tickets' => (int) env('DEMO_TICKETS', 50),
        'months' => (int) env('DEMO_MONTHS', 6),
    ],
];
