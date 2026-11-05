<?php

return [
    'push_enabled' => env('MEDICORE_PUSH_ENABLED', false),
    // Fichier de compte de service privé, jamais fourni au client Flutter.
    'firebase_credentials' => env('MEDICORE_FIREBASE_CREDENTIALS'),
];
