<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OwnerController;

use App\Http\Controllers\AdminController;

Route::get('/', function () {
    return view('welcome');
});

