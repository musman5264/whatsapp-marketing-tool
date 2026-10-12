<?php

// TEMPORARY (T-0006): settings for the WhatsMine capture endpoint
// (app/Modules/Integrations/Http/Controllers/WhatsMineCaptureController.php).
// Off unless WHATSMINE_CAPTURE_ENABLED=true. Remove with the controller and route once the real
// webhook contract has been recorded.
return [
    'enabled' => (bool) env('WHATSMINE_CAPTURE_ENABLED', false),
    'token' => env('WHATSMINE_CAPTURE_TOKEN'),
    'dir' => storage_path('app/whatsmine-capture'),
];
