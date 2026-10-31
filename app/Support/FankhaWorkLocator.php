<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Finds the site's work for a work of the parser v2 JSON. The slug suffix «-v{vol}-{ordinal}» is not enough: works v2
 * restored from the page images (item 87) moved the ordinals after them, while the site's slugs keep the old ones, so
 * the suffix led 138 translators of step 13.68 to a neighbouring work. A work is the one in the same volume with the
 * same title (without a closing «(ترجمه)» and the like) whose page is within one of v2's; works of one title on one
 * page pair in their order. Anything else is not found.
 */
class FankhaWorkLocator
{
    /** @var array<string, array<int, object>> "vol|title" => the site's works, in slug order */
    private array $site = [];

    /** @var array<string, int> "vol|ordinal" of the slug suffix => work id */
    private array $bySuffix = [];

    /** @var array<string, array<int, array>> "vol|title" => v2 works of that title (ordinal, page) */
    private array $v2 = [];

    /** @param iterable<array> $v2Works every work of the v2 JSON (its key, title and provenance) */
    public function __construct(iterable $v2Works)
    {
        foreach ($v2Works as $w) {
            $this->v2[$w['key']['vol'] . '|' . self::title($w['title'] ?? '')][] = ['ordinal' => (int) $w['key']['ordinal'], 'page' => (int) ($w['provenance']['page_start'] ?? 0)];
        }
        DB::table('works')->select(['id', 'slug', 'primary_title', 'volume_number', 'page_start'])->orderBy('id')->chunk(10000, function ($works) {
            foreach ($works as $w) {
                $w->ordinal = preg_match('/-v\d+-(\d+)$/', (string) $w->slug, $m) ? (int) $m[1] : PHP_INT_MAX;
                $this->site[$w->volume_number . '|' . self::title($w->primary_title)][] = $w;
                if (preg_match('/-v(\d+)-(\d+)$/', (string) $w->slug, $m)) {
                    $this->bySuffix[$m[1] . '|' . $m[2]] ??= (int) $w->id;
                }
            }
        });
        foreach ($this->site as &$works) {
            usort($works, fn ($a, $b) => $a->ordinal <=> $b->ordinal);
        }
    }

    /** The site's work id for v2 work (vol, ordinal, title, page), or null when it is not one work for certain. */
    public function find(int $vol, int $ordinal, string $title, int $page): ?int
    {
        $key = $vol . '|' . self::title($title);
        $near = fn (int $p) => abs($p - $page) <= 1;
        $candidates = array_values(array_filter($this->site[$key] ?? [], fn ($w) => $near((int) $w->page_start)));
        if (count($candidates) === 1) {
            return (int) $candidates[0]->id;
        }
        if (! $candidates) {
            return null;
        }
        // «پاتولوژی» twice on one page: the n-th of v2 is the n-th of the site
        $peers = array_values(array_filter($this->v2[$key] ?? [], fn ($w) => $near($w['page'])));
        usort($peers, fn ($a, $b) => $a['ordinal'] <=> $b['ordinal']);
        if (count($peers) !== count($candidates)) {
            return null;
        }
        $at = array_search($ordinal, array_column($peers, 'ordinal'), true);

        return $at === false ? null : (int) $candidates[$at]->id;
    }

    /** The work whose slug ends in «-v{vol}-{ordinal}» (an index lookup instead of a LIKE over every slug). */
    public function bySlugSuffix(int $vol, int $ordinal): ?int
    {
        return $this->bySuffix["{$vol}|{$ordinal}"] ?? null;
    }

    /** A title compared without spaces, punctuation, a closing parenthesis («(ترجمه)») and the article «ال». */
    public static function title(?string $title): string
    {
        $t = strtr((string) $title, ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ی']);
        $t = preg_replace('/\s*\([^()]*\)\s*$/u', '', $t);
        $t = preg_replace('/[\s\x{200c}ـ«»()\[\]،.:؛\-]/u', '', $t);

        return preg_replace('/^ال/u', '', $t);
    }
}
