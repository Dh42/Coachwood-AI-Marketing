<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\ConnectionAuthController;
use App\Http\Controllers\GmailConnectionController;
use App\Http\Controllers\HomeController;
use App\Livewire\Account;
use App\Livewire\Activity\ActivityLog;
use App\Livewire\Auth\AcceptInvitation;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Brands\BrandSettings;
use App\Livewire\Brands\CreateBrand;
use App\Livewire\Catalogue\ProductsPage;
use App\Livewire\Connections\ConnectionsIndex;
use App\Livewire\Connections\CreateConnection;
use App\Livewire\Connections\ShowConnection;
use App\Livewire\Scheduler\ScheduledTasks;
use App\Livewire\Settings\AreaSettingsPage;
use App\Livewire\Settings\PlatformSettings;
use App\Livewire\Users\ManageUsers;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});

// Open to guests and signed-in users: the page handles both.
Route::get('/invitations/{token}', AcceptInvitation::class)->name('invitations.show');

Route::middleware('auth')->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::get('/', HomeController::class)->name('home');
    Route::get('/account', Account::class)->name('account');

    Route::get('/users', ManageUsers::class)->middleware('can:users.manage')->name('users');
    Route::get('/activity', ActivityLog::class)->middleware('can:activity.view')->name('activity');
    Route::get('/scheduled-tasks', ScheduledTasks::class)->middleware('can:scheduler.manage')->name('scheduled-tasks');
    Route::middleware('can:settings.manage')->group(function () {
        Route::get('/settings', PlatformSettings::class)->name('settings');
        Route::get('/settings/email/connect', [GmailConnectionController::class, 'connect'])->name('settings.email.connect');
        Route::get('/settings/email/callback', [GmailConnectionController::class, 'callback'])->name('settings.email.callback');
    });
    Route::middleware('can:connections.manage')->group(function () {
        Route::get('/connections', ConnectionsIndex::class)->name('connections');
        Route::get('/connections/new', CreateConnection::class)->name('connections.create');
        Route::get('/connections/oauth/callback', [ConnectionAuthController::class, 'callback'])->name('connections.oauth.callback');
        Route::get('/connections/{connection}', ShowConnection::class)->whereNumber('connection')->name('connections.show');
        Route::get('/connections/{connection}/sign-in', [ConnectionAuthController::class, 'start'])->whereNumber('connection')->name('connections.authorize');
    });

    Route::get('/brands/new', CreateBrand::class)->middleware('can:brands.create')->name('brands.create');

    // Screens that work on one brand. The permission is checked against that
    // brand, so a role held in just this brand counts.
    Route::prefix('/brands/{brand}')->middleware('can:brands.view,brand')->group(function () {
        Route::get('/products', ProductsPage::class)->name('products');
        Route::get('/settings', BrandSettings::class)->name('brand-settings');
        Route::get('/settings/{area}', AreaSettingsPage::class)->name('area-settings');
    });
});
