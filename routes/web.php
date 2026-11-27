<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/login');
});

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::get('/dashboard', function () {
    return view('pages.dashboard');
})->name('dashboard');

Route::get('/imports', function () {
    return view('imports.index');
})->name('imports.index');

Route::get('/variance', function () {
    return redirect('/dashboard');
});

Route::get('/reviews', function () {
    return redirect('/dashboard');
});

Route::get('/audit-history', function () {
    return redirect('/dashboard');
});

Route::get('/users', function () {
    return redirect('/dashboard');
});
