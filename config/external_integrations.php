<?php

return [
    // Development only: lets the outbound client reach private/loopback hosts over http.
    'allow_private_hosts' => (bool) env('EXTERNAL_INTEGRATIONS_ALLOW_PRIVATE_HOSTS', false),
];
