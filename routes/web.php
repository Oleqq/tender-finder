<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('welcome');

Route::get('/onboarding', fn () => Inertia::render('Onboarding'))->name('onboarding');
Route::get('/consents', fn () => Inertia::render('Consents'))->name('consents');
Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
Route::get('/tenders', fn () => Inertia::render('Tenders'))->name('tenders');
Route::get('/profile', fn () => Inertia::render('Profile'))->name('profile');
