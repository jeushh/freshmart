<?php

return ['paths' => ['api/*', 'sanctum/csrf-cookie'], 'allowed_methods' => ['*'], 'allowed_origins' => [env('FRONTEND_URL', 'http://127.0.0.1:5173')], 'allowed_headers' => ['*'], 'supports_credentials' => true];
