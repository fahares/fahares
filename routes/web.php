<?php

use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\CatalogerController as AdminCatalogerController;
use App\Http\Controllers\Admin\CatalogReferenceController as AdminCatalogReferenceController;
use App\Http\Controllers\Admin\PersonReviewController as AdminPersonReviewController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\HomePageController as AdminHomePageController;
use App\Http\Controllers\Admin\LibraryController as AdminLibraryController;
use App\Http\Controllers\Admin\PageController as AdminPageController;
use App\Http\Controllers\Admin\PersonController as AdminPersonController;
use App\Http\Controllers\Admin\PostController as AdminPostController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
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
use App\Http\Controllers\PageController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\MySuggestionsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ContributionsController;
use App\Http\Controllers\ContributeController;
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

// Statistical insights: figures built nightly by fahares:build-insights
Route::get('/insights', [InsightsController::class, 'index'])->name('insights');

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

// Posts: reports, introductions to manuscripts and other writings published from the admin panel
Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
Route::get('/posts/{id}', [PostController::class, 'show'])
    ->where('id', '[0-9]+.*')
    ->name('posts.show');

// Fixed pages whose text is edited in the admin panel
Route::get('/about', [PageController::class, 'about'])->name('about');

// Approved corrections, site-wide and per credited researcher (backlog item 63)
Route::get('/contributions', [ContributionsController::class, 'index'])->name('contributions.index');
Route::get('/contributors/{user}', [ContributionsController::class, 'show'])->whereNumber('user')->name('contributors.show');

// «تصحیح جمعی» (backlog item 80): the panel's review queues answered by researchers, one card at a time. The section's
// title lives in config/contributions.php and never in these addresses. Anyone may look; answering needs an account.
Route::get('/contribute', [ContributeController::class, 'index'])->name('contribute.index');
Route::get('/contribute/{queue}', [ContributeController::class, 'next'])->where('queue', '[a-z]+')->name('contribute.next');
Route::get('/contribute/{queue}/{item}', [ContributeController::class, 'show'])->where('queue', '[a-z]+')->whereNumber('item')->name('contribute.show');
Route::post('/contribute/{queue}/{item}/skip', [ContributeController::class, 'skip'])->where('queue', '[a-z]+')->whereNumber('item')
    ->middleware('throttle:120,10')->name('contribute.skip');
Route::middleware('auth')->group(function () {
    // the way in for a guest: sign in, then back to the same card
    Route::get('/contribute/{queue}/{item}/sign-in', [ContributeController::class, 'signIn'])->where('queue', '[a-z]+')->whereNumber('item')->name('contribute.sign-in');
    Route::post('/contribute/{queue}/{item}', [ContributeController::class, 'answer'])->where('queue', '[a-z]+')->whereNumber('item')
        ->middleware('throttle:40,10')->name('contribute.answer');
});

// Field Suggestions (Crowdsourced corrections); signed-in users only (backlog item 76), which covers research notes and links
Route::post('/suggestions', [SuggestionController::class, 'store'])
    ->middleware(['auth', 'throttle:20,60'])
    ->name('suggestions.store');
Route::get('/api/suggestions/people', [SuggestionController::class, 'people'])
    ->middleware('throttle:120,1')
    ->name('suggestions.people');
Route::get('/api/suggestions/lookup', [SuggestionController::class, 'lookup'])
    ->middleware('throttle:120,1')
    ->name('suggestions.lookup');

// Feedback about the site from any page
Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:10,60')->name('feedback.store');

// Images of research notes (access checked in the controller)
Route::get('/notes/{suggestion}/files/{index}', [NoteFileController::class, 'show'])->whereNumber('index')->name('notes.file');

