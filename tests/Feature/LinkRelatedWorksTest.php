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
        return $this->workWithTexts(['primary_title' => $title, 'clean_title' => $title, 'author_name' => $author, 'slug' => 'rel-' . uniqid(),
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

        // the pages: the relation both ways, in the one relations section (step 14.277); the text's own fields are off
        // the page, and its words show only where they are not the work's title and author
        $page = $this->followingRedirects()->get(route('works.show', $sharh))->assertOk();
        $page->assertSee('<a href="' . route('works.show', $nahj) . '"', false)->assertSee('شرحی است بر')
            ->assertDontSee('در فهرست:')->assertDontSee('وابسته به</dt>', false)->assertDontSee('field="commentaries_and_glosses"', false);
        $this->followingRedirects()->get(route('works.show', $nahj))->assertOk()->assertDontSee('<span>شروح، حواشی و منظومه‌ها</span>', false);
        $page = $this->followingRedirects()->get(route('works.show', $nahj))->assertOk();
        $page->assertSee('<a href="' . route('works.show', $sharh) . '"', false)->assertSee('<a href="' . route('works.show', $tarjama) . '"', false)->assertSee('ترجمه‌ها');
        $this->followingRedirects()->get(route('works.show', $lumʿa))->assertOk()->assertSee('بخش‌ها و مجلدها');

        // the base titles feed the search: the relation's and the text's
        $this->assertSame(['نهج البلاغة', 'خطب علی'], $sharh->fresh()->baseTitles());
    }

    /** Step 14.277: the entries tied to no work stand in the page's one relations section, and a tied one shows the catalogue's other words */
    public function test_the_relations_section_holds_the_entries_tied_to_no_work()
    {
        $nahj = $this->work('نهج البلاغة', 'سید رضی، محمد بن حسین', ['commentaries_and_glosses' => [
            'شرح نهج البلاغة؛ ابن میثم، میثم بن علی (636-679)', 'شرح نهج البلاغة؛ کسی، که نیست (-900)', 'نهج البلاغة (ترجمه)',
        ]]);
        $sharh = $this->work('شرح نهج البلاغة', 'ابن میثم، میثم بن علی');
        $orphan = $this->work('رساله‌ای دیگر', 'کسی، الف', ['related_work' => 'کتابی که نیست؛ کسی، ب (-800)']);
        // a decision ties an entry to a work the catalogue itself records under another author
        $zayirja = $this->work('الزایرجة', 'خزرجی، احمد بن مسعود');
        $adwar = $this->work('الادوار العنصریة', 'ابن غزی، احمد', ['related_work' => 'الزایرجة؛ سبتی، احمد بن جعفر (524-601)']);
        DB::table('work_text_relations')->where('work_id', $adwar->id)->update(['linked_work_id' => $zayirja->id, 'link_status' => 'linked', 'link_source' => 'model', 'differs' => true]);
        DB::table('work_relations')->insert(['work_id' => $adwar->id, 'related_work_id' => $zayirja->id, 'type' => 'related', 'source' => 'model', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful();

        // a field the parser reads no entry in (long, with no «؛») is one row as written; a field of several entries keeps its note
        $long = 'الفرائض النصیریة علی مذهب اهل البیت = جواهر الفرائض = رسالة الدرة = الرسالة المیراثیة = رساله‌ای دیگر با نامی بلند نصیرالدین طوسی، محمد بن محمد';
        $unread = $this->work('شرحی بر فرائض', 'کسی، ج', ['related_work' => $long]);
        $note = 'گزیده‌ای است از این دو کتاب که در ده باب، برای هر روز از دهۀ نخست ماه یک باب، مرتب شده و در پایان آن فهرستی از مطالب هر باب آمده است';
        $two = $this->work('منتخب دو کتاب', 'کسی، د', ['related_work' => "کتاب یکم؛ کسی، ه (-700)؛ کتاب دوم؛ کسی، و (-800)؛ {$note}"]);
        $this->assertEquals([['side' => 'related', 'text' => $long, 'type' => 'related']], \App\Support\WorkCatalogueEntries::page($unread)['unlinked']);
        $this->assertSame([$note], \App\Support\WorkCatalogueEntries::page($two)['notes']);
        $this->followingRedirects()->get(route('works.show', $two))->assertOk()
            ->assertSeeInOrder(['وابسته است به:', 'کتاب یکم؛ کسی، ه (-۷۰۰)', 'کتاب دوم؛ کسی، و (-۸۰۰)', "یادداشت فهرست: {$note}"], false);

        $rows = \App\Support\WorkCatalogueEntries::page($nahj->fresh());
        $this->assertSame([], $rows['printed']);
        $this->assertEquals([
            ['side' => 'children', 'text' => 'شرح نهج البلاغة؛ کسی، که نیست (-900)', 'type' => 'commentary'],
            ['side' => 'children', 'text' => 'نهج البلاغة (ترجمه)', 'type' => 'translation'],
        ], $rows['unlinked']);
        // the page: the work that is tied as a link, the others as text under the label of their type
        $this->followingRedirects()->get(route('works.show', $nahj))->assertOk()->assertSeeInOrder([
            'رابطه با آثار دیگر', 'شروح:', '<a href="' . route('works.show', $sharh) . '"', 'شرح نهج البلاغة؛ کسی، که نیست (-۹۰۰)', 'بی پیوند به رکورد اثر',
            'ترجمه‌ها:', 'نهج البلاغة (ترجمه)',
        ], false);
        $this->followingRedirects()->get(route('works.show', $orphan))->assertOk()
            ->assertSeeInOrder(['رابطه با آثار دیگر', 'وابسته است به:', 'کتابی که نیست؛ کسی، ب (-۸۰۰)', 'بی پیوند به رکورد اثر'], false);
        // the catalogue's words beside a tied work, where its author there is another name
        $this->assertSame([$zayirja->id => 'الزایرجة؛ سبتی، احمد بن جعفر (524-601)'], \App\Support\WorkCatalogueEntries::page($adwar->fresh())['printed']);
        $this->followingRedirects()->get(route('works.show', $adwar))->assertOk()
            ->assertSeeInOrder(['وابسته است به:', '<a href="' . route('works.show', $zayirja) . '"', 'در فهرست: الزایرجة؛ سبتی، احمد بن جعفر (۵۲۴-۶۰۱)'], false);
        // a work the texts say nothing of keeps the invitation to add a relation
        $this->followingRedirects()->get(route('works.show', $zayirja))->assertOk()->assertSee('آثار وابسته:');
    }

    /** Step 14.229: two works of one title that are plainly two, and the one of several works of a title and author */
    public function test_two_works_of_one_title_and_the_one_of_several_works_of_a_title_and_author()
    {
        // one title: a selection by another, a selection by the author himself, an answer by another author: linked
        $majalis = $this->work('مجالس المؤمنین', 'شوشتری، نورالله بن شرف الدین', ['commentaries_and_glosses' => ['مجالس المؤمنین (منتخب)؛ ذوالقدر، خلیل (-11)']]);
        $selection = $this->work('مجالس المؤمنین', 'ذوالقدر، خلیل', ['work_form' => 'selection', 'alternative_titles' => [['title' => 'مجالس المؤمنین (منتخب)']]]);
        $hisn = $this->work('الحصن الحصین', 'ابن جزری، محمد بن محمد');
        $own = $this->work('الحصن الحصین', 'ابن جزری، محمد بن محمد', ['work_form' => 'selection', 'related_work' => 'الحصن الحصین؛ ابن جزری، محمد بن محمد (751-833)']);
        $tafsir = $this->work('تفسیر آیه نور', 'شیروانی، محمد بن حسن');
        $answer = $this->work('تفسیر آیه نور', 'خواجوئی، محمد اسماعیل بن حسین', ['related_work' => 'تفسیر آیه نور؛ شیروانی، محمد بن حسن (1033-1098)']);
        // one title and one author with nothing to tell them apart, or the author under two forms of his name: most likely one work twice
        $this->work('التذکرة', 'انطاکی، داود بن عمر', ['related_work' => 'التذکرة؛ انطاکی، داود بن عمر (950-1008)']);
        $this->work('التذکرة', 'انطاکی، داود بن عمر');
        $this->work('الشافیة', 'ابوفراس حمدانی، حارث بن سعید', ['related_work' => 'الشافیة؛ ابوفراس همدانی، حارث بن سعید (320-357)']);
        $this->work('الشافیة', 'ابوفراس همدانی، حارث بن سعید');

        // several works of the title and author: the one with every title of the entry, marks and all
        $tajrid = $this->work('شرح تجرید الاعتقاد', 'قوشچی، علی بن محمد', ['commentaries_and_glosses' => ['حاشیة شرح التجرید (امور عامه)؛ خفری، محمد بن احمد (-957)', 'حاشیة شرح التجرید = اثبات الواجب؛ خفری، محمد بن احمد (-957)']]);
        $umur = $this->work('حاشیة شرح التجرید', 'خفری، محمد بن احمد', ['alternative_titles' => [['title' => 'حاشیة شرح التجرید (امور عامه)']]]);
        $ithbat = $this->work('حاشیة شرح التجرید', 'خفری، محمد بن احمد', ['alternative_titles' => [['title' => 'اثبات الواجب']]]);
        // what a work is made on, of two works of one title and author: the one that is not the other's selection
        $sharh = $this->work('شرح الحصن الحصین', 'حنفی تبریزی، شمس الدین محمد', ['related_work' => 'الحصن الحصین؛ ابن جزری، محمد بن محمد (751-833)']);

        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful();
        $this->assertEqualsCanonicalizing([
            [$selection->id, $majalis->id, 'abridgment', 'auto', 85],
            [$own->id, $hisn->id, 'abridgment', 'auto', 85],
            [$answer->id, $tafsir->id, 'related', 'auto', 65],
            [$umur->id, $tajrid->id, 'gloss', 'auto', 85],
            [$ithbat->id, $tajrid->id, 'gloss', 'auto', 85],
            [$sharh->id, $hisn->id, 'commentary', 'auto', 85],
        ], $this->relations());
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
        $this->assertSame([[null, null], [$qutb->id, 'linked']], DB::table('work_text_relations')->where('work_id', $base->id)->orderBy('position')->get()
            ->map(fn ($r) => [$r->linked_work_id, $r->link_status])->all());

        // the page links the work; the author written after the title is the work's own, so the catalogue's words are not repeated
        $this->followingRedirects()->get(route('works.show', $glued))->assertOk()
            ->assertSee('<a href="' . route('works.show', $tahdhib) . '"', false)->assertDontSee('در فهرست:');
    }

    /** Step 14.310: in «شروح و حواشی» the part after «؛» is the work's author also when it has no life dates */
    public function test_an_author_written_with_no_life_dates_is_read()
    {
        $base = $this->work('هدایة الحکمة', 'ابهری، مفضل بن عمر', ['commentaries_and_glosses' => [
            'حاشیة شرح هدایة الحکمة؛ خلیل بن فاضل', 'حاشیة شرح هدایة الحکمة؛ ناشناس', 'شرح هدایة الحکمة؛ کتابی دیگر', 'حاشیة شرح هدایة الحکمة؛ کسی، دیگر (-900)',
        ]]);
        $gloss = $this->work('حاشیة شرح هدایة الحکمة', 'خلیل بن فاضل');
        $this->work('حاشیة شرح هدایة الحکمة', 'لاری، عبدالغفور');
        // the second part is itself the title of a work: two entries, as before, and no author for the first
        $this->work('شرح هدایة الحکمة', 'میبدی، حسین');
        $this->work('کتابی دیگر', 'کسی');
        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful()->expectsOutputToContain('1 relations: 1 to add');
        $this->assertSame([[$gloss->id, $base->id, 'related', 'auto', 70]], $this->relations());
        $this->assertSame([$gloss->id, null, null, null],
            DB::table('work_text_relations')->where('work_id', $base->id)->orderBy('position')->pluck('linked_work_id')->all());
    }
}
