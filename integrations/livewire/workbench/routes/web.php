<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/', 'demo');
Route::view('/other', 'other');
Route::view('/secret', 'secret');
