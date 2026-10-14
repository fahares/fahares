<?php

use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\CatalogerController as AdminCatalogerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\LibraryController as AdminLibraryController;
use App\Http\Controllers\Admin\PersonController as AdminPersonController;
use App\Http\Controllers\Admin\SubjectController as AdminSubjectController;
use App\Http\Controllers\Admin\SuggestionController as AdminSuggestionController;
use App\Http\Controllers\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CatalogerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\ManuscriptController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\MySuggestionsController;
use App\Http\Controllers\NoteFileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SuggestionController;
use App\Http\Controllers\WorkController;
use Illuminate\Support\Facades\Route;

// Home Page
Route::get('/', [HomeController::class, 'index'])->name('home');

// Search Engine
Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/api/search', [SearchController::class, 'api'])->name('search.api');

// Works
Route::get('/works/{id}', [WorkController::class, 'show'])
    ->where('id', '[0-9]+.*')
    ->name('works.show');

// Manuscripts
Route::get('/manuscripts/{id}', [ManuscriptController::class, 'show'])
    ->where('id', '[0-9]+.*')
    ->name('manuscripts.show');

// People & Authorities
Route::get('/people/{id}', [PersonController::class, 'show'])
    ->where('id', '[0-9]+.*')
    ->name('people.show');

// Libraries & Archives
Route::get('/libraries', [LibraryController::class, 'index'])->name('libraries.index');
Route::get('/libraries/{id}', [LibraryController::class, 'show'])
    ->where('id', '[0-9]+.*')
    ->name('libraries.show');

// Subjects & Knowledge Taxonomy
Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects.index');
Route::get('/subjects/{id}', [SubjectController::class, 'show'])
    ->where('id', '[0-9]+.*')
    ->name('subjects.show');

// Catalogers & Bibliographers
Route::get('/catalogers', [CatalogerController::class, 'index'])->name('catalogers.index');
Route::get('/catalogers/{id}', [CatalogerController::class, 'show'])
    ->where('id', '[0-9]+.*')
    ->name('catalogers.show');

// Field Suggestions (Crowdsourced corrections)
Route::post('/suggestions', [SuggestionController::class, 'store'])
    ->middleware('throttle:20,60')
    ->name('suggestions.store');
Route::get('/api/suggestions/people', [SuggestionController::class, 'people'])
    ->middleware('throttle:120,1')
    ->name('suggestions.people');
Route::get('/api/suggestions/lookup', [SuggestionController::class, 'lookup'])
    ->middleware('throttle:120,1')
    ->name('suggestions.lookup');

// Images of research notes (access checked in the controller)
Route::get('/notes/{suggestion}/files/{index}', [NoteFileController::class, 'show'])->whereNumber('index')->name('notes.file');

// A signed-in user's own suggestions
Route::middleware('auth')->group(function () {
    Route::get('/my/suggestions', [MySuggestionsController::class, 'index'])->name('my.suggestions');
    Route::post('/my/suggestions/{suggestion}/credit', [MySuggestionsController::class, 'credit'])->name('my.suggestions.credit');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/settings/notifications', [NotificationController::class, 'settings'])->name('notifications.settings');
    Route::post('/settings/notifications', [NotificationController::class, 'saveSettings'])->name('notifications.settings.save');
});
Route::get('/notifications/unsubscribe/{user}/{type}/{channel}', [NotificationController::class, 'unsubscribe'])
    ->middleware('signed')
    ->name('notifications.unsubscribe');

// Short Permalinks (Permanent 301 Redirects)
Route::get('/w/{id}', function ($id) {
    // Merged records fall through to the show page, which follows their 301 redirect
    $work = \App\Models\Work::find((int) $id);
    return redirect()->route('works.show', $work ?? (int) $id, 301);
})->where('id', '[0-9]+')->name('works.permalink');

Route::get('/m/{id}', function ($id) {
    // Merged records fall through to the show page, which follows their 301 redirect
    $manuscript = \App\Models\Manuscript::find((int) $id);
    return redirect()->route('manuscripts.show', $manuscript ?? (int) $id, 301);
})->where('id', '[0-9]+')->name('manuscripts.permalink');

