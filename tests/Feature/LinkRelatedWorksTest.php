<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Work;
use App\Support\WorkRelationLinker;
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

    /** Step 14.229: the same title and author written another way, and an entry with no author that is named back */
    public function test_the_rules_link_an_author_written_another_way_and_a_work_that_names_this_one_back()
    {
        $person = fn (string $name, array $more = []) => Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'rel-p-' . uniqid(), 'is_author' => true] + $more);

        // the author after the title with no «؛», and after another title
        $tahdhib = $this->work('تهذیب المنطق', 'تفتازانی، مسعود بن عمر');
        $glued = $this->work('حاشیة تهذیب المنطق', 'یزدی، عبدالله بن حسین', ['related_work' => 'تهذیب المنطق تفتازانی، مسعود بن عمر (722-792)']);
        $tajrid = $this->work('شرح تجرید الاعتقاد', 'قوشچی، علی بن محمد');
        $after = $this->work('تحقیق التقابل', 'دوانی، محمد بن اسعد', ['related_work' => 'شرح تجرید الاعتقاد = شرح التجرید قوشچی، علی بن محمد (-879)']);

        // another name of the author's record; another form of the name with the same year of death, not with another year
        $burhan = $this->work('البرهان فی علم المیزان', 'گلنبوی، اسماعیل بن مصطفی', ['author_id' => $person('گلنبوی، اسماعیل بن مصطفی', ['aliases' => ['گلبنوی، اسماعیل بن مصطفی']])->id]);
        $alias = $this->work('حاشیة البرهان', 'کسی، چهارم', ['related_work' => 'البرهان فی علم المیزان؛ گلبنوی، اسماعیل بن مصطفی (1143-1205)']);
        $qanun = $this->work('القانون فی الطب', 'ابن سینا، حسین بن عبدالله', ['author_id' => $person('ابن سینا، حسین بن عبدالله', ['death_year_hijri' => 428])->id]);
        $died = $this->work('شرح القانون', 'کسی، پنجم', ['related_work' => 'القانون فی الطب؛ ابن سینا، حسین بن علی (370-428)']);
        $this->work('شرح دیگر قانون', 'کسی، ششم', ['related_work' => 'القانون فی الطب؛ ابن سینا، حسین بن علی (370-430)']);

        // no author in the text: the one work of the title that names this work back. Two works of one title that both name
        // their base are each linked from their own text
        $ashura = $this->work('زیارت عاشورا', 'محمد بن علی (ع)، امام پنجم', ['commentaries_and_glosses' => ['شرح زیارت عاشورا', 'زیارت عاشورا (ترجمه)']]);
        $back = $this->work('شرح زیارت عاشورا', '', ['related_work' => 'زیارت عاشورا']);
        $this->work('شرح زیارت عاشورا', 'کسی، هفتم');
        $first = $this->work('زیارت عاشورا', 'کسی، هشتم', ['related_work' => 'زیارت عاشورا', 'work_form' => 'translation']);
        $second = $this->work('زیارت عاشورا', 'کسی، نهم', ['related_work' => 'زیارت عاشورا', 'work_form' => 'translation']);
        // left: the entry stands for several works; and one work two works of one title both list
        $this->work('دعای صباح', 'کسی، دهم', ['commentaries_and_glosses' => ['شرح دعای صباح (2 عنوان)']]);
        $this->work('شرح دعای صباح', '', ['related_work' => 'دعای صباح؛ کسی، دیگر (-900)']);
        $this->work('دعای کمیل', 'کسی، یازدهم', ['commentaries_and_glosses' => ['شرح دعای کمیل']]);
        $this->work('دعای کمیل', 'کسی، دوازدهم', ['commentaries_and_glosses' => ['شرح دعای کمیل']]);
        $this->work('شرح دعای کمیل', '', ['related_work' => 'دعای کمیل']);

        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful();
        $this->assertEqualsCanonicalizing([
            [$glued->id, $tahdhib->id, 'gloss', 'auto', 85],
            [$after->id, $tajrid->id, 'related', 'auto', 65],
            [$alias->id, $burhan->id, 'related', 'auto', 65],
            [$died->id, $qanun->id, 'related', 'auto', 65],
            [$back->id, $ashura->id, 'commentary', 'auto', 85],
            [$first->id, $ashura->id, 'translation', 'auto', 85],
            [$second->id, $ashura->id, 'translation', 'auto', 85],
        ], $this->relations());

        // of two entries of one title, the page links the work from the one that names its author
        $base = $this->work('الشمسیة', 'کاتبی، علی بن عمر', ['commentaries_and_glosses' => ['شرح الشمسیة', 'شرح الشمسیة؛ قطب الدین رازی، محمد بن محمد (694-766)']]);
        $qutb = $this->work('شرح الشمسیة', 'قطب الدین رازی، محمد بن محمد');
        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful();
        $this->assertSame([1], array_keys(WorkRelationLinker::linkedEntries($base)['children']));
        $this->assertSame($qutb->id, WorkRelationLinker::linkedEntries($base)['children'][1][0][1]->id);

        // the page links the title alone, and leaves the author after it as text
        $this->followingRedirects()->get(route('works.show', $glued))->assertOk()
            ->assertSee('>تهذیب المنطق</a> تفتازانی، مسعود بن عمر (۷۲۲-۷۹۲)', false);
    }
}
