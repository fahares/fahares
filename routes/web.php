<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\ManuscriptController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\SearchController;
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

