<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Work;
use App\Models\WorkEntryLink;
use App\Support\WorkRelationLinker;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * works:link-decisions (backlog item 6, steps 14.270 and 14.285): the entries a decisions file ties to their works are
 * tied in work_text_relations and, where the two works are not related yet, get a relation of source «model»; only «match» of
 * confidence «high» is written; the work's page links the entry; a second run changes nothing, and a decision taken
 * out of the file takes its rows away.
 */
class LinkDecidedWorksTest extends TestCase
{
    private function work(string $title, string $author, array $more = []): Work
    {
        return $this->workWithTexts(['primary_title' => $title, 'clean_title' => $title, 'author_name' => $author, 'slug' => 'dec-' . uniqid(),
            'volume_number' => 1, 'page_start' => 1] + $more);
    }

    private function file(array $decisions): string
    {
        $path = tempnam(sys_get_temp_dir(), 'decisions');
        file_put_contents($path, json_encode(['decisions' => array_map(fn ($d) => $d + ['verdict' => 'match', 'confidence' => 'high'], $decisions)], JSON_UNESCAPED_UNICODE));

        return $path;
    }

    public function test_the_decided_entries_are_written_and_linked_on_the_page()
    {
        // an entry with no author and two works of its title: the decision says which
        $kafiya = $this->work('الکافیة', 'ابن حاجب، عثمان بن عمر', ['commentaries_and_glosses' => ['شرح الکافیة', 'حاشیة الکافیة؛ کسی، الف (-900)']]);
        $this->work('شرح الکافیة', '');
        $sharh = $this->work('شرح الکافیة', '');
        // a title written another way, in the text of the work made on it; the author follows the title
        $tabsira = $this->work('تبصرة المتعلمین فی احکام الدین', 'علامه حلی، حسن بن یوسف');
        $kashf = $this->work('کشف الاستار', 'همدانی، حسین بن محمد', ['related_work' => 'تبصرة المتعلمین علامه حلی، حسن بن یوسف (648-726)']);
        // a pair works:link-related has already: the entry is tied, no second relation is made
        $hashiya = $this->work('حاشیة الکافیة', 'کسی، الف');
        DB::table('work_relations')->insert(['work_id' => $hashiya->id, 'related_work_id' => $kafiya->id, 'type' => 'gloss', 'source' => 'auto', 'confidence' => 90, 'created_at' => now(), 'updated_at' => now()]);
        // a pair related the other way round is left; so is an entry the text does not have, and a decision that is not sure
        $base = $this->work('متن', 'کسی، ج', ['commentaries_and_glosses' => ['شرح متن']]);
        $wrong = $this->work('شرح متن', '');
        DB::table('work_relations')->insert(['work_id' => $base->id, 'related_work_id' => $wrong->id, 'type' => 'part', 'source' => 'suggestion', 'created_at' => now(), 'updated_at' => now()]);

        $decisions = [
            ['work_id' => $kafiya->id, 'side' => 'children', 'entry' => 'شرح الکافیة', 'found_id' => $sharh->id],
            ['work_id' => $kashf->id, 'side' => 'related', 'entry' => 'تبصرة المتعلمین علامه حلی، حسن بن یوسف (648-726)', 'found_id' => $tabsira->id],
            ['work_id' => $kafiya->id, 'side' => 'children', 'entry' => 'حاشیة الکافیة؛ کسی، الف (-900)', 'found_id' => $hashiya->id],
            ['work_id' => $base->id, 'side' => 'children', 'entry' => 'شرح متن', 'found_id' => $wrong->id],
            ['work_id' => $base->id, 'side' => 'children', 'entry' => 'شرحی که در متن نیست', 'found_id' => $sharh->id],
            ['work_id' => $base->id, 'side' => 'children', 'entry' => 'شرح متن', 'found_id' => $sharh->id, 'confidence' => 'medium'],
            ['work_id' => $base->id, 'side' => 'children', 'entry' => 'شرح متن', 'found_id' => null, 'verdict' => 'none'],
        ];
        $file = $this->file($decisions);

        $this->artisan('works:link-decisions', ['file' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('work_text_relations')->where('link_source', 'model')->count());

        $this->artisan('works:link-decisions', ['file' => $file, '--no-index' => true])->assertSuccessful()
            ->expectsOutputToContain('3 entries: 3 to add')->expectsOutputToContain('2 relations: 2 to add');
        $this->assertEqualsCanonicalizing([
            [$sharh->id, $kafiya->id, 'commentary', 'model', 80],
            [$kashf->id, $tabsira->id, 'related', 'model', 80],
        ], DB::table('work_relations')->where('source', 'model')->get()->map(fn ($r) => [$r->work_id, $r->related_work_id, $r->type, $r->source, $r->confidence])->all());
        $this->assertEqualsCanonicalizing([
            [$kafiya->id, 'children', 'شرح الکافیة', $sharh->id],
            [$kashf->id, 'related', 'تبصرة المتعلمین علامه حلی، حسن بن یوسف (648-726)', $tabsira->id],
            [$kafiya->id, 'children', 'حاشیة الکافیة؛ کسی، الف', $hashiya->id],
        ], DB::table('work_text_relations')->where('link_source', 'model')->get()->map(fn ($r) => [$r->work_id, $r->side, $r->entry_key, $r->linked_work_id])->all());

        // the pages: the entry the decision names is linked, and of a glued author only the title
        $this->assertSame([$sharh->id, $hashiya->id], DB::table('work_text_relations')->where('work_id', $kafiya->id)->where('link_status', 'linked')->orderBy('position')->pluck('linked_work_id')->all());
        $this->assertSame([], \App\Support\WorkCatalogueEntries::page($kafiya)['unlinked']);
        $this->followingRedirects()->get(route('works.show', $kashf))->assertOk()
            ->assertSee('<a href="' . route('works.show', $tabsira) . '"', false)
            // the start of the work's title with its own author after it: the catalogue's words say nothing else
            ->assertDontSee('در فهرست:');
        $this->followingRedirects()->get(route('works.show', $kafiya))->assertOk()->assertSee('<a href="' . route('works.show', $sharh) . '"', false);

        // works:link-related leaves the pairs of the decisions alone; a second run changes nothing
        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful();
        $this->artisan('works:link-decisions', ['file' => $file, '--no-index' => true])->assertSuccessful()
            ->expectsOutputToContain('3 entries: 0 to add, 0 to remove, 3 kept')->expectsOutputToContain('2 relations: 0 to add, 0 to remove, 2 kept');

        // a decision taken out of the file takes its entry and its relation away
        $this->artisan('works:link-decisions', ['file' => $this->file(array_slice($decisions, 1)), '--no-index' => true])->assertSuccessful()
            ->expectsOutputToContain('2 entries: 0 to add, 1 to remove')->expectsOutputToContain('1 relations: 0 to add, 1 to remove');
        $this->assertFalse(DB::table('work_relations')->where('work_id', $sharh->id)->exists());
    }

    /** Step 14.274: the matches of less confidence wait in the panel; only a reviewer's «پیوند بده» links the entry */
    public function test_a_proposal_is_linked_only_when_a_reviewer_confirms_it()
    {
        config(['scout.driver' => 'collection']);
        $kafiya = $this->work('الکافیة', 'ابن حاجب، عثمان بن عمر', ['commentaries_and_glosses' => ['شرح الکافیة', 'نظم الکافیة']]);
        $sharh = $this->work('الوافیة', 'کسی، الف', ['related_work' => 'متنی در نحو']);
        $nazm = $this->work('منظومة نحویة', 'کسی، ب');
        $file = $this->file([
            ['work_id' => $kafiya->id, 'side' => 'children', 'entry' => 'شرح الکافیة', 'found_id' => $sharh->id, 'confidence' => 'medium', 'why' => 'Only commentary of the title'],
            ['work_id' => $kafiya->id, 'side' => 'children', 'entry' => 'نظم الکافیة', 'found_id' => $nazm->id, 'confidence' => 'medium'],
        ]);

        // without the option nothing is queued; with it, once
        $this->artisan('works:link-decisions', ['file' => $file, '--no-index' => true])->assertSuccessful();
        $this->assertSame(0, WorkEntryLink::review()->count());
        $this->artisan('works:link-decisions', ['file' => $file, '--no-index' => true, '--proposals' => true])->assertSuccessful()->expectsOutputToContain('2 proposals to add');
        $this->artisan('works:link-decisions', ['file' => $file, '--no-index' => true, '--proposals' => true])->assertSuccessful()->expectsOutputToContain('0 proposals to add');
        [$first, $second] = WorkEntryLink::review()->orderBy('id')->get()->all();
        $this->assertSame(['review', 'proposed', 'Only commentary of the title'], [$first->link_source, $first->link_status, $first->why]);
        // a proposal is not a tie: the page still shows both entries as text
        $this->assertCount(2, \App\Support\WorkCatalogueEntries::page($kafiya)['unlinked']);
        $this->assertSame(0, DB::table('work_relations')->count());

        // the queue is the reviewers'
        $user = User::create(['name' => 'خواننده', 'email' => uniqid('el-') . '@example.test', 'password' => 'password123', 'role' => 'user']);
        $this->actingAs($user)->get(route('admin.work-entry-links.index'))->assertForbidden();
        $admin = User::create(['name' => 'داور', 'email' => uniqid('el-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.work-entry-links.index'))->assertOk()->assertSee('شرح الکافیة')->assertSee('الوافیة')->assertSee('Only commentary of the title');

        // «پیوند بده»: the relation, and the entry linked on the work's page
        $this->actingAs($admin)->post(route('admin.work-entry-links.link', $first))->assertRedirect();
        // the type is the entry's own: «شرح الکافیة»
        $this->assertSame([[$sharh->id, $kafiya->id, 'commentary', 'review']],
            DB::table('work_relations')->get()->map(fn ($r) => [$r->work_id, $r->related_work_id, $r->type, $r->source])->all());
        $this->assertSame(['linked', $admin->id], [$first->fresh()->link_status, $first->fresh()->decided_by]);
        $this->assertSame(['نظم الکافیة'], array_column(\App\Support\WorkCatalogueEntries::page($kafiya)['unlinked'], 'text'));
        $this->followingRedirects()->get(route('works.show', $kafiya))->assertOk()->assertSee('<a href="' . route('works.show', $sharh) . '"', false);
        // works:link-related and a later run of the decisions leave the reviewer's rows alone
        $this->artisan('works:link-related', ['--no-index' => true])->assertSuccessful();
        $this->artisan('works:link-decisions', ['file' => $file, '--no-index' => true, '--proposals' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('work_relations')->where('source', 'review')->count());
        $this->assertSame('linked', $first->fresh()->link_status);

        // «اثر دیگری است» leaves the entry as text; a decided item is not decided twice
        $this->actingAs($admin)->post(route('admin.work-entry-links.reject', $second))->assertRedirect();
        $this->assertSame('rejected', $second->fresh()->link_status);
        $this->actingAs($admin)->post(route('admin.work-entry-links.link', $second))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.work-entry-links.index', ['view' => 'done']))->assertOk()->assertSee('نظم الکافیة')->assertSee('بازگشایی');

        // «بازگشایی» takes the link and its relation back
        $this->actingAs($admin)->post(route('admin.work-entry-links.reopen', $first))->assertRedirect();
        $this->assertSame('proposed', $first->fresh()->link_status);
        $this->assertSame(0, DB::table('work_relations')->count());
        $this->assertCount(2, \App\Support\WorkCatalogueEntries::page($kafiya)['unlinked']);
    }
}
