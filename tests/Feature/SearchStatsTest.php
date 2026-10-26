<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Library;
use App\Models\SearchLog;
use App\Models\User;
use Tests\TestCase;

/** Search stats and the search fixes of backlog item 83, phases 0 and 1 */
class SearchStatsTest extends TestCase
{
    private const BROWSER = ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0'];

    public function test_a_search_is_logged_anonymously_with_its_filters(): void
    {
        $library = Library::create(['name' => 'کتابخانه‌ی آزمایشی آمار', 'city' => 'قم', 'slug' => 'stats-library-' . uniqid()]);

        $this->withHeaders(self::BROWSER)->get(route('search', ['type' => 'manuscripts', 'library_id' => $library->id, 'flag' => 'is_autograph']))
            ->assertOk();

        $log = SearchLog::sole();
        $this->assertSame('', $log->query);
        $this->assertSame('manuscripts', $log->type);
        $this->assertSame(['library_id' => (string) $library->id, 'flags' => ['is_autograph']], $log->filters);
        $this->assertSame(0, $log->results_count);
        $this->assertFalse($log->is_member);
        $this->assertNull($log->clicked_position);
    }

    public function test_bots_prefetches_later_pages_and_bare_browsing_are_not_logged(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->get(route('search', ['type' => 'manuscripts', 'century' => 11]))->assertOk();
        $this->withHeaders(self::BROWSER + ['Sec-Purpose' => 'prefetch'])->get(route('search', ['type' => 'manuscripts', 'century' => 11]))->assertOk();
        $this->withHeaders(self::BROWSER)->get(route('search', ['type' => 'manuscripts', 'century' => 11, 'page' => 2]))->assertOk();
        $this->withHeaders(self::BROWSER)->get(route('search', ['type' => 'manuscripts']))->assertOk()->assertDontSee('data-search-log', false);

        $this->assertSame(0, SearchLog::count());
    }

    public function test_only_the_first_result_opened_is_recorded(): void
    {
        $log = SearchLog::create(['query' => 'کافی', 'type' => 'works', 'scope' => 'titles_names', 'results_count' => 40]);

        $this->post(route('search.click'), ['log' => $log->id, 'position' => 3])->assertNoContent();
        $this->post(route('search.click'), ['log' => $log->id, 'position' => 1])->assertNoContent();
        $this->assertSame(3, $log->fresh()->clicked_position);

        $old = SearchLog::create(['query' => 'شفا', 'type' => 'works', 'scope' => 'titles_names', 'results_count' => 5, 'created_at' => now()->subDay()]);
        $this->post(route('search.click'), ['log' => $old->id, 'position' => 2])->assertNoContent();
        $this->assertNull($old->fresh()->clicked_position);
    }

    public function test_the_stats_page_shows_frequent_empty_and_unopened_searches(): void
    {
        $admin = User::create(['name' => 'مدیر آمار', 'email' => 'stats-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        foreach ([['کافی', 40, 1], ['کافی', 40, null], ['واژه‌ی ناپیدا', 0, null], ['بی‌کلیک', 7, null], ['بی‌کلیک', 7, null]] as [$q, $n, $pos]) {
            SearchLog::create(['query' => $q, 'type' => 'works', 'scope' => 'titles_names', 'results_count' => $n, 'clicked_position' => $pos,
                'filters' => $q === 'کافی' ? ['century' => '4'] : null]);
        }

        $this->actingAs($admin)->get(route('admin.search-stats.index'))->assertOk()
            ->assertSeeInOrder(['پرتکرارترین جستجوها', 'کافی', 'جستجوهای بی‌نتیجه', 'واژه‌ی ناپیدا', 'نتیجه‌دار ولی بی‌کلیک', 'بی‌کلیک'])
            ->assertSee('سده')->assertSee('۵');

        $editor = User::create(['name' => 'ویراستار آمار', 'email' => 'stats-editor@fahares.net', 'password' => 'password123', 'role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.search-stats.index'))->assertForbidden();
    }

    public function test_digits_are_latin_and_titles_are_indexed_without_the_article(): void
    {
        $this->assertSame('1189/4', TextNormalizer::normalize('۱۱۸۹/۴'));
        $this->assertSame('1189', TextNormalizer::normalize('١١٨٩'));

        $this->assertSame('کافی الکافی', TextNormalizer::expandVariants('الکافی'));
        $this->assertSame('شفا الشفا', TextNormalizer::expandVariants('الشفاء'));
        $this->assertSame('روضه الروضه بهیه البهیه الروضت البهیت', TextNormalizer::expandVariants('الروضة البهیة'));
        // Too short to carry the article: «الله», «الی»
        $this->assertSame('رساله الی الله', TextNormalizer::expandVariants('رساله الی الله'));
    }
}
