<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\EntityRedirect;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** robots.txt, canonical addresses, noindex and the sitemap (backlog item 72, step 14.107). */
class IndexingTest extends TestCase
{
    protected Work $work;

    protected Manuscript $manuscript;

    protected Person $person;

    protected Library $library;

    protected Cataloger $cataloger;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://fahares.net']);
        Cache::flush();
        $this->library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'idx-lib-' . uniqid()]);
        $this->work = Work::create(['primary_title' => 'دیوان قدسی', 'clean_title' => 'دیوان قدسی', 'slug' => 'idx-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->manuscript = Manuscript::create(['work_id' => $this->work->id, 'library_id' => $this->library->id, 'shelfmark' => '1939', 'volume_number' => 1, 'page_start' => 1]);
        // the normalised name differs from the name, which the route key is built from
        $this->person = Person::create(['name' => 'نعمة الله ولی', 'normalized_name' => 'نعمه الله ولی', 'slug' => 'idx-person-' . uniqid(),
            'works_count' => 0, 'manuscripts_count' => 0]);
        $this->cataloger = Cataloger::create(['name' => 'درایتی', 'slug' => 'idx-cat-' . uniqid()]);
    }

    protected function canonicalOf(string $html): ?string
    {
        return preg_match('/<link rel="canonical" href="([^"]+)">/', $html, $m) ? html_entity_decode($m[1]) : null;
    }

    public function test_robots_txt_names_the_sitemap_and_blocks_private_paths(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString("Sitemap: https://fahares.net/sitemap.xml", $robots);
        foreach (['/admin', '/my/', '/login', '/register', '/api/', '/notes/', '/notifications', '/search/export', '/contribute/'] as $path) {
            $this->assertMatchesRegularExpression('/^Disallow: ' . preg_quote($path, '/') . '$/m', $robots);
        }
        // the record pages and the bare search page stay open
        foreach (['/works', '/manuscripts', '/people', '/libraries', '/search', '/sources'] as $path) {
            $this->assertDoesNotMatchRegularExpression('/^Disallow: ' . preg_quote($path, '/') . '$/m', $robots);
        }
    }

    public function test_record_pages_name_their_canonical_address_without_the_query(): void
    {
        $url = route('works.show', $this->work);
        $html = $this->get($url . '?sort=shelfmark&direction=desc')->assertOk()->getContent();

        $this->assertSame('https://fahares.net/works/' . rawurlencode($this->work->getRouteKey()), $this->canonicalOf($html));
        $this->assertStringNotContainsString('noindex', $html);

        $html = $this->get(route('manuscripts.show', $this->manuscript))->assertOk()->getContent();
        $this->assertSame('https://fahares.net/manuscripts/' . rawurlencode($this->manuscript->getRouteKey()), $this->canonicalOf($html));
    }

    public function test_a_merged_record_redirects_to_the_page_whose_canonical_is_the_target(): void
    {
        $gone = $this->work->id + 1000;
        EntityRedirect::recordRedirect('works', $gone, $this->work->id);

        $target = route('works.show', $this->work);
        $this->get("/works/{$gone}-old-title")->assertStatus(301)->assertRedirect($target);
        $this->get("/w/{$gone}")->assertStatus(301);
        // an old slug of a living record goes to the same address
        $this->get("/works/{$this->work->id}-old-title")->assertStatus(301)->assertRedirect($target);

        $html = $this->get($target)->getContent();
        $this->assertSame('https://fahares.net/works/' . rawurlencode($this->work->getRouteKey()), $this->canonicalOf($html));
    }

    public function test_search_results_are_noindex_but_the_bare_search_page_is_not(): void
    {
        $html = $this->get('/search')->assertOk()->getContent();
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertSame('https://fahares.net/search', $this->canonicalOf($html));

        $html = $this->get('/search?type=manuscripts')->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        $this->assertNull($this->canonicalOf($html), 'a noindex page names no canonical address');
    }

    public function test_private_and_form_pages_send_a_noindex_header(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/register')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/admin')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $user = User::firstOrCreate(['email' => 'idx-user@fahares.net'], ['name' => 'کاربر', 'password' => 'password123', 'role' => 'user']);
        $this->actingAs($user)->get('/my/suggestions')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get(route('works.show', $this->work))->assertHeaderMissing('X-Robots-Tag');
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_every_sitemap_address_is_the_records_own_address(): void
    {
        $index = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString(route('sitemap.chunk', ['type' => 'catalogers', 'page' => 1]), $index);

        $expected = [
            'works' => route('works.show', $this->work),
            'manuscripts' => route('manuscripts.show', $this->manuscript),
            'people' => route('people.show', $this->person),
            'libraries' => route('libraries.show', $this->library),
            'catalogers' => route('catalogers.show', $this->cataloger),
        ];
        foreach ($expected as $type => $url) {
            $xml = $this->get("/sitemap-{$type}-1.xml")->assertOk()->getContent();
            preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m);
            $this->assertContains(rawurldecode($url), array_map(fn ($l) => rawurldecode(html_entity_decode($l)), $m[1]), $type);
            // the address is not a redirect
            $this->get($url)->assertOk();
        }

        $static = $this->get('/sitemap-static-1.xml')->getContent();
        $this->assertStringContainsString('<loc>' . route('catalogers.index') . '</loc>', $static);
    }

    public function test_the_quick_search_api_is_rate_limited(): void
    {
        $this->getJson('/api/search?q=' . urlencode('دیوان'))->assertHeader('X-RateLimit-Limit', '120');
    }

    public function test_the_sitemap_lists_source_entries_with_text_or_a_work_but_not_thin_ones(): void
    {
        $source = Source::create(['code' => 'idx-dharia', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 30, 'title' => 'الذریعة']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 9]);
        $item = fn (string $key, string $text) => SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id,
            'volume_number' => 9, 'item_key' => $key, 'position' => (int) $key, 'kind' => 'entry', 'page_start' => 1, 'page_end' => 1,
            'title' => 'دیوان', 'title_normalized' => 'دیوان', 'text' => $text, 'text_status' => 'auto']);
        $long = $item('11', str_repeat('دیوان شعر فارسی به خط نستعلیق. ', 5));
        $thin = $item('12', 'دیوان، مر في (295:19).');
        $tied = $item('13', 'دیوان قدسی.');
        $rejected = $item('14', 'دیوان دیگر.');
        foreach ([[$tied, SourceEntry::STATUS_VERIFIED], [$rejected, SourceEntry::STATUS_REJECTED]] as [$entry, $status]) {
            SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $this->work->id, 'source_id' => $source->id,
                'source_item_id' => $entry->id, 'origin' => SourceEntry::ORIGIN_MANUAL, 'status' => $status]);
        }

        $this->assertStringContainsString(route('sitemap.chunk', ['type' => 'entries', 'page' => 1]), $this->get('/sitemap.xml')->getContent());
        $xml = $this->get('/sitemap-entries-1.xml')->assertOk()->getContent();
        foreach ([$long, $tied] as $listed) {
            $this->assertStringContainsString('<loc>' . $listed->load('source')->url() . '</loc>', $xml);
            $this->get($listed->url())->assertOk()->assertHeaderMissing('X-Robots-Tag');
        }
        foreach ([$thin, $rejected] as $left) {
            $this->assertStringNotContainsString('<loc>' . $left->load('source')->url() . '</loc>', $xml);
        }
    }
}