// A signed-in user's own suggestions
Route::middleware('auth')->group(function () {
    Route::get('/my/suggestions', [MySuggestionsController::class, 'index'])->name('my.suggestions');
    Route::get('/settings/profile', [ProfileController::class, 'show'])->name('profile');
    Route::post('/settings/profile', [ProfileController::class, 'update'])->middleware('throttle:20,60')->name('profile.update');
    Route::get('/account/complete', [ProfileController::class, 'complete'])->name('account.complete');
    Route::post('/account/complete', [ProfileController::class, 'saveComplete'])->name('account.complete.save');
    Route::post('/settings/profile/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:20,60')->name('profile.avatar');
    Route::delete('/settings/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
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
    // Merged records fall through to the show page, which follows their 301 redirect
    $library = \App\Models\Library::find((int) $id);
    return redirect()->route('libraries.show', $library ?? (int) $id, 301);
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

// Email verification (not required to suggest; it publishes the name beside suggestions and claims earlier guest suggestions)
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:3,10')->name('verification.send');
});

// ==========================================
// Editorial & Admin Command Center Routes
// ==========================================
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Every section needs its permission of the access matrix (App\Auth\Permissions, backlog item 65);
    // 'admin' only lets in users who hold panel.access
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    // Suggestions Desk; approving or reopening a relational suggestion also needs the permission of its tool (see SuggestionController)
    Route::middleware('can:suggestions.review')->group(function () {
        Route::get('/suggestions', [AdminSuggestionController::class, 'index'])->name('suggestions.index');
        Route::get('/suggestions/{suggestion}', [AdminSuggestionController::class, 'show'])->name('suggestions.show');
        Route::post('/suggestions/{suggestion}/approve', [AdminSuggestionController::class, 'approve'])->name('suggestions.approve');
        Route::post('/suggestions/{suggestion}/reject', [AdminSuggestionController::class, 'reject'])->name('suggestions.reject');
        Route::post('/suggestions/{suggestion}/reopen', [AdminSuggestionController::class, 'reopen'])->name('suggestions.reopen');
        Route::post('/suggestions/{suggestion}/credit', [AdminSuggestionController::class, 'credit'])->name('suggestions.credit');
        Route::post('/suggestions/{suggestion}/note', [AdminSuggestionController::class, 'saveNote'])->name('suggestions.note');
        Route::post('/suggestions/{suggestion}/withdraw', [AdminSuggestionController::class, 'withdrawNote'])->name('suggestions.withdraw');
        Route::post('/suggestions/{suggestion}/convert', [AdminSuggestionController::class, 'convert'])->name('suggestions.convert');
        Route::post('/suggestions/{suggestion}/resolve', [AdminSuggestionController::class, 'resolveFeedback'])->name('suggestions.resolve');
    });
    Route::post('/suggestions/direct-edit', [AdminSuggestionController::class, 'directEdit'])->middleware('can:suggestions.direct_edit')->name('suggestions.direct-edit');

    // Taxonomy & Knowledge Tree
    Route::match(['get', 'post'], '/subjects/merge', [AdminSubjectController::class, 'merge'])->middleware('can:subjects.merge')->name('subjects.merge');
    Route::middleware('can:subjects.edit')->group(function () {
        Route::get('/subjects/tree', [AdminSubjectController::class, 'tree'])->name('subjects.tree');
        Route::post('/subjects/recount', [AdminSubjectController::class, 'recount'])->name('subjects.recount');
        Route::resource('subjects', AdminSubjectController::class);
    });

    // Authorities / People Deduplication & Management
    Route::middleware('can:people.merge')->group(function () {
        Route::get('/people/search', [AdminPersonController::class, 'search'])->name('people.search');
        Route::match(['get', 'post'], '/people/merge', [AdminPersonController::class, 'merge'])->name('people.merge');
    });
    Route::resource('people', AdminPersonController::class)->only(['index', 'edit', 'update'])->middleware('can:people.edit');

    // Person review queues: namesakes to merge, records holding several people to split (backlog item 78); each action checks its own permission
    Route::get('/person-reviews', [AdminPersonReviewController::class, 'index'])->name('person-reviews.index');
    Route::post('/person-reviews/{review}/merge', [AdminPersonReviewController::class, 'merge'])->name('person-reviews.merge');
    Route::post('/person-reviews/{review}/split', [AdminPersonReviewController::class, 'split'])->name('person-reviews.split');
    Route::post('/person-reviews/{review}/status', [AdminPersonReviewController::class, 'status'])->name('person-reviews.status');

    // Catalogue references matched by machine (backlog item 51)
    Route::middleware('can:catalog_refs.review')->group(function () {
        Route::get('/catalog-references', [AdminCatalogReferenceController::class, 'index'])->name('catalog-references.index');
        Route::post('/catalog-references/bulk-verify', [AdminCatalogReferenceController::class, 'bulkVerify'])->name('catalog-references.bulk-verify');
        Route::post('/catalog-references/bulk/{batch}/revert', [AdminCatalogReferenceController::class, 'revertBulk'])->name('catalog-references.bulk-revert');
        Route::post('/catalog-references/{reference}/verify', [AdminCatalogReferenceController::class, 'verify'])->name('catalog-references.verify');
        Route::post('/catalog-references/{reference}/reject', [AdminCatalogReferenceController::class, 'reject'])->name('catalog-references.reject');
        Route::post('/catalog-references/{reference}/reopen', [AdminCatalogReferenceController::class, 'reopen'])->name('catalog-references.reopen');
    });
    Route::middleware('can:source_entries.edit')->group(function () {
        Route::get('/source-entries/{entry}/text', [\App\Http\Controllers\Admin\SourceEntryController::class, 'text'])->name('source-entries.text');
        Route::post('/source-entries/{entry}/text', [\App\Http\Controllers\Admin\SourceEntryController::class, 'update'])->name('source-entries.update');
    });

    // Catalogers & Bibliographers
    Route::resource('catalogers', AdminCatalogerController::class)->only(['index', 'edit', 'update'])->middleware('can:catalogers.edit');

    // Home page content: texts, banner slides, featured images, home catalogers
    Route::middleware('can:home.manage')->group(function () {
        Route::get('/home-page', [AdminHomePageController::class, 'index'])->name('home.index');
        Route::put('/home-page/settings', [AdminHomePageController::class, 'updateSettings'])->name('home.settings');
        Route::post('/home-page/items', [AdminHomePageController::class, 'storeItem'])->name('home.items.store');
        Route::put('/home-page/items/{item}', [AdminHomePageController::class, 'updateItem'])->name('home.items.update');
        Route::delete('/home-page/items/{item}', [AdminHomePageController::class, 'destroyItem'])->name('home.items.destroy');
        Route::post('/home-page/items/{item}/move', [AdminHomePageController::class, 'moveItem'])->name('home.items.move');
        Route::post('/home-page/catalogers', [AdminHomePageController::class, 'addCataloger'])->name('home.catalogers.add');
        Route::delete('/home-page/catalogers/{cataloger}', [AdminHomePageController::class, 'removeCataloger'])->name('home.catalogers.remove');
        Route::post('/home-page/catalogers/{cataloger}/move', [AdminHomePageController::class, 'moveCataloger'])->name('home.catalogers.move');
    });

    // Posts
    Route::middleware('can:posts.manage')->group(function () {
        Route::post('/posts/{post}/images', [AdminPostController::class, 'storeImage'])->name('posts.images.store');
        Route::delete('/posts/{post}/images', [AdminPostController::class, 'destroyImage'])->name('posts.images.destroy');
        Route::resource('posts', AdminPostController::class)->except(['show']);
    });

    // Fixed pages, such as «درباره ما»
    Route::middleware('can:pages.manage')->group(function () {
        Route::get('/pages/{page}/edit', [AdminPageController::class, 'edit'])->name('pages.edit');
        Route::put('/pages/{page}', [AdminPageController::class, 'update'])->name('pages.update');
    });

    // Libraries & Archives
    Route::resource('libraries', AdminLibraryController::class)->only(['index', 'edit', 'update'])->middleware('can:libraries.edit');

    // Audit Trail & Activity Logs
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit-logs.index');
    Route::post('/audit-logs/{log}/rollback', [AdminAuditLogController::class, 'rollback'])->middleware('can:audit.rollback')->name('audit-logs.rollback');

    // System Operations & Cache Management
    Route::middleware('can:system.manage')->group(function () {
        Route::get('/system', [AdminSystemController::class, 'index'])->name('system.index');
        Route::post('/system/clear-cache', [AdminSystemController::class, 'clearCache'])->name('system.clear-cache');
        Route::post('/system/reindex', [AdminSystemController::class, 'reindex'])->name('system.reindex');
    });

    // User & Roles Management
    Route::resource('users', AdminUserController::class)->only(['index', 'edit', 'update', 'destroy'])->middleware('can:users.manage');

    // The access matrix
    Route::middleware('can:roles.manage')->group(function () {
        Route::get('/roles', [AdminRoleController::class, 'index'])->name('roles.index');
        Route::put('/roles', [AdminRoleController::class, 'update'])->name('roles.update');
        Route::post('/roles/reset', [AdminRoleController::class, 'reset'])->name('roles.reset');
    });
});



