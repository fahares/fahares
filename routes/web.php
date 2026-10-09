<?php

use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\CatalogerController as AdminCatalogerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\LibraryController as AdminLibraryController;
use App\Http\Controllers\Admin\PersonController as AdminPersonController;
use App\Http\Controllers\Admin\ScholarlyAnnotationController as AdminAnnotationController;
use App\Http\Controllers\Admin\SubjectController as AdminSubjectController;
use App\Http\Controllers\Admin\SuggestionController as AdminSuggestionController;
use App\Http\Controllers\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
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
Route::post('/suggestions', [SuggestionController::class, 'store'])->name('suggestions.store');

// Short Permalinks (Permanent 301 Redirects)
Route::get('/w/{id}', function ($id) {
    $work = \App\Models\Work::findOrFail((int) $id);
    return redirect()->route('works.show', $work, 301);
})->where('id', '[0-9]+')->name('works.permalink');

Route::get('/m/{id}', function ($id) {
    $manuscript = \App\Models\Manuscript::findOrFail((int) $id);
    return redirect()->route('manuscripts.show', $manuscript, 301);
})->where('id', '[0-9]+')->name('manuscripts.permalink');

Route::get('/p/{id}', function ($id) {
    $person = \App\Models\Person::findOrFail((int) $id);
    return redirect()->route('people.show', $person, 301);
})->where('id', '[0-9]+')->name('people.permalink');

Route::get('/l/{id}', function ($id) {
    $library = \App\Models\Library::findOrFail((int) $id);
    return redirect()->route('libraries.show', $library, 301);
})->where('id', '[0-9]+')->name('libraries.permalink');

Route::get('/s/{id}', function ($id) {
    $subject = \App\Models\Subject::findOrFail((int) $id);
    return redirect()->route('subjects.show', $subject, 301);
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

    // Scholarly Annotations Apparatus
    Route::get('/annotations', [AdminAnnotationController::class, 'index'])->name('annotations.index');
    Route::post('/annotations/{annotation}/toggle-public', [AdminAnnotationController::class, 'togglePublic'])->name('annotations.toggle-public');
    Route::delete('/annotations/{annotation}', [AdminAnnotationController::class, 'destroy'])->name('annotations.destroy');

    // Taxonomy & Knowledge Tree
    Route::get('/subjects/tree', [AdminSubjectController::class, 'tree'])->name('subjects.tree');
    Route::match(['get', 'post'], '/subjects/merge', [AdminSubjectController::class, 'merge'])->name('subjects.merge');
    Route::post('/subjects/recount', [AdminSubjectController::class, 'recount'])->name('subjects.recount');
    Route::resource('subjects', AdminSubjectController::class);

    // Authorities / People Deduplication & Management
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



