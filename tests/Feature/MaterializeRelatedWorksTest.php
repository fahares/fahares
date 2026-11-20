<?php

namespace Tests\Feature;

use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * works:materialize-related (backlog item 6, step 14.283): every entry of «وابسته به» and «شروح و حواشی» becomes a row
 * of work_text_relations with its tie, its place and its words, so that the two fields can be dropped; the rows by
 * hand are left, a second run changes nothing, and the page shows each entry once.
 */
class MaterializeRelatedWorksTest extends TestCase
{
    private function work(string $title, string $author, array $more = []): Work
    {
        return Work::create(['primary_title' => $title, 'clean_title' => $title, 'author_name' => $author, 'slug' => 'mat-' . uniqid(),
            'volume_number' => 1, 'page_start' => 1] + $more);
    }

    private function rows(Work $work): array
    {
        return DB::table('work_text_relations')->where('work_id', $work->id)->orderBy('side')->orderBy('position')->get()
            ->map(fn ($r) => [$r->side, $r->position, $r->type, $r->text, $r->source, $r->linked_work_id, $r->link_source, $r->link_status, (bool) $r->differs, $r->model_verdict])->all();
    }

    public function test_every_entry_is_written_with_its_tie()
    {
        $note = 'گزیده‌ای است از این دو کتاب که در ده باب، برای هر روز از دهۀ نخست ماه یک باب، مرتب شده و در پایان آن فهرستی از مطالب هر باب آمده است';
        $nahj = $this->work('نهج البلاغة', 'سید رضی، محمد بن حسین', ['commentaries_and_glosses' => [
            'شرح نهج البلاغة؛ ابن میثم، میثم بن علی (636-679)', 'شرح نهج البلاغة؛ کسی، که نیست (-900)', 'منهاج البراعة', 'حاشیة نهج البلاغة', 'ترجمۀ کهن',
        ]]);
        $sharh = $this->work('شرح نهج البلاغة', 'ابن میثم، میثم بن علی');
        $minhaj = $this->work('منهاج البراعة فی شرح نهج البلاغة', 'خوئی، حبیب الله');
        $hashiya = $this->work('حاشیة نهج البلاغة', '');
        $two = $this->work('منتخب دو کتاب', 'کسی، د', ['related_work' => "کتاب یکم؛ کسی، ه (-700)؛ نهج البلاغة؛ رضی، محمد (359-406)؛ {$note}"]);
        // the ties as they are today: by the rules, by a judged decision, and one waiting in the panel's queue
        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful();
        $link = fn (Work $host, string $side, string $entry, Work $found, array $more = []) => DB::table('work_entry_links')->insert(['work_id' => $host->id, 'side' => $side,
            'entry' => $entry, 'entry_hash' => sha1($entry), 'linked_work_id' => $found->id, 'created_at' => now(), 'updated_at' => now()] + $more + ['source' => 'model', 'status' => 'linked']);
        $link($nahj, 'children', 'منهاج البراعة', $minhaj, ['confidence' => 80]);
        DB::table('work_relations')->insert(['work_id' => $minhaj->id, 'related_work_id' => $nahj->id, 'type' => 'commentary', 'source' => 'model', 'created_at' => now(), 'updated_at' => now()]);
        $link($nahj, 'children', 'حاشیة نهج البلاغة', $hashiya, ['source' => 'review', 'status' => 'proposed', 'why' => 'Only gloss of the title', 'confidence' => 60]);
        $link($two, 'related', 'نهج البلاغة؛ رضی، محمد', $nahj);
        DB::table('work_relations')->insert(['work_id' => $two->id, 'related_work_id' => $nahj->id, 'type' => 'abridgment', 'source' => 'model', 'created_at' => now(), 'updated_at' => now()]);
        // a relation added by hand is not the catalogue's
        $byHand = DB::table('work_text_relations')->insertGetId(['work_id' => $nahj->id, 'side' => 'children', 'type' => 'gloss', 'text' => 'حاشیه‌ای که دستی افزوده شد', 'created_at' => now(), 'updated_at' => now()]);
        $file = tempnam(sys_get_temp_dir(), 'decisions');
        file_put_contents($file, json_encode(['decisions' => [
            ['work_id' => $nahj->id, 'side' => 'children', 'entry' => 'شرح نهج البلاغة؛ کسی، که نیست (-900)', 'verdict' => 'none', 'confidence' => 'high', 'found_id' => null],
            ['work_id' => $nahj->id, 'side' => 'children', 'entry' => 'ترجمۀ کهن', 'verdict' => 'unsure', 'confidence' => 'medium', 'found_id' => null],
            ['work_id' => $nahj->id, 'side' => 'children', 'entry' => 'منهاج البراعة', 'verdict' => 'match', 'confidence' => 'high', 'found_id' => $minhaj->id],
        ]], JSON_UNESCAPED_UNICODE));

        $this->artisan('works:materialize-related', ['--decisions' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('work_text_relations')->count());

        $this->artisan('works:materialize-related', ['--decisions' => $file])->assertSuccessful();
        // side, place, type, text, source, the work, who tied it, the state, whether the words differ, the judging's verdict
        $this->assertSame([
            ['children', null, 'gloss', 'حاشیه‌ای که دستی افزوده شد', 'suggestion', null, null, null, false, null],
            ['children', 0, 'commentary', 'شرح نهج البلاغة؛ ابن میثم، میثم بن علی (636-679)', 'catalogue', $sharh->id, 'auto', 'linked', false, null],
            ['children', 1, 'commentary', 'شرح نهج البلاغة؛ کسی، که نیست (-900)', 'catalogue', null, null, null, false, 'none'],
            ['children', 2, 'related', 'منهاج البراعة', 'catalogue', $minhaj->id, 'model', 'linked', false, null],
            ['children', 3, 'gloss', 'حاشیة نهج البلاغة', 'catalogue', $hashiya->id, 'review', 'proposed', false, null],
            ['children', 4, 'related', 'ترجمۀ کهن', 'catalogue', null, null, null, false, 'unsure'],
        ], $this->rows($nahj));
        $this->assertSame([
            ['related', 0, 'related', 'کتاب یکم؛ کسی، ه (-700)', 'catalogue', null, null, null, false, null],
            ['related', 1, 'related', 'نهج البلاغة؛ رضی، محمد (359-406)', 'catalogue', $nahj->id, 'model', 'linked', true, null],
            ['related', 999, 'note', $note, 'catalogue', null, null, null, false, null],
        ], $this->rows($two));
        $this->assertSame('Only gloss of the title', DB::table('work_text_relations')->where('link_status', 'proposed')->value('why'));

        // an entry of a field of several keeps its words as the field writes them: life dates the parser reads no years in
        $odd = $this->work('اثر سوم', 'کسی، ح', ['related_work' => 'الحشائش؛ دیوسکوریدس (سده 1م)؛ کتاب دوم = نام دیگر؛ کسی، ز (-800)']);
        $this->artisan('works:materialize-related', ['--decisions' => $file])->assertSuccessful();
        $this->assertSame(['الحشائش؛ دیوسکوریدس (سده 1م)', 'کتاب دوم = نام دیگر؛ کسی، ز (-800)'],
            DB::table('work_text_relations')->where('work_id', $odd->id)->orderBy('position')->pluck('text')->all());
        $odd->update(['related_work' => null]);

        // a second run writes the same rows; the row by hand keeps its id
        $before = [$this->rows($nahj), $this->rows($two)];
        $this->artisan('works:materialize-related', ['--decisions' => $file])->assertSuccessful();
        $this->assertSame($before, [$this->rows($nahj), $this->rows($two)]);
        $this->assertTrue(DB::table('work_text_relations')->where('id', $byHand)->exists());

        // the page still shows each entry once: the row by hand, and the catalogue's own from its text
        $html = $this->followingRedirects()->get(route('works.show', $nahj))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'شرح نهج البلاغة؛ کسی، که نیست (-۹۰۰)'));
        $this->assertSame(1, substr_count($html, '>حاشیه‌ای که دستی افزوده شد<'));

        // a work whose texts are gone has no row of the catalogue left
        $two->update(['related_work' => null]);
        $this->artisan('works:materialize-related')->assertSuccessful();
        $this->assertSame([], $this->rows($two));
        $this->assertSame(1, DB::table('work_text_relations')->where('source', 'suggestion')->count());
    }
}
