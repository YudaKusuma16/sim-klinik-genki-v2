<?php

return [

    'paths' => [
        resource_path('views'),
        env('FRONTEND_PATH', base_path('../simklinik-frontend')) . '/resources/views',
    ],

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),

];
