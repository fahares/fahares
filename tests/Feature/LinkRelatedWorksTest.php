<?php

namespace Tests\Feature;

use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * works:link-related (backlog item 6, step 14.104): the related works Fankha records as text linked to their works in
 * work_relations when one work has the title and the author; the type read from the title or «related»; weaker
 * matches, one title on both sides and an editor's relation left; the work's page linking the text.
 */
class LinkRelatedWorksTest extends TestCase
{
    private function work(string $title, string $author, array $more = []): Work
    {
        return Work::create(['primary_title' => $title, 'clean_title' => $title, 'author_name' => $author, 'slug' => 'rel-' . uniqid(),
            'volume_number' => 1, 'page_start' => 1] + $more);
    }

    private function relations(): array
    {
        return DB::table('work_relations')->orderBy('work_id')->orderBy('related_work_id')->get()
            ->map(fn ($r) => [$r->work_id, $r->related_work_id, $r->type, $r->source, $r->confidence])->all();
    }

    public function test_the_texts_are_linked_to_the_one_work_of_their_title_and_author()
    {
        $nahj = $this->work('نهج البلاغة', 'سید رضی، محمد بن حسین', [
            'commentaries_and_glosses' => ['شرح نهج البلاغة؛ ابن میثم، میثم بن علی (636-679)', 'نهج البلاغة (ترجمه)؛ فیض الاسلام، علی نقی (1284-1370)'],
        ]);
        $sharh = $this->work('شرح نهج البلاغة', 'ابن میثم، میثم بن علی', ['related_work' => 'نهج البلاغة = خطب علی؛ سید رضی، محمد بن حسین (359-406)']);
        // a translation has the title of its base, and is told by its form
        $tarjama = $this->work('نهج البلاغة', 'فیض الاسلام، علی نقی', ['work_form' => 'translation']);
        // a gloss on a commentary, listed among the base text's: «related»
        $lumʿa = $this->work('اللمعة الدمشقیة', 'شهید اول، محمد بن مکی', ['commentaries_and_glosses' => ['حاشیة الروضة البهیة؛ سلطان العلماء، حسین بن محمد (1001-1064)']]);
        $hashiya = $this->work('حاشیة الروضة البهیة', 'سلطان العلماء، حسین بن محمد');
        // no author in the text, or one title on both sides: left
        $this->work('شرح آخر', 'کسی، دیگر', ['related_work' => 'نهج البلاغة']);
        $tadhkira = $this->work('التذکرة', 'انطاکی، داود بن عمر', ['related_work' => 'التذکرة؛ انطاکی، داود بن عمر (950-1008)']);
        $this->work('التذکرة', 'انطاکی، داود بن عمر');
        // an editor's relation between two works the texts also relate is the editor's
        $suggested = $this->work('شرح اللمعة', 'کسی، سوم', ['related_work' => 'اللمعة الدمشقیة؛ شهید اول، محمد بن مکی (734-786)']);
        DB::table('work_relations')->insert(['work_id' => $suggested->id, 'related_work_id' => $lumʿa->id, 'type' => 'part', 'source' => 'suggestion', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('works:link-related', ['--dry-run' => true])->assertSuccessful();
        $this->assertCount(1, $this->relations());

        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful()->expectsOutputToContain('3 relations: 3 to add');
        $this->assertEqualsCanonicalizing([
            [$sharh->id, $nahj->id, 'commentary', 'auto', 90],
            [$tarjama->id, $nahj->id, 'translation', 'auto', 90],
            [$hashiya->id, $lumʿa->id, 'related', 'auto', 70],
            [$suggested->id, $lumʿa->id, 'part', 'suggestion', null],
        ], $this->relations());
        $this->assertFalse(DB::table('work_relations')->where('work_id', $tadhkira->id)->exists());

        // a second run changes nothing; a text no longer there takes its relation away
        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful()->expectsOutputToContain('0 to add, 0 to remove, 3 kept');
        $hashiya->delete();
        $this->assertCount(3, $this->relations());

        // the pages: the text as Fankha wrote it, its titles linked to the works, and the relation both ways
        $page = $this->followingRedirects()->get(route('works.show', $sharh))->assertOk();
        $page->assertSee('<a href="' . route('works.show', $nahj) . '"', false)->assertSee('؛ سید رضی، محمد بن حسین (۳۵۹-۴۰۶)', false)
            ->assertSee('شرحی است بر');
        $page = $this->followingRedirects()->get(route('works.show', $nahj))->assertOk();
        $page->assertSee('<a href="' . route('works.show', $sharh) . '"', false)->assertSee('<a href="' . route('works.show', $tarjama) . '"', false)->assertSee('ترجمه‌ها');
        $this->followingRedirects()->get(route('works.show', $lumʿa))->assertOk()->assertSee('بخش‌ها و مجلدها');

        // the base titles feed the search: the relation's and the text's
        $this->assertSame(['نهج البلاغة', 'خطب علی'], $sharh->fresh()->baseTitles());
    }
}
