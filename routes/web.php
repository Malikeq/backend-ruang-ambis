<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn() => response()->json(['app' => 'AI Lolos PTN API', 'version' => '1.0', 'status' => 'ok']));
