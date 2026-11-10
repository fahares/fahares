<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Support\AuthorInQuery;
use Meilisearch\Client as MeiliClient;
use Tests\TestCase;

/** «قانون ابن سینا»: the title words searched once more among the named author's works (step 14.128) */
class AuthorInQueryTest extends TestCase
{
    /** An engine whose people index finds the given people for the given words, and nothing else */
    private function engine(array $found): void
    {
        $meili = \Mockery::mock(MeiliClient::class);
        $meili->shouldReceive('multiSearch')->andReturnUsing(fn (array $queries) => ['results' => array_map(
            fn ($q) => ['hits' => array_map(fn ($id) => ['id' => $id], $found[$q->toArray()['q']] ?? [])], $queries)]);
        $this->app->instance(MeiliClient::class, $meili);
    }

    private function person(string $name, array $aliases = []): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'aiq-' . uniqid(), 'aliases' => $aliases, 'is_author' => true, 'works_count' => 50]);
    }

    public function test_an_author_named_at_either_end_of_the_query_is_found(): void
    {
        $avicenna = $this->person('ابن سینا، حسین بن عبدالله', ['شیخ الرییس']);
        $this->engine(['ابن سینا' => [$avicenna->id], 'شیخ الرییس' => [$avicenna->id]]);

        $this->assertSame(['title' => 'قانون', 'author_ids' => [$avicenna->id]], AuthorInQuery::split('قانون ابن سینا'));
        $this->assertSame(['title' => 'قانون', 'author_ids' => [$avicenna->id]], AuthorInQuery::split('ابن سینا قانون'));
        $this->assertSame(['title' => 'الشفا', 'author_ids' => [$avicenna->id]], AuthorInQuery::split('الشفا شیخ الرییس'));
    }

    public function test_a_person_the_engine_finds_by_other_words_is_not_taken_for_the_author(): void
    {
        $other = $this->person('محمد بن سینا الکاتب');
        // The engine finds him for «سینا», but «سینا» is not his name or an alias
        $this->engine(['سینا' => [$other->id]]);

        $this->assertNull(AuthorInQuery::split('قانون سینا'));
    }

    public function test_a_phrase_an_alternative_a_left_out_word_or_one_word_keep_the_plain_search(): void
    {
        $meili = \Mockery::mock(MeiliClient::class);
        $meili->shouldNotReceive('multiSearch');
        $this->app->instance(MeiliClient::class, $meili);

        $this->assertNull(AuthorInQuery::split('"قانون ابن سینا"'));
        $this->assertNull(AuthorInQuery::split('قانون | ابن سینا'));
        $this->assertNull(AuthorInQuery::split('تجرید -شرح'));
        $this->assertNull(AuthorInQuery::split('قانون'));
    }

    public function test_an_engine_failure_keeps_the_plain_search(): void
    {
        $this->app->instance(MeiliClient::class, new MeiliClient('http://127.0.0.1:9'));

        $this->assertNull(AuthorInQuery::split('قانون ابن سینا'));
    }

    public function test_the_author_search_is_weighted_up_within_the_tab_filter(): void
    {
        [$whole, $byAuthor] = AuthorInQuery::queries('works_index', 'قانون ابن سینا', ['title' => 'قانون', 'author_ids' => [7, 9]], ['titles_names'], 'languages = "عربی"');

        $this->assertSame('قانون ابن سینا', $whole->toArray()['q']);
        $this->assertSame(['languages = "عربی"'], $whole->toArray()['filter']);
        $this->assertSame('قانون', $byAuthor->toArray()['q']);
        $this->assertSame(['languages = "عربی"', 'author_id IN [7,9]'], $byAuthor->toArray()['filter']);
        $this->assertSame(AuthorInQuery::WEIGHT, $byAuthor->toArray()['federationOptions']['weight']);
    }
}