Route::get('/p/{id}', function ($id) {
    // Merged records fall through to the show page, which follows their 301 redirect
    $person = \App\Models\Person::find((int) $id);
    return redirect()->route('people.show', $person ?? (int) $id, 301);
})->where('id', '[0-9]+')->name('people.permalink');

Route::get('/l/{id}', function ($id) {
    $library = \App\Models\Library::findOrFail((int) $id);
    return redirect()->route('libraries.show', $library, 301);
})->where('id', '[0-9]+')->name('libraries.permalink');

Route::get('/s/{id}', function ($id) {
    // Merged records fall through to the show page, which follows their 301 redirect
    $subject = \App\Models\Subject::find((int) $id);
    return redirect()->route('subjects.show', $subject ?? (int) $id, 301);
})->where('id', '[0-9]+')->name('subjects.permalink');

// Dynamic Multi-part Sitemaps (Sitemap Index & Chunks)
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap-{type}-{page}.xml', [SitemapController::class, 'chunk'])
    ->where([
        'type' => 'static|works|manuscripts|people|libraries',
        'page' => '[1-9][0-9]*',
    ])
    ->name('sitemap.chunk');

// ==========================================
// Authentication Routes (Researchers & Admins)
// ==========================================
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.post');
    Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('/register', [RegisterController::class, 'register'])->name('register.post');
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// Forgotten password
Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,10')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,10')->name('password.update');
});

// Sign in with Google
Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

// Email verification (not required anywhere; it lets an account claim its earlier guest suggestions)
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:3,10')->name('verification.send');
});

// ==========================================
// Editorial & Admin Command Center Routes
// ==========================================
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    // Suggestions Desk
    Route::get('/suggestions', [AdminSuggestionController::class, 'index'])->name('suggestions.index');
    Route::get('/suggestions/{suggestion}', [AdminSuggestionController::class, 'show'])->name('suggestions.show');
    Route::post('/suggestions/{suggestion}/approve', [AdminSuggestionController::class, 'approve'])->name('suggestions.approve');
    Route::post('/suggestions/{suggestion}/reject', [AdminSuggestionController::class, 'reject'])->name('suggestions.reject');
    Route::post('/suggestions/{suggestion}/credit', [AdminSuggestionController::class, 'credit'])->name('suggestions.credit');
    Route::post('/suggestions/direct-edit', [AdminSuggestionController::class, 'directEdit'])->name('suggestions.direct-edit');
    Route::post('/suggestions/{suggestion}/note', [AdminSuggestionController::class, 'saveNote'])->name('suggestions.note');
    Route::post('/suggestions/{suggestion}/withdraw', [AdminSuggestionController::class, 'withdrawNote'])->name('suggestions.withdraw');


    // Taxonomy & Knowledge Tree
    Route::get('/subjects/tree', [AdminSubjectController::class, 'tree'])->name('subjects.tree');
    Route::match(['get', 'post'], '/subjects/merge', [AdminSubjectController::class, 'merge'])->name('subjects.merge');
    Route::post('/subjects/recount', [AdminSubjectController::class, 'recount'])->name('subjects.recount');
    Route::resource('subjects', AdminSubjectController::class);

    // Authorities / People Deduplication & Management
    Route::get('/people/search', [AdminPersonController::class, 'search'])->name('people.search');
    Route::match(['get', 'post'], '/people/merge', [AdminPersonController::class, 'merge'])->name('people.merge');
    Route::resource('people', AdminPersonController::class)->only(['index', 'edit', 'update']);

    // Catalogers & Bibliographers
    Route::resource('catalogers', AdminCatalogerController::class)->only(['index', 'edit', 'update']);

    // Libraries & Archives
    Route::resource('libraries', AdminLibraryController::class)->only(['index', 'edit', 'update']);

    // Audit Trail & Activity Logs
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');
    Route::post('/audit-logs/{log}/rollback', [AdminAuditLogController::class, 'rollback'])->name('audit-logs.rollback');

    // System Operations & Cache Management
    Route::get('/system', [AdminSystemController::class, 'index'])->name('system.index');
    Route::post('/system/clear-cache', [AdminSystemController::class, 'clearCache'])->name('system.clear-cache');
    Route::post('/system/reindex', [AdminSystemController::class, 'reindex'])->name('system.reindex');

    // User & Roles Management
    Route::resource('users', AdminUserController::class)->only(['index', 'edit', 'update', 'destroy']);
});



