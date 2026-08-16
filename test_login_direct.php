<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$req = Illuminate\Http\Request::create(
    '/api/login',
    'POST',
    [],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ],
    json_encode(['email' => 'dm328432@gmail.com', 'password' => 'wrongpass'])
);

$resp = $kernel->handle($req);
echo "STATUS: " . $resp->getStatusCode() . PHP_EOL;
echo "CONTENT: " . $resp->getContent() . PHP_EOL;
