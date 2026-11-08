<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    /**
     * Number of URLs per sitemap chunk.
     */
    public const CHUNK_SIZE = 20000;

    /**
     * Cache TTL in seconds (2 hours for a short, responsive cache).
     */
    public const CACHE_TTL = 7200;

    /**
     * Characters of text below which an entry of a source is too thin to list (a bare title, a «see …» line), unless
     * a work is tied to it (step 14.107).
     */
    public const ENTRY_MIN_TEXT = 100;

    /**
     * Sitemap Index listing all chunks.
     */
    public function index(): Response
    {
        $xml = Cache::remember('sitemap_index_xml', self::CACHE_TTL, function () {
            $types = [
                'static' => 1,
                'works' => max(1, (int) ceil(DB::table('works')->count() / self::CHUNK_SIZE)),
                'manuscripts' => max(1, (int) ceil(DB::table('manuscripts')->count() / self::CHUNK_SIZE)),
                'people' => max(1, (int) ceil(DB::table('people')->count() / self::CHUNK_SIZE)),
                'libraries' => max(1, (int) ceil(DB::table('libraries')->count() / self::CHUNK_SIZE)),
                'catalogers' => max(1, (int) ceil(DB::table('catalogers')->count() / self::CHUNK_SIZE)),
                'entries' => max(1, (int) ceil($this->entryQuery()->count() / self::CHUNK_SIZE)),
            ];

            $now = now()->toIso8601String();
            $lines = [];
            $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
            $lines[] = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

            foreach ($types as $type => $totalPages) {
                for ($p = 1; $p <= $totalPages; $p++) {
                    $loc = htmlspecialchars(route('sitemap.chunk', ['type' => $type, 'page' => $p]), ENT_XML1, 'UTF-8');
                    $lines[] = '    <sitemap>';
                    $lines[] = "        <loc>{$loc}</loc>";
                    $lines[] = "        <lastmod>{$now}</lastmod>";
                    $lines[] = '    </sitemap>';
                }
            }

            $lines[] = '</sitemapindex>';
            return implode("\n", $lines);
        });

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    /**
     * Individual sitemap chunk.
     */
    public function chunk(string $type, int $page): Response
    {
        if ($page < 1) {
            abort(404);
        }

        $cacheKey = "sitemap_{$type}_{$page}";

        $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($type, $page) {
            return $this->generateChunkXml($type, $page);
        });

        if ($xml === null) {
            abort(404);
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    /**
     * Generate the XML content for a specific sitemap chunk.
     */
    protected function generateChunkXml(string $type, int $page): ?string
    {
        @set_time_limit(180);

        $urls = [];
        $defaultDate = now()->toIso8601String();

        switch ($type) {
            case 'static':
                if ($page !== 1) {
                    return null;
                }
                $urls = $this->getStaticUrls($defaultDate);
                break;

            case 'works':
                $urls = $this->getWorkUrls($page, $defaultDate);
                if (empty($urls) && $page > 1) {
                    return null;
                }
                break;

            case 'manuscripts':
                $urls = $this->getManuscriptUrls($page, $defaultDate);
                if (empty($urls) && $page > 1) {
                    return null;
                }
                break;

            case 'people':
                $urls = $this->getPeopleUrls($page, $defaultDate);
                if (empty($urls) && $page > 1) {
                    return null;
                }
                break;

            case 'entries':
                $urls = $this->getEntryUrls($page, $defaultDate);
                if (empty($urls) && $page > 1) {
                    return null;
                }
                break;

            case 'catalogers':
                $urls = $this->getCatalogerUrls($page, $defaultDate);
                if (empty($urls) && $page > 1) {
                    return null;
                }
                break;

            case 'libraries':
                $urls = $this->getLibraryUrls($page, $defaultDate);
                if (empty($urls) && $page > 1) {
                    return null;
                }
                break;

            default:
                return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $item) {
            $loc = htmlspecialchars($item['loc'], ENT_XML1, 'UTF-8');
            $lastmod = htmlspecialchars($item['lastmod'], ENT_XML1, 'UTF-8');
            $changefreq = $item['changefreq'] ?? 'weekly';
            $priority = $item['priority'] ?? '0.7';

            $xml .= "    <url>\n";
            $xml .= "        <loc>{$loc}</loc>\n";
            $xml .= "        <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "        <changefreq>{$changefreq}</changefreq>\n";
            $xml .= "        <priority>{$priority}</priority>\n";
            $xml .= "    </url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }

    /**
     * Static and taxonomy routes.
     */
    protected function getStaticUrls(string $defaultDate): array
    {
        $urls = [
            [
                'loc' => route('home'),
                'lastmod' => $defaultDate,
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
            [
                'loc' => route('subjects.index'),
                'lastmod' => $defaultDate,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ],
            [
                'loc' => route('libraries.index'),
                'lastmod' => $defaultDate,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ],
            [
                'loc' => route('catalogers.index'),
                'lastmod' => $defaultDate,
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ],
            [
                'loc' => route('sources.index'),
                'lastmod' => $defaultDate,
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ],
            [
                'loc' => route('about'),
                'lastmod' => $defaultDate,
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ],
            [
                'loc' => route('search'),
                'lastmod' => $defaultDate,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ],
        ];

        $urls[] = [
            'loc' => route('posts.index'),
            'lastmod' => $defaultDate,
            'changefreq' => 'weekly',
            'priority' => '0.6',
        ];
        // each source the site has something of (step 13.80); its page texts stay out (noindex)
        $shelf = \App\Support\SourceShelf::counts();
        foreach (\App\Models\Source::orderBy('id')->get(['id', 'code', 'updated_at']) as $source) {
            if (array_sum($shelf[$source->id] ?? []) > 0) {
                $urls[] = [
                    'loc' => route('sources.show', $source->code),
                    'lastmod' => $source->updated_at?->toIso8601String() ?? $defaultDate,
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                ];
            }
        }
        foreach (Post::published()->orderBy('id')->get() as $post) {
            $urls[] = [
                'loc' => route('posts.show', $post),
                'lastmod' => $post->updated_at?->toIso8601String() ?? $defaultDate,
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        // All subjects taxonomy
        $subjects = DB::table('subjects')
            ->select('id', 'name', 'updated_at')
            ->orderBy('id')
            ->get();

        foreach ($subjects as $s) {
            $slug = Str::slug($s->name ?? '', '-', null) ?: 'subject';
            $urls[] = [
                'loc' => url("/subjects/{$s->id}-{$slug}"),
                'lastmod' => $s->updated_at ? Carbon::parse($s->updated_at)->toIso8601String() : $defaultDate,
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        return $urls;
    }

    /**
     * Works chunk URLs.
     */
    protected function getWorkUrls(int $page, string $defaultDate): array
    {
        $offset = ($page - 1) * self::CHUNK_SIZE;

        $rows = DB::table('works')
            ->select('id', 'clean_title', 'primary_title', 'updated_at')
            ->orderBy('id')
            ->skip($offset)
            ->take(self::CHUNK_SIZE)
            ->get();

        $urls = [];
        foreach ($rows as $row) {
            $title = $row->clean_title ?: $row->primary_title;
            $slug = Str::slug($title, '-', null);
            if (mb_strlen($slug) > 75) {
                $slug = mb_substr($slug, 0, 75);
                $lastHyphen = mb_strrpos($slug, '-');
                if ($lastHyphen > 30) {
                    $slug = mb_substr($slug, 0, $lastHyphen);
                }
            }
            $slug = $slug ?: 'work';
            $routeKey = "{$row->id}-{$slug}";

            $urls[] = [
                'loc' => url("/works/{$routeKey}"),
                'lastmod' => $row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : $defaultDate,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
        }

        return $urls;
    }

    /**
     * Manuscripts chunk URLs.
     */
    protected function getManuscriptUrls(int $page, string $defaultDate): array
    {
        $offset = ($page - 1) * self::CHUNK_SIZE;

        $rows = DB::table('manuscripts')
            ->leftJoin('works', 'manuscripts.work_id', '=', 'works.id')
            ->leftJoin('libraries', 'manuscripts.library_id', '=', 'libraries.id')
            ->select([
                'manuscripts.id',
                'manuscripts.library',
                'manuscripts.shelfmark',
                'manuscripts.updated_at',
                'works.clean_title as work_clean_title',
                'works.primary_title as work_primary_title',
                'libraries.name as library_name',
            ])
            ->orderBy('manuscripts.id')
            ->skip($offset)
            ->take(self::CHUNK_SIZE)
            ->get();

        $urls = [];
        foreach ($rows as $row) {
            $workTitle = $row->work_clean_title ?: $row->work_primary_title;
            $libName = $row->library_name ?? $row->library; // as Manuscript::getPersianSlugAttribute
            $shelfmark = $row->shelfmark;

            $parts = array_filter([$workTitle, $libName, $shelfmark]);
            $text = !empty($parts) ? implode(' ', $parts) : 'نسخه خطی';
            $slug = Str::slug($text, '-', null);
            if (mb_strlen($slug) > 80) {
                $slug = mb_substr($slug, 0, 80);
                $lastHyphen = mb_strrpos($slug, '-');
                if ($lastHyphen > 30) {
                    $slug = mb_substr($slug, 0, $lastHyphen);
                }
            }
            $slug = $slug ?: 'manuscript';
            $routeKey = "{$row->id}-{$slug}";

            $urls[] = [
                'loc' => url("/manuscripts/{$routeKey}"),
                'lastmod' => $row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : $defaultDate,
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        return $urls;
    }

    /**
     * People chunk URLs.
     */
    protected function getPeopleUrls(int $page, string $defaultDate): array
    {
        $offset = ($page - 1) * self::CHUNK_SIZE;

        $rows = DB::table('people')
            ->select('id', 'name', 'updated_at')
            ->orderBy('id')
            ->skip($offset)
            ->take(self::CHUNK_SIZE)
            ->get();

        $urls = [];
        foreach ($rows as $row) {
            // the route key is built from the name alone (Person::getPersianSlugAttribute), so the address is not a 301
            $slug = Str::slug($row->name, '-', null);
            if (mb_strlen($slug) > 75) {
                $slug = mb_substr($slug, 0, 75);
                $lastHyphen = mb_strrpos($slug, '-');
                if ($lastHyphen > 30) {
                    $slug = mb_substr($slug, 0, $lastHyphen);
                }
            }
            $slug = $slug ?: 'person';
            $routeKey = "{$row->id}-{$slug}";

            $urls[] = [
                'loc' => url("/people/{$routeKey}"),
                'lastmod' => $row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : $defaultDate,
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }

        return $urls;
    }

    /**
     * Libraries chunk URLs.
     */
    protected function getLibraryUrls(int $page, string $defaultDate): array
    {
        $offset = ($page - 1) * self::CHUNK_SIZE;

        $rows = DB::table('libraries')
            ->select('id', 'name', 'full_name', 'city', 'updated_at')
            ->orderBy('id')
            ->skip($offset)
            ->take(self::CHUNK_SIZE)
            ->get();

        $urls = [];
        foreach ($rows as $row) {
            $name = $row->full_name ?: ($row->name . ($row->city ? ' ' . $row->city : ''));
            $slug = Str::slug($name, '-', null);
            if (mb_strlen($slug) > 75) {
                $slug = mb_substr($slug, 0, 75);
                $lastHyphen = mb_strrpos($slug, '-');
                if ($lastHyphen > 30) {
                    $slug = mb_substr($slug, 0, $lastHyphen);
                }
            }
            $slug = $slug ?: 'library';
            $routeKey = "{$row->id}-{$slug}";

            $urls[] = [
                'loc' => url("/libraries/{$routeKey}"),
                'lastmod' => $row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : $defaultDate,
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }

        return $urls;
    }

    /**
     * Catalogers chunk URLs (backlog item 72): their pages are public records too. Built by the model, which has few
     * rows, so the address is its route key.
     */
    protected function getCatalogerUrls(int $page, string $defaultDate): array
    {
        return \App\Models\Cataloger::orderBy('id')->skip(($page - 1) * self::CHUNK_SIZE)->take(self::CHUNK_SIZE)
            ->get(['id', 'name', 'updated_at'])
            ->map(fn ($c) => [
                'loc' => route('catalogers.show', $c),
                'lastmod' => $c->updated_at?->toIso8601String() ?? $defaultDate,
                'changefreq' => 'monthly',
                'priority' => '0.5',
            ])->all();
    }

    /**
     * The entries of the sources worth a search engine's visit (step 14.107): those with some text of their own, or
     * tied to a work. A printed page of a source stays out (noindex), since its text is these entries' text.
     */
    protected function entryQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('source_items')
            ->where(fn ($q) => $q->whereRaw('CHAR_LENGTH(COALESCE(source_items.text, \'\')) >= ?', [self::ENTRY_MIN_TEXT])
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('source_entries')
                    ->whereColumn('source_entries.source_item_id', 'source_items.id')
                    ->where('source_entries.citable_type', \App\Models\Work::class)
                    ->where('source_entries.status', '!=', \App\Models\SourceEntry::STATUS_REJECTED)));
    }

    /**
     * Source entries chunk URLs: /sources/dharia/3/1442, built as SourceItem::url builds them.
     */
    protected function getEntryUrls(int $page, string $defaultDate): array
    {
        $rows = $this->entryQuery()
            ->join('sources', 'sources.id', '=', 'source_items.source_id')
            ->select('source_items.id', 'source_items.volume_number', 'source_items.item_key', 'source_items.updated_at', 'sources.code')
            ->orderBy('source_items.id')
            ->skip(($page - 1) * self::CHUNK_SIZE)
            ->take(self::CHUNK_SIZE)
            ->get();

        return $rows->map(fn ($row) => [
            'loc' => route('source-items.show', [$row->code, $row->volume_number, (new \App\Models\SourceItem(['item_key' => $row->item_key]))->urlKey()]),
            'lastmod' => $row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : $defaultDate,
            'changefreq' => 'monthly',
            'priority' => '0.5',
        ])->all();
    }
}
