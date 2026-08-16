<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AdminController;

Route::get('/', function () {
    return response()->json([
        'status' => 'online',
        'service' => 'FastNet Stays API Backend',
        'version' => '1.0.0',
        'documentation' => '/api/properties'
    ]);
});

