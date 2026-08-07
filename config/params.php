<?php

declare(strict_types=1);

use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;

return [
    'rasuvaeff/yii3-filestorage-web' => [
        // Must contain {token}. Keep it in step with the route you register —
        // this is what goes into every URL Storage::urlFor() hands out.
        'route' => '/files/{token}',
        // The request attribute your router puts the token in.
        'tokenAttribute' => 'token',
        // Private by default: a signed URL is per-recipient, so a shared cache
        // storing it would serve one tenant's file to the next request.
        'cacheControl' => 'private, max-age=3600',
        // Rotating HMAC keys. `active` signs; every key here verifies, so a
        // retired key keeps unexpired URLs working until it is removed.
        // Generate one with: php -r "echo bin2hex(random_bytes(32));"
        'signingKeys' => [
            'active' => '',
            'keys' => [],
        ],
        // Media types forced to attachment whatever the delivery policy says.
        // Empty means the built-in list; anything here is added to it.
        'extraActiveMediaTypes' => [],
    ],
    'yiisoft/yii-console' => [
        'commands' => [],
    ],
    // Registered so `FileDownloadAction` can be referenced from a route file
    // without the application having to construct it.
    'rasuvaeff/yii3-filestorage-web/action' => FileDownloadAction::class,
];
