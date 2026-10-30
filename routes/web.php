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
use App\Http\Controllers\Admin\SearchStatsController as AdminSearchStatsController;
use App\Http\Controllers\Admin\SubjectController as AdminSubjectController;
use App\Http\Controllers\Admin\SuggestionController as AdminSuggestionController;
use App\Http\Controllers\Admin\StructureController as AdminStructureController;
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
// The result opened from a search page, for the admin's search stats (backlog item 83)
// The results as a file (backlog item 83); limited, as a bulk download is the costliest request of the site
Route::get('/search/export', [SearchController::class, 'export'])->middleware('throttle:10,1')->name('search.export');
// A larger file built in the queue for a signed-in user, and its signed, expiring link
Route::post('/search/export/queue', [SearchController::class, 'queueExport'])->middleware(['auth', 'throttle:3,10'])->name('search.export.queue');
Route::get('/search/export/file/{file}', [SearchController::class, 'downloadExport'])->middleware('signed')->name('search.export.file');
Route::post('/search/click', [SearchController::class, 'click'])->middleware('throttle:60,1')->name('search.click');

// Statistical insights: figures built nightly by fahares:build-insights
Route::get('/insights', [InsightsController::class, 'index'])->name('insights');

// Works
Route::get('/works/{id}', [WorkController::class, 'show'])
    ->where('id', '[0-9]+.*')
    ->name('works.show');

// «منابع و مراجع»: the sources by kind, and one source with its volumes (step 13.79)
Route::get('/sources', [\App\Http\Controllers\SourceController::class, 'index'])->name('sources.index');
Route::get('/sources/{code}', [\App\Http\Controllers\SourceController::class, 'show'])
    ->where('code', '[a-z0-9-]+')
    ->name('sources.show');

// The text of a printed page of a source: /sources/monzavi/5/page/3366 (plan docs/plans/source-page-text.md)
Route::get('/sources/{code}/{volume}/page/{page}', [\App\Http\Controllers\SourcePageController::class, 'show'])
    ->where(['code' => '[a-z0-9-]+', 'volume' => '[0-9]+', 'page' => '[0-9]+'])
    ->name('source-pages.show');

// The printed entries of a source (al-Dhari'a): /sources/dharia/3/1442, «1345-2» for a number printed twice,
// «1442.1» for the first unnumbered entry after 1442
Route::get('/sources/{code}/{volume}/{key}', [\App\Http\Controllers\SourceItemController::class, 'show'])
    ->where(['code' => '[a-z0-9-]+', 'volume' => '[0-9]+', 'key' => '[0-9p][0-9.\-]*'])
    ->name('source-items.show');

