<?php

namespace Tests\Feature\Admin;

use App\Auth\Permissions;
use App\Models\AuditLog;
use App\Models\CatalogVolume;
use App\Models\Source;
use App\Models\SourceCitationForm;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use App\Sources\CitationForms;
use App\Sources\SourceEntrySync;
use App\Support\BibliographyLinks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * How a source is cited in the others (step 14.57, docs/plans/source-name-patterns.md): the forms in
 * source_citation_forms, imported from config/bibliography.php so that every citation reads as before; a form of a
 * new source made in the panel with a preview of what it does to the works' bibliographies, and its undo; a citing
 * source's own form over any source's; and a new source registered from the panel.
 */
class CitationFormsTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'مدیر الگو', 'email' => 'forms-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        CitationForms::forget();
    }

    protected function tearDown(): void
    {
        CitationForms::forget();
        parent::tearDown();
    }

    private function source(string $code, string $title, int $volumes, string $kind = Source::KIND_BIBLIOGRAPHY, array $last = []): Source
    {
        $source = Source::create(['code' => $code, 'kind' => $kind, 'rank' => Source::KIND_RANKS[$kind], 'title' => $title, 'volumes_count' => $volumes]);
        for ($n = 1; $n <= $volumes; $n++) {
            DB::table('source_volumes')->insert(['source_id' => $source->id, 'volume_number' => $n, 'title' => "{$title} - جلد {$n}",
                'page_last' => $last[$n - 1] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $source;
    }

    private function work(array $bibliography): Work
    {
        $work = Work::create(['primary_title' => 'اثر آزمون', 'clean_title' => 'اثر آزمون', 'slug' => 'forms-work-' . uniqid(), 'volume_number' => 1,
            'page_start' => 1, 'bibliography' => $bibliography]);
        SourceEntrySync::syncBibliography($work);

        return $work;
    }

    private function entry(Work $work, int $index = 0): object
    {
        return DB::table('source_entries')->where('legacy_type', 'work_bibliography')->where('legacy_id', $work->id)->where('legacy_index', $index)->first();
    }

    public function test_the_config_patterns_become_forms_and_every_citation_reads_as_before()
    {
        $this->source('dharia', 'الذریعة الی تصانیف الشیعة', 26);
        $this->source('kashf', 'کشف الظنون', 2);
        // «مؤلفین مشار» is not in this registry, as it was not in production's: it has no form and is read from config
        $work = $this->work(['الذریعة 90/25 و 107/8', 'کشف الظنون 1266', 'دنا 2/207', 'مؤلفین مشار 89/1']);
        $before = array_map(fn ($e) => BibliographyLinks::segments($e), $work->bibliography);
        $config = config('bibliography.sources');
        $this->assertSame($config, CitationForms::sources(), 'with no form, config is read as it is');

        $this->artisan('sources:import-citation-forms', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, SourceCitationForm::count());
        $this->artisan('sources:import-citation-forms')->assertSuccessful();
        $this->assertSame(2, SourceCitationForm::count(), 'one form per source of the registry');
        $this->assertNull(SourceCitationForm::first()->citing_source_id, 'read in any source');

        $read = CitationForms::sources();
        $this->assertSame(array_keys($config), array_keys($read), 'a source with no registry row stays, in its place');
        foreach ($config as $code => $entry) {
            $this->assertEquals($entry, $read[$code], $code);
        }
        $this->assertSame('mushar-authors', collect(BibliographyLinks::segments('مؤلفین مشار 89/1'))->pluck('cite.source')->filter()->first());
        $this->assertSame($before, array_map(fn ($e) => BibliographyLinks::segments($e), $work->bibliography));
        $this->artisan('sources:import-citation-forms', ['--check' => true])->assertSuccessful()->expectsOutputToContain('0 differ');

        $this->artisan('sources:import-citation-forms')->assertSuccessful()->expectsOutputToContain('Made 0 forms');
        $this->artisan('sources:import-citation-forms', ['--revert' => true])->assertSuccessful();
        $this->assertSame(0, SourceCitationForm::count());
    }

    public function test_a_form_of_a_new_source_is_previewed_saved_and_undone()
    {
        $dena = $this->source('dena-test', 'فهرستواره دستنوشته‌های ایران', 2, last: [800, 1200]);
        $work = $this->work(['دنا 809/1', 'دنا 994/2-995 (51 نسخه)', 'دنا 2000/1', 'الاوقاف العامة 12/3']);
        $this->assertNull($this->entry($work)->source_id);
        $url = route('admin.sources.citations.store', $dena);
        $form = ['context' => 'bibliography', 'citing_source_id' => '', 'names' => "دنا\nفهرستواره دستنوشته های ایران", 'volumes' => '', 'notes' => 'چاپ اول'];

        // the preview tells and saves nothing: 994/2 is read, 809/1 and 2000/1 are past volume 1's 800 pages
        $this->actingAs($this->admin)->post($url, $form + ['preview' => 1])->assertRedirect()
            ->assertSessionHas('citation_preview', fn ($p) => $p['gained'] === 1 && $p['unread'] === 2 && $p['works'] === 1 && $p['read'] === 3);
        $this->assertSame(0, SourceCitationForm::count());
        $this->actingAs($this->admin)->get(route('admin.sources.citations.create', $dena))->assertOk()
            ->assertSee('پیش‌نمایش: هنوز چیزی ذخیره نشده است.')->assertSee('۱ رشته‌ی مأخذ به این منبع وصل می‌شود');

        $this->actingAs($this->admin)->post($url, $form)->assertRedirect(route('admin.sources.show', $dena));
        $made = SourceCitationForm::sole();
        $this->assertSame(['دنا', 'فهرستواره دستنوشته های ایران'], $made->names);
        $this->assertSame([$dena->id, 2, 994], [(int) $this->entry($work, 1)->source_id, (int) $this->entry($work, 1)->volume_number, (int) $this->entry($work, 1)->page_start]);
        $this->assertSame(SourceVolume::where('source_id', $dena->id)->where('volume_number', 2)->value('id'), (int) $this->entry($work, 1)->source_volume_id);
        $this->assertNull($this->entry($work, 3)->source_id);
        $this->assertStringNotContainsString('<a', (string) BibliographyLinks::html('دنا 994/2'), 'a source the site shows nothing of is read, not linked');
        $this->actingAs($this->admin)->get(route('admin.sources.show', $dena))->assertOk()
            ->assertSee('ارجاع به این منبع در منابع دیگر')->assertSee('هر منبع')->assertSee('۱ رشته‌ی تازه‌وصل');

        // its long name only: «دنا» is no longer read and the string comes away; then «دنا» only, and it is back
        $update = route('admin.sources.citations.update', [$dena, $made]);
        $this->actingAs($this->admin)->put($update, ['names' => 'فهرستواره دستنوشته های ایران', 'preview' => 1] + $form)
            ->assertSessionHas('citation_preview', fn ($p) => $p['lost'] === 1);
        $this->actingAs($this->admin)->put($update, ['names' => 'فهرستواره دستنوشته های ایران'] + $form);
        $this->assertNull($this->entry($work, 1)->source_id);
        $this->actingAs($this->admin)->put($update, ['names' => 'دنا'] + $form);
        $this->assertSame($dena->id, (int) $this->entry($work, 1)->source_id);

        // undone from the source page, latest first; then from the audit log; then the form itself
        $logs = AuditLog::where('auditable_type', SourceCitationForm::class)->orderBy('id')->get();
        $this->assertSame(['create', 'update', 'update'], $logs->pluck('action')->all());
        $this->actingAs($this->admin)->post(route('admin.sources.citations.undo', $logs[0]))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.sources.citations.undo', $logs[2]))->assertSessionHas('success');
        $this->assertNull($this->entry($work, 1)->source_id);
        $this->assertSame(['فهرستواره دستنوشته های ایران'], $made->fresh()->names);
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$logs[1]->id}/rollback")->assertSessionHas('success');
        $this->assertSame([$dena->id, ['دنا', 'فهرستواره دستنوشته های ایران']], [(int) $this->entry($work, 1)->source_id, $made->fresh()->names]);
        $this->actingAs($this->admin)->post(route('admin.sources.citations.undo', $logs[0]))->assertSessionHas('success');
        $this->assertNull(SourceCitationForm::find($made->id));
        $this->assertNull($this->entry($work, 1)->source_id);
        $this->assertNull($this->entry($work, 1)->volume_number);
        $this->assertSame([], CitationForms::sources() === config('bibliography.sources') ? [] : ['the config is read again']);
    }

    public function test_no_form_is_saved_before_the_config_patterns_are_imported()
    {
        $dharia = $this->source('dharia', 'الذریعة الی تصانیف الشیعة', 26);
        $dena = $this->source('dena-test', 'فهرستواره دستنوشته‌های ایران', 2);
        $this->actingAs($this->admin)->get(route('admin.sources.citations.create', $dena))->assertSee('sources:import-citation-forms');
        $this->actingAs($this->admin)->post(route('admin.sources.citations.store', $dena), ['context' => 'bibliography', 'names' => 'دنا'])
            ->assertSessionHas('error');
        $this->assertSame(0, SourceCitationForm::count());
        $this->assertArrayHasKey('dharia', CitationForms::sources());
    }

    public function test_a_citing_sources_own_form_is_read_in_its_page_texts_only()
    {
        $dharia = $this->source('dharia', 'الذریعة الی تصانیف الشیعة', 26);
        $kashf = $this->source('kashf', 'کشف الظنون', 2);
        $this->artisan('sources:import-citation-forms')->assertSuccessful();
        // al-Dhari'a writes Kashf al-Zunun as «الكشف» too
        SourceCitationForm::create(['cited_source_id' => $kashf->id, 'citing_source_id' => $dharia->id, 'context' => 'page_text',
            'names' => ['کشف الظنون', 'الکشف'], 'options' => ['volumes' => 2, 'unit' => 'ستون']]);
        CitationForms::forget();

        $cited = fn (array $segments) => array_values(array_filter(array_column($segments, 'cite')));
        $this->assertSame([['source' => 'kashf', 'volume' => 2, 'page' => 1266]], $cited(BibliographyLinks::inProse('كما في الكشف 1266 وغيره', 'dharia')));
        $this->assertSame([], $cited(BibliographyLinks::inProse('كما في الكشف 1266 وغيره', 'kashf')), 'another source does not write it so');
        $this->assertSame([], $cited(BibliographyLinks::segments('الکشف 1266')), 'nor Fankha in its bibliographies');
        $this->assertSame([['source' => 'kashf', 'volume' => 2, 'page' => 1266]], $cited(BibliographyLinks::inProse('کشف الظنون 1266', 'monzavi')));

        $this->actingAs($this->admin)->get(route('admin.sources.show', $dharia))->assertOk()->assertSee('ارجاع‌های این منبع به منابع دیگر')->assertSee('الکشف');
        $this->actingAs($this->admin)->post(route('admin.sources.citations.store', $kashf), ['context' => 'page_text', 'names' => 'کشف'])
            ->assertSessionHasErrors('citing_source_id');
    }

    public function test_a_new_source_is_registered_after_its_look_for_a_duplicate()
    {
        $this->source('existing-test', 'فهرست نسخه‌های خطی فارسی', 6);
        $url = route('admin.sources.store');
        $values = ['code' => 'monzavi-persian', 'kind' => 'bibliography', 'title' => 'فهرست نسخه های خطی فارسی', 'short_name' => 'نسخه‌های منزوی',
            'unit' => 'صفحه', 'volumes' => 3, 'last_pages' => "1000\n2000\n3000", 'runs_on' => 1];

        $this->actingAs($this->admin)->post($url, $values)->assertRedirect()->assertSessionHas('similar');
        $this->assertNull(Source::where('code', 'monzavi-persian')->first());
        $this->actingAs($this->admin)->post($url, ['code' => 'Bad Code'] + $values)->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post($url, ['last_pages' => '1000'] + $values + ['despite_similar' => 1])->assertSessionHasErrors('last_pages');

        $this->actingAs($this->admin)->post($url, $values + ['despite_similar' => 1]);
        $made = Source::where('code', 'monzavi-persian')->sole();
        $this->assertSame([3, Source::KIND_RANKS['bibliography'], false], [$made->volumes_count, $made->rank, $made->hasPageImages()]);
        $this->assertSame([[1, 1, 1000], [2, 1001, 2000], [3, 2001, 3000]], SourceVolume::where('source_id', $made->id)->orderBy('volume_number')
            ->get()->map(fn ($v) => [$v->volume_number, $v->page_first, $v->page_last])->all(), 'pages that run on through the volumes');
        $this->assertSame(1, AuditLog::where('auditable_type', Source::class)->where('auditable_id', $made->id)->where('action', 'create')->count());

        // a library's catalogue is a catalog volume too, under the same id
        $this->actingAs($this->admin)->post($url, ['code' => 'lib-cat-test', 'kind' => 'library_catalogue', 'title' => 'فهرست کتابخانه آزمون یکتا', 'unit' => 'صفحه', 'volumes' => 2]);
        $catalogue = Source::where('code', 'lib-cat-test')->sole();
        $ids = SourceVolume::where('source_id', $catalogue->id)->pluck('id');
        $this->assertSame(2, CatalogVolume::whereIn('id', $ids)->count());
        $this->assertSame(1, Source::where('title', 'like', 'فهرست کتابخانه آزمون یکتا%')->count(), 'no source of its own made for its volumes');

        // the access matrix: the sources' editor does not register sources nor edit their forms
        $editor = User::create(['name' => 'ویراستار', 'email' => 'forms-editor@fahares.net', 'password' => 'password123', 'role' => 'editor']);
        Permissions::save(['editor' => ['panel.access', 'sources.edit']]);
        $this->actingAs($editor)->get(route('admin.sources.create'))->assertForbidden();
        $this->actingAs($editor)->post(route('admin.sources.citations.store', $made), ['context' => 'bibliography', 'names' => 'منزوی'])->assertForbidden();
        $this->actingAs($editor)->get(route('admin.sources.show', $made))->assertOk()->assertDontSee('افزودن الگو');
    }
}
