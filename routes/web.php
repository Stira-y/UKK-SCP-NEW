<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return redirect('/login/login.html');
});

Route::get('/login/login.html', function () {
    $filePath = public_path('login/login.html');
    if (file_exists($filePath)) {
        return response()->file($filePath);
    }
    return redirect('/');
})->name('login');

// Fallback penyajian berkas upload (avatar, foto bukti jurnal, dsb.)
Route::get('/storage/{path}', function ($path) {
    $cleanPath = ltrim(str_replace('public/', '', $path), '/');
    $fullPath = storage_path('app/public/' . $cleanPath);
    if (!file_exists($fullPath)) {
        $altPath = storage_path($cleanPath);
        if (file_exists($altPath) && !is_dir($altPath)) {
            $fullPath = $altPath;
        } else {
            abort(404, 'File not found');
        }
    }
    return response()->file($fullPath, [
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'no-cache, private'
    ]);
})->where('path', '.*');
