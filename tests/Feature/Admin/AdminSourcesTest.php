<?php

namespace Tests\Feature\Admin;

use App\Auth\Permissions;
use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\CatalogVolumePart;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use App\Support\Fa;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The sources registry in the admin panel (step 14.50, backlog items 70 and 111): the list, a source's name, kind and
 * rank, a volume's library and people, the parts of a volume with a preview of their effect on the copies, and the
 * merge of two sources with its undo.
 */
class AdminSourcesTest extends TestCase
{
    private User $admin;

    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'مدیر منابع', 'email' => 'sources-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-src-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function library(string $name): Library
    {
        return Library::create(['name' => $name, 'city' => 'تهران', 'slug' => 'lib-' . uniqid()]);
    }

    private function source(string $title, string $code, ?Library $library = null, string $kind = Source::KIND_LIBRARY_CATALOGUE): Source
    {
        return Source::create(['code' => $code, 'kind' => $kind, 'rank' => Source::KIND_RANKS[$kind], 'title' => $title,
            'library_id' => $library?->id, 'volumes_count' => 0]);
    }

    private function volume(Source $source, int $number, ?string $title = null): SourceVolume
    {
        $columns = ['title' => $title ?? $source->title . ' - جلد ' . $number, 'volume_number' => $number];
        $cv = CatalogVolume::create($columns + ['library_id' => $source->library_id]);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()] + $columns);
        $source->update(['volumes_count' => $source->volumes()->count()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function copy(Library $library, SourceVolume $volume, ?int $page = null, ?Cataloger $cataloger = null): Manuscript
    {
        $copy = Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library->id, 'library' => $library->name, 'shelfmark' => (string) random_int(1, 999999),
            'catalog_volume_id' => $volume->id, 'cataloger_id' => $cataloger?->id, 'volume_number' => 1, 'page_start' => 1]);
        // the copy's citation entry, made when the copy is
        SourceEntry::updateOrCreate(['legacy_type' => 'fankha_citation', 'legacy_id' => $copy->id, 'legacy_index' => 0], ['citable_type' => Manuscript::class,
            'citable_id' => $copy->id, 'source_id' => $volume->source_id, 'source_volume_id' => $volume->id, 'volume_number' => $volume->volume_number,
            'page_start' => $page, 'origin' => SourceEntry::ORIGIN_FANKHA_CITATION]);

        return $copy;
    }

    private function cataloger(string $name): Cataloger
    {
        return Cataloger::create(['name' => $name, 'slug' => 'cat-' . uniqid()]);
    }

    public function test_the_sections_follow_the_access_matrix()
    {
        $source = $this->source('فهرست آزمون دسترسی', 'access-test');
        $volume = $this->volume($source, 1);
        $user = User::create(['name' => 'کاربر', 'email' => 'sources-user@fahares.net', 'password' => 'password123', 'role' => 'user']);
        $editor = User::create(['name' => 'ویراستار', 'email' => 'sources-editor@fahares.net', 'password' => 'password123', 'role' => 'editor']);
        Permissions::save(['editor' => ['panel.access', 'sources.edit']]);

        $this->actingAs($user)->get('/admin/sources')->assertForbidden();
        $this->actingAs($editor)->get('/admin/sources')->assertOk();
        $this->actingAs($editor)->get("/admin/sources/{$source->id}/volumes/{$volume->id}")->assertOk();
        $this->actingAs($editor)->get('/admin/sources/merge')->assertForbidden();
        $this->actingAs($editor)->post('/admin/sources/merge', [])->assertForbidden();
        $this->actingAs($editor)->get("/admin/sources/{$source->id}")->assertOk()->assertDontSee('ادغام منبع دیگر در این');

        Permissions::save(['editor' => ['panel.access']]);
        $this->actingAs($editor)->get('/admin/sources')->assertForbidden();
        $this->actingAs($editor)->put("/admin/sources/{$source->id}", ['title' => 'x', 'kind' => 'other', 'rank' => 1])->assertForbidden();
        $this->actingAs($editor)->post("/admin/sources/{$source->id}/volumes/{$volume->id}/parts", ['title' => 'x'])->assertForbidden();
        $this->assertSame('فهرست آزمون دسترسی', $source->fresh()->title);

        $this->actingAs($this->admin)->get('/admin')->assertSee(route('admin.sources.index'))->assertSee(route('admin.sources.merge'));
    }

    public function test_the_list_filters_and_counts_in_persian_digits()
    {
        $tehran = $this->library('کتابخانه مرکزی');
        $big = $this->source('فهرست بزرگ آزمون', 'big-test', $tehran);
        foreach ([1, 2, 3] as $n) {
            $this->volume($big, $n);
        }
        $this->copy($tehran, $big->volumes()->first());
        DB::table('source_pages')->insert(['source_volume_id' => $big->volumes()->first()->id, 'page' => 1, 'text' => 'متن', 'created_at' => now(), 'updated_at' => now()]);
        $bio = $this->source('تراجم آزمون', 'bio-test', null, Source::KIND_BIOGRAPHICAL);

        $this->actingAs($this->admin)->get('/admin/sources?q=آزمون')->assertOk()
            ->assertSee('فهرست بزرگ آزمون')->assertSee('تراجم آزمون')->assertSee('#' . Fa::digits($big->id))->assertSee('۱ صفحه');
        $this->actingAs($this->admin)->get('/admin/sources?q=آزمون&kind=biographical')->assertOk()
            ->assertSee('تراجم آزمون')->assertDontSee('فهرست بزرگ آزمون');
        $this->actingAs($this->admin)->get('/admin/sources?q=آزمون&volumes=many')->assertOk()
            ->assertSee('فهرست بزرگ آزمون')->assertDontSee('تراجم آزمون');
        $this->actingAs($this->admin)->get('/admin/sources?q=آزمون&text=yes&entries=some&sort=copies')->assertOk()
            ->assertSee('فهرست بزرگ آزمون')->assertDontSee('تراجم آزمون');
        $this->actingAs($this->admin)->get('/admin/sources?q=bio-test')->assertOk()->assertSee('تراجم آزمون')->assertDontSee('فهرست بزرگ آزمون');
        $this->actingAs($this->admin)->get("/admin/sources/{$big->id}")->assertOk()
            ->assertSee('جلد ۳')->assertSee('خواندنی، ۱ صفحه');
    }

    public function test_a_source_name_kind_and_rank_are_edited_logged_and_rolled_back()
    {
        $source = $this->source('فهرست پیش از ویرایش', 'edit-test');

        $this->actingAs($this->admin)->put("/admin/sources/{$source->id}", ['title' => '  فهرست   پس از ویرایش ', 'short_name' => 'فهرست کوتاه',
            'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 25, 'library_id' => ''])->assertRedirect(route('admin.sources.show', $source));
        $source->refresh();
        $this->assertSame(['فهرست پس از ویرایش', 'فهرست کوتاه', 'bibliography', 25], [$source->title, $source->short_name, $source->kind, $source->rank]);
        $log = AuditLog::where('auditable_type', Source::class)->where('auditable_id', $source->id)->sole();
        $this->assertEqualsCanonicalizing(['title' => 'فهرست پیش از ویرایش', 'short_name' => null, 'kind' => 'library_catalogue', 'rank' => 10], $log->old_values);

        $this->actingAs($this->admin)->put("/admin/sources/{$source->id}", ['title' => '', 'kind' => 'nothing', 'rank' => 'a'])
            ->assertSessionHasErrors(['title', 'kind', 'rank']);

        $this->actingAs($this->admin)->get("/admin/sources/{$source->id}")->assertSee('سابقه‌ی تغییرها')->assertSee('عنوان، نام کوتاه، نوع، رتبه');
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame(['فهرست پیش از ویرایش', 'library_catalogue', 10], [$source->fresh()->title, $source->fresh()->kind, $source->fresh()->rank]);
    }

    public function test_a_volume_library_and_people_with_the_copies_cataloger()
    {
        $library = $this->library('کتابخانه جلد');
        $source = $this->source('فهرست یک‌جلدی', 'volume-test', $library);
        $volume = $this->volume($source, 1);
        $old = $this->cataloger('فهرست‌نگار پیشین');
        $new = $this->cataloger('فهرست‌نگار تازه');
        $editor = $this->cataloger('ویراستار جلد');
        CatalogVolume::find($volume->id)->catalogers()->attach($old->id, ['role' => 'cataloger']);
        $copies = collect([1, 2])->map(fn () => $this->copy($library, $volume, 5, $old));
        $url = "/admin/sources/{$source->id}/volumes/{$volume->id}";
        $people = ['people' => [['cataloger_id' => $new->id, 'role' => 'cataloger'], ['cataloger_id' => $editor->id, 'role' => 'supervisor'], ['cataloger_id' => '', 'role' => 'cataloger']]];

        // the preview tells and saves nothing
        $this->actingAs($this->admin)->put($url, ['library_id' => $library->id, 'preview' => 1] + $people)->assertRedirect($url)
            ->assertSessionHas('preview', fn ($p) => $p['form'] === 'volume' && $p['effect']['cataloger'] === 2);
        $this->assertSame($old->id, $copies[0]->fresh()->cataloger_id);
        $this->assertSame([$old->id], CatalogVolume::find($volume->id)->catalogers()->pluck('catalogers.id')->all());
        $this->actingAs($this->admin)->get($url)->assertSee('پیش‌نمایش: هنوز چیزی ذخیره نشده است.')->assertSee('فهرست‌نگار ۲ نسخه عوض می‌شود');

        $this->actingAs($this->admin)->put($url, ['library_id' => ''] + $people)->assertRedirect($url)->assertSessionHas('effect');
        $volumeNow = CatalogVolume::find($volume->id);
        $this->assertNull($volumeNow->library_id);
        $this->assertEqualsCanonicalizing([$new->id, $editor->id], $volumeNow->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame('supervisor', $volumeNow->catalogers()->find($editor->id)->pivot->role);
        $this->assertSame([$new->id, $new->id], $copies->map(fn ($c) => $c->fresh()->cataloger_id)->all());
        $this->assertSame([0, 2], [$old->fresh()->manuscripts_count, $new->fresh()->manuscripts_count]);
        $this->assertSame(1, AuditLog::where('auditable_type', CatalogVolume::class)->where('auditable_id', $volume->id)->count());
    }

    public function test_parts_are_added_edited_and_removed_and_the_copies_follow()
    {
        $a = $this->library('کتابخانه الف');
        $b = $this->library('کتابخانه ب');
        $source = $this->source('نشریه آزمون', 'parts-test');
        $volume = $this->volume($source, 5);
        $valaei = $this->cataloger('مهدی والئی');
        $other = $this->cataloger('فهرست‌نگار دیگر');
        $early = $this->copy($a, $volume, 20);
        $late = $this->copy($a, $volume, 120);
        $ofB = $this->copy($b, $volume, 200);
        $url = "/admin/sources/{$source->id}/volumes/{$volume->id}";
        $part = ['title' => 'کتابخانه الف', 'library_id' => $a->id, 'page_first' => 8, 'page_last' => 107, 'catalogers' => [$valaei->id, '']];

        // a preview of a new part: both copies of «الف» would take it, nothing is made
        $this->actingAs($this->admin)->post("{$url}/parts", $part + ['preview' => 1])->assertRedirect("{$url}#new")
            ->assertSessionHas('preview', fn ($p) => $p['form'] === 'new' && $p['effect']['joined'] === 2 && $p['effect']['outside'] === 1 && $p['effect']['outside_before'] === 0);
        $this->assertSame(0, CatalogVolumePart::count());
        $this->assertNull($early->fresh()->catalog_volume_part_id);

        $this->actingAs($this->admin)->post("{$url}/parts", $part)->assertRedirect("{$url}#new");
        $first = CatalogVolumePart::sole();
        $this->assertSame([$first->id, $first->id, null], [$early->fresh()->catalog_volume_part_id, $late->fresh()->catalog_volume_part_id, $ofB->fresh()->catalog_volume_part_id]);
        $this->assertSame([$valaei->id, 2], [$early->fresh()->cataloger_id, $first->fresh()->manuscripts_count]);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('کتابخانه الف')->assertSee('ص ۸–۱۰۷')->assertSee('۱ بیرون از بازه');

        // a second part of the same library: now the page decides
        $this->actingAs($this->admin)->post("{$url}/parts", ['title' => 'کتابخانه الف، دنباله', 'library_id' => $a->id, 'page_first' => 108, 'page_last' => 150, 'catalogers' => [$other->id]]);
        $second = CatalogVolumePart::where('title', 'کتابخانه الف، دنباله')->sole();
        $this->assertSame([$first->id, $second->id], [$early->fresh()->catalog_volume_part_id, $late->fresh()->catalog_volume_part_id]);
        $this->assertSame($other->id, $late->fresh()->cataloger_id);

        // the first part's pages grow over the second's: the late copy is of neither alone and leaves its part
        $this->actingAs($this->admin)->put("{$url}/parts/{$first->id}", ['title' => 'کتابخانه الف', 'library_id' => $a->id, 'page_first' => 8, 'page_last' => 130,
            'catalogers' => [$valaei->id], 'preview' => 1])->assertSessionHas('preview', fn ($p) => $p['effect']['left'] === 1);
        $this->assertSame(107, $first->fresh()->page_last);
        $this->actingAs($this->admin)->put("{$url}/parts/{$first->id}", ['title' => 'کتابخانه الف', 'library_id' => $a->id, 'page_first' => 8, 'page_last' => 130, 'catalogers' => [$valaei->id]]);
        $this->assertSame([130, null], [$first->fresh()->page_last, $late->fresh()->catalog_volume_part_id]);

        $this->actingAs($this->admin)->put("{$url}/parts/{$first->id}", ['title' => 'کتابخانه الف', 'page_first' => 50, 'page_last' => 10])
            ->assertSessionHasErrorsIn('part' . $first->id, ['page_last']);

        // removing the second part gives the late copy back to the first, its library's one part
        $this->actingAs($this->admin)->delete("{$url}/parts/{$second->id}")->assertRedirect("{$url}#part{$second->id}");
        $this->assertNull(CatalogVolumePart::find($second->id));
        $this->assertSame([$first->id, $valaei->id], [$late->fresh()->catalog_volume_part_id, $late->fresh()->cataloger_id]);
        $this->assertSame([3, 'remove'], [AuditLog::where('auditable_type', CatalogVolumePart::class)->whereIn('action', ['create', 'update'])->count(),
            AuditLog::where('auditable_type', CatalogVolumePart::class)->latest('id')->value('action')]);

        // the library's page shows its part
        $this->get(route('libraries.show', $a))->assertOk()->assertSee('بخش «کتابخانه الف»', false)->assertSee('ص ۸–۱۳۰')->assertSee('مهدی والئی');
    }

    public function test_two_sources_merge_after_a_preview_and_the_merge_is_undone()
    {
        $library = $this->library('کتابخانه مرعشی');
        $kept = $this->source('فهرست کتابخانه آیت‌الله العظمی مرعشی', 'merge-keep', $library);
        $folded = $this->source('فهرست کتابخانه حضرت آیة الله العظمی مرعشی', 'merge-fold', $library);
        $k1 = $this->volume($kept, 1);
        $k2 = $this->volume($kept, 2);
        $f2 = $this->volume($folded, 2);
        $f5 = $this->volume($folded, 5);
        $cataloger = $this->cataloger('حسینی اشکوری');
        CatalogVolume::find($f2->id)->catalogers()->attach($cataloger->id, ['role' => 'cataloger']);
        $partOfDuplicate = CatalogVolumePart::create(['catalog_volume_id' => $f2->id, 'library_id' => $library->id, 'title' => 'بخش جلد تکراری', 'sort' => 1]);
        $partOfDuplicate->catalogers()->attach($cataloger->id, ['role' => 'cataloger']);
        $onDuplicate = $this->copy($library, $f2, 40);
        $onFive = $this->copy($library, $f5, 9);
        CatalogVolumePart::assign([$onDuplicate->id]);
        $this->assertSame($partOfDuplicate->id, $onDuplicate->fresh()->catalog_volume_part_id);
        $before = [
            'sources' => DB::table('sources')->whereIn('id', [$kept->id, $folded->id])->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except('updated_at')->all())->all(),
            'entries' => DB::table('source_entries')->where('legacy_type', 'fankha_citation')->whereIn('legacy_id', [$onDuplicate->id, $onFive->id])->orderBy('id')->get(['id', 'source_id', 'source_volume_id', 'volume_number'])->all(),
        ];

        $this->actingAs($this->admin)->get("/admin/sources/merge?keep={$kept->id}&fold={$folded->id}")->assertOk()
            ->assertSee('جلدهای تکراری که در جلد ماندنی ادغام می‌شوند')->assertSee('فهرست کتابخانه آیت‌الله العظمی مرعشی')
            ->assertSee('ادغام‌شونده');
        $this->actingAs($this->admin)->post('/admin/sources/merge', ['keep' => $kept->id, 'fold' => $folded->id, 'title' => 'فهرست مرعشی'])
            ->assertSessionHasErrors('confirm');
        $this->assertNotNull(Source::find($folded->id));

        $this->actingAs($this->admin)->post('/admin/sources/merge', ['keep' => $kept->id, 'fold' => $folded->id, 'title' => 'فهرست مرعشی', 'confirm' => 1])
            ->assertRedirect(route('admin.sources.show', $kept));
        $this->assertNull(Source::find($folded->id));
        $this->assertNull(SourceVolume::find($f2->id));
        $kept->refresh();
        $this->assertSame(['فهرست مرعشی', 3, ['merge-fold']], [$kept->title, $kept->volumes_count, $kept->metadata['merged_codes']]);
        $this->assertSame([1, 2, 5], $kept->volumes()->orderBy('volume_number')->pluck('volume_number')->all());
        $this->assertSame([$k2->id, $kept->id], [$onDuplicate->fresh()->catalog_volume_id, DB::table('source_entries')->where('legacy_type', 'fankha_citation')->where('legacy_id', $onDuplicate->id)->value('source_id')]);
        $this->assertSame($k2->id, $partOfDuplicate->fresh()->catalog_volume_id, 'the duplicate volume\'s part moves with its copies');
        $this->assertSame($partOfDuplicate->id, $onDuplicate->fresh()->catalog_volume_part_id);
        $this->assertSame($kept->id, SourceVolume::find($f5->id)->source_id);
        $this->get('/sources/merge-fold')->assertMovedPermanently()->assertRedirect(route('sources.show', 'merge-keep'));
        $log = AuditLog::where('auditable_type', Source::class)->where('action', 'merge')->sole();
        $this->actingAs($this->admin)->get("/admin/sources/{$kept->id}")->assertSee('برگرداندن ادغام')->assertSee('در این منبع ادغام شد');
        $this->actingAs($this->admin)->get('/admin/audit-logs')->assertOk()->assertDontSee($log->old_values['snapshot']);

        // undone: the two sources, the duplicate volume, its part, copies and entries are as before
        $this->actingAs($this->admin)->post("/admin/sources/merges/{$log->id}/undo")->assertRedirect(route('admin.sources.show', $kept->id))->assertSessionHas('success');
        $this->assertSame($before['sources'], DB::table('sources')->whereIn('id', [$kept->id, $folded->id])->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except('updated_at')->all())->all());
        $this->assertEquals($before['entries'], DB::table('source_entries')->where('legacy_type', 'fankha_citation')->whereIn('legacy_id', [$onDuplicate->id, $onFive->id])->orderBy('id')->get(['id', 'source_id', 'source_volume_id', 'volume_number'])->all());
        $this->assertSame([$folded->id, 2], [SourceVolume::find($f2->id)->source_id, SourceVolume::find($f2->id)->volume_number]);
        $this->assertSame($f2->id, $onDuplicate->fresh()->catalog_volume_id);
        $this->assertSame([$f2->id, $partOfDuplicate->id], [$partOfDuplicate->fresh()->catalog_volume_id, $onDuplicate->fresh()->catalog_volume_part_id]);
        $this->assertSame([$cataloger->id], CatalogVolume::find($f2->id)->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame($folded->id, SourceVolume::find($f5->id)->source_id);
        $this->get('/sources/merge-fold')->assertOk();

        // once only, and the same from the audit log
        $this->actingAs($this->admin)->post("/admin/sources/merges/{$log->id}/undo")->assertSessionHas('error');
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('error');
    }

    public function test_a_merge_is_undone_from_the_audit_log_and_a_source_does_not_merge_with_itself()
    {
        $kept = $this->source('فهرست الف - جلد اول', 'log-keep');
        $folded = $this->source('فهرست الف - جلد دوم', 'log-fold');
        $this->volume($kept, 1, 'فهرست الف - جلد اول');
        $second = $this->volume($folded, 1, 'فهرست الف - جلد دوم');

        $this->actingAs($this->admin)->post('/admin/sources/merge', ['keep' => $kept->id, 'fold' => $kept->id, 'title' => 'x', 'confirm' => 1])
            ->assertSessionHasErrors('fold');
        $this->actingAs($this->admin)->post('/admin/sources/merge', ['keep' => $kept->id, 'fold' => $folded->id, 'title' => 'فهرست الف', 'confirm' => 1]);
        $this->assertSame([2, 'فهرست الف'], [SourceVolume::find($second->id)->volume_number, $kept->fresh()->title], 'the volume takes the number its title gives');

        $log = AuditLog::where('auditable_type', Source::class)->where('action', 'merge')->sole();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame([$folded->id, 1], [SourceVolume::find($second->id)->source_id, SourceVolume::find($second->id)->volume_number]);
        $this->assertSame('فهرست الف - جلد اول', $kept->fresh()->title);
        $this->assertTrue($log->isRolledBack());
    }
}
