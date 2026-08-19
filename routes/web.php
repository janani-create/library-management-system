<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/auth/login.php');
});

Route::get('/dashboard', function () {
    return redirect('/app.php');
});

Route::get('/login.php', fn () => redirect('/auth/login.php'));
Route::get('/register.php', fn () => redirect('/auth/register.php'));
Route::get('/logout.php', fn () => redirect('/auth/logout.php'));
