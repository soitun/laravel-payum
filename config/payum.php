<?php

return [
    'storage' => [
        'token' => 'files',
        'gateway_config' => 'files',
        'path' => storage_path('app/payum'),
    ],

    'route' => [
        'prefix' => 'payum',
        'as' => 'payum.',
        'middleware' => ['web'],
    ],

    'default' => 'offline',

    'drivers' => [],
];
