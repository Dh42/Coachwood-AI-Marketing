<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\BrandKitFileController;
use App\Http\Controllers\ConnectionAuthController;
use App\Http\Controllers\CutoutFileController;
use App\Http\Controllers\GmailConnectionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OutputFileController;
use App\Http\Controllers\PromptTrialFileController;
use App\Http\Controllers\RequestReferenceController;
use App\Livewire\Account;
use App\Livewire\Activity\ActivityLog;
use App\Livewire\Auth\AcceptInvitation;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\BrandKit\BrandKitPage;
use App\Livewire\Brands\BrandSettings;
use App\Livewire\Brands\CreateBrand;
use App\Livewire\Budgets\SpendPage;
use App\Livewire\Catalogue\ProductsPage;
use App\Livewire\Connections\ConnectionsIndex;
use App\Livewire\Connections\CreateConnection;
use App\Livewire\Connections\ShowConnection;
use App\Livewire\Layouts\LayoutsPage;
use App\Livewire\Models\ModelsPage;
use App\Livewire\Notices\NoticesPage;
use App\Livewire\Platforms\PlatformLibraryPage;
use App\Livewire\Prompts\PromptsPage;
use App\Livewire\Requests\NewRequest;
use App\Livewire\Requests\RequestPage;
use App\Livewire\Requests\RequestsIndex;
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
    Route::get('/notices', NoticesPage::class)->name('notices');

    Route::get('/users', ManageUsers::class)->middleware('can:users.manage')->name('users');
    Route::get('/spend', SpendPage::class)->middleware('can:budgets.manage')->name('spend');
    Route::get('/activity', ActivityLog::class)->middleware('can:activity.view')->name('activity');
    Route::get('/scheduled-tasks', ScheduledTasks::class)->middleware('can:scheduler.manage')->name('scheduled-tasks');
    Route::get('/platforms', PlatformLibraryPage::class)->middleware('can:platforms.manage')->name('platforms');
    Route::get('/models', ModelsPage::class)->middleware('can:models.manage')->name('models');
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
        Route::get('/requests', RequestsIndex::class)->name('requests');
        Route::get('/requests/new', NewRequest::class)->middleware('can:requests.create,brand')->name('requests.create');
        Route::get('/requests/{request}', RequestPage::class)->whereNumber('request')->name('requests.show');
        Route::get('/requests/{request}/references/{reference}', RequestReferenceController::class)->whereNumber('request')->whereNumber('reference')->name('requests.reference');
        Route::get('/requests/{request}/outputs/{output}/{size?}', OutputFileController::class)->whereNumber('request')->whereNumber('output')->whereIn('size', ['thumb'])->name('requests.output');
        Route::get('/products', ProductsPage::class)->name('products');
        Route::get('/products/cutouts/{image}', CutoutFileController::class)->whereNumber('image')->name('products.cutout');
        Route::get('/kit', BrandKitPage::class)->name('brand-kit');
        Route::get('/prompts', PromptsPage::class)->name('prompts');
        Route::get('/prompts/tries/{file}', PromptTrialFileController::class)->where('file', '[A-Za-z0-9]+(\.thumb)?\.(png|jpg|webp|gif)')->middleware('can:prompts.edit,brand')->name('prompts.try-file');
        Route::get('/layouts', LayoutsPage::class)->name('layouts');
        Route::get('/kit/files/{item}/{size?}', BrandKitFileController::class)->whereNumber('item')->whereIn('size', ['thumb'])->name('brand-kit.file');
        Route::get('/settings', BrandSettings::class)->name('brand-settings');
        Route::get('/settings/{area}', AreaSettingsPage::class)->name('area-settings');
    });
});