// The page range of a volume's scanned pages, for the page viewer (backlog item 94)
Route::get('/api/page-images/{code}/{volume}', [\App\Http\Controllers\PageImagesController::class, 'show'])
    ->where(['code' => '[a-z0-9-]+', 'volume' => '[0-9]+'])
    ->name('page-images.show');

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
    Route::post('/settings/search-history', [ProfileController::class, 'searchHistory'])->name('profile.search-history');
    Route::post('/settings/profile/keep-name', [ProfileController::class, 'keepName'])->name('profile.keep-name');
    Route::get('/account/complete', [ProfileController::class, 'complete'])->name('account.complete');
    Route::post('/account/complete', [ProfileController::class, 'saveComplete'])->name('account.complete.save');
    Route::post('/settings/profile/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:20,60')->name('profile.avatar');
    Route::delete('/settings/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
    Route::post('/my/suggestions/{suggestion}/credit', [MySuggestionsController::class, 'credit'])->name('my.suggestions.credit');
    // Letters with the site's staff (backlog item 91)
    Route::get('/my/messages', [\App\Http\Controllers\MessagesController::class, 'index'])->name('my.messages');
    Route::get('/my/messages/{thread}', [\App\Http\Controllers\MessagesController::class, 'show'])->name('my.messages.show');
    Route::post('/my/messages/{thread}', [\App\Http\Controllers\MessagesController::class, 'reply'])->middleware('throttle:20,60')->name('my.messages.reply');
    Route::post('/my/messages/{thread}/report', [\App\Http\Controllers\MessagesController::class, 'report'])->middleware('throttle:10,60')->name('my.messages.report');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/settings/notifications', [NotificationController::class, 'settings'])->name('notifications.settings');
    Route::post('/settings/notifications', [NotificationController::class, 'saveSettings'])->name('notifications.settings.save');
    Route::post('/push-subscriptions', [\App\Http\Controllers\PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('push.subscribe');
    Route::post('/settings/telegram', [\App\Http\Controllers\TelegramLinkController::class, 'link'])->name('telegram.link');
    Route::delete('/settings/telegram', [\App\Http\Controllers\TelegramLinkController::class, 'unlink'])->name('telegram.unlink');
    Route::post('/push-subscriptions/test', [\App\Http\Controllers\PushSubscriptionController::class, 'test'])->middleware('throttle:5,1')->name('push.test');
    Route::delete('/push-subscriptions', [\App\Http\Controllers\PushSubscriptionController::class, 'destroy'])->middleware('throttle:20,1')->name('push.unsubscribe');
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
        Route::get('/suggestions/{suggestion}/convert-fields', [AdminSuggestionController::class, 'convertFields'])->name('suggestions.convert-fields');
        Route::post('/suggestions/{suggestion}/resolve', [AdminSuggestionController::class, 'resolveFeedback'])->name('suggestions.resolve');
        Route::post('/suggestions/{suggestion}/ask', [AdminSuggestionController::class, 'ask'])->middleware('throttle:30,60')->name('suggestions.ask');
    });
    Route::post('/suggestions/direct-edit', [AdminSuggestionController::class, 'directEdit'])->middleware('can:suggestions.direct_edit')->name('suggestions.direct-edit');

    // Structural tools run directly, with a preview and confirmation, through the same code as an approved suggestion (backlog item 45)
    Route::middleware('can:structure.direct')->group(function () {
        Route::get('/structure', [AdminStructureController::class, 'index'])->name('structure.index');
        Route::get('/structure/{type}/{id}', [AdminStructureController::class, 'show'])->whereIn('type', array_keys(\App\Suggestions\SuggestionRegistry::TYPES))->whereNumber('id')->name('structure.show');
        Route::post('/structure/{type}/{id}', [AdminStructureController::class, 'apply'])->whereIn('type', array_keys(\App\Suggestions\SuggestionRegistry::TYPES))->whereNumber('id')->name('structure.apply');
    });

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

        // Works tied by machine to a printed entry of a bibliography (step 13.72): verify, reject or move to another entry
        Route::get('/source-links', [\App\Http\Controllers\Admin\SourceLinkController::class, 'index'])->name('source-links.index');
        Route::get('/source-links/{entry}', [\App\Http\Controllers\Admin\SourceLinkController::class, 'show'])->name('source-links.show');
        Route::post('/source-links/{entry}/review', [\App\Http\Controllers\Admin\SourceLinkController::class, 'review'])->name('source-links.review');
        Route::post('/source-links/{entry}/move', [\App\Http\Controllers\Admin\SourceLinkController::class, 'move'])->name('source-links.move');
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

    // What people search for (backlog item 83)
    Route::get('/search-stats', [AdminSearchStatsController::class, 'index'])->middleware('can:search.stats')->name('search-stats.index');

    // System Operations & Cache Management
    Route::middleware('can:system.manage')->group(function () {
        Route::get('/system', [AdminSystemController::class, 'index'])->name('system.index');
        Route::post('/system/clear-cache', [AdminSystemController::class, 'clearCache'])->name('system.clear-cache');
        Route::post('/system/reindex', [AdminSystemController::class, 'reindex'])->name('system.reindex');
    });

    // User & Roles Management
    Route::resource('users', AdminUserController::class)->only(['index', 'edit', 'update', 'destroy'])->middleware('can:users.manage');

    // Direct messages to users and their templates (backlog item 91)
    Route::middleware('can:users.message')->prefix('messages')->name('messages.')->controller(\App\Http\Controllers\Admin\MessageController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:30,60')->name('store');
        Route::get('/templates', 'templates')->name('templates');
        Route::put('/templates/{template}', 'updateTemplate')->name('templates.update');
        Route::get('/{thread}', 'show')->whereNumber('thread')->name('show');
        Route::post('/{thread}', 'reply')->whereNumber('thread')->name('reply');
        Route::post('/{thread}/toggle', 'toggle')->whereNumber('thread')->name('toggle');
    });

    // The access matrix
    Route::middleware('can:roles.manage')->group(function () {
        Route::get('/roles', [AdminRoleController::class, 'index'])->name('roles.index');
        Route::put('/roles', [AdminRoleController::class, 'update'])->name('roles.update');
        Route::post('/roles/reset', [AdminRoleController::class, 'reset'])->name('roles.reset');
    });
});

// Telegram posts here when a user follows the link of the settings page (backlog item 25); checked by its secret header
// Resend posts each email received at the reply domain here (backlog item 91); checked by its signature
Route::post('/messages/inbound', [\App\Http\Controllers\InboundEmailController::class, 'store'])->middleware('throttle:120,1')->name('messages.inbound');
Route::post('/telegram/webhook', [\App\Http\Controllers\TelegramLinkController::class, 'webhook'])->middleware('throttle:60,1')->name('telegram.webhook');
