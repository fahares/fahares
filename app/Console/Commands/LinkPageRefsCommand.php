<?php

namespace App\Console\Commands;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourcePage;
use App\Support\PageLinks\CopyResolver;
use App\Support\PageLinks\EntryResolver;
use App\Support\PageLinks\HoldingResolver;
use App\Support\PageLinks\Holdings;
use App\Support\PageLinks\PageText;
use App\Support\PageLinks\References;
use App\Support\PageLinks\Shelfmarks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Links in a source's page texts that go further than its marks (step 13.155, plan docs/plans/page-text-links.md), for
 * any source config('page_links') gives forms for:
 * - copies: the shelfmark after a mark linked to a library («(سپهسالار: 404)») links the copy (CopyResolver), the mark
 *   and the number both. The library's link stays: the page shows the copy's, and the library's when an editor
 *   rejects that.
 * - references: a reference to a volume and page of the source itself («مر في (161:7) بعنوان خسرو و گل») links the
 *   entry it names there, or the page (References, EntryResolver).
 * - holdings: a line of a source without marks naming a library's copy by its catalogue page or number («دانشگاه
 *   2705/12 ش (1) 3701», Monzavi, step 14.34) links the copy (Holdings, HoldingResolver).
 *
 * The links go to source_page_links (a copy's with its mark's ordinal and kind, a reference's with kind «ref» and a
 * holding's with kind «holding», both with no ordinal), made again on every run but for those an editor rejected; only
 * the ENABLED methods are saved.
 */
class LinkPageRefsCommand extends Command
{
    public const KIND_REFERENCE = 'ref';

    public const KIND_HOLDING = 'holding';

    /** The methods whose links are saved: those a hand check found right in 49 of 50 or more (steps 13.155, 14.34). */
    public const ENABLED = [
        'copies' => ['exact', 'part'],
        'references' => ['title', 'context', 'page', 'page-titled'],
        // step 14.34: all that link one copy; of about 160 read by hand all but two looked right, «page-not-number» mostly a
        // misprinted number (Monzavi's 10177 for 10172) or an old one on the same catalogue page
        'holdings' => ['work+both', 'work+page', 'work+shelfmark', 'work+page-not-number', 'title+both', 'title+page', 'title+shelfmark', 'title+page-not-number'],
    ];

    protected $signature = 'sources:link-page-refs
        {source : The source\'s code, one config(\'page_links\') has}
        {--only= : copies, references or holdings (default all the source has)}
        {--volume= : Only this volume}
        {--sample= : Write a random sample of the links of each method, with their context, to this file}
        {--per-kind=50 : Links of each method in the sample}
        {--all : Save the links of every method, not only the ENABLED ones (for measuring)}
        {--dry-run : Match and count without saving}';

    protected $description = 'Link the shelfmarks and the references to the source itself in a source\'s page texts to their copies and entries';

    public function handle(): int
    {
        $source = Source::where('code', $this->argument('source'))->firstOrFail();
        $config = config('page_links.' . $source->code);
        if (! $config) {
            $this->error("config/page_links.php has no forms for {$source->code}");

            return self::FAILURE;
        }
        $parts = $this->option('only') ? [$this->option('only')] : array_keys(array_intersect_key(self::ENABLED, $config));
        if (array_diff($parts, array_keys(self::ENABLED))) {
            $this->error('--only is copies, references or holdings');

            return self::FAILURE;
        }

        foreach ($parts as $part) {
            $this->info("== {$part}");
            [$links, $count] = match ($part) {
                'copies' => $this->copies($source, $config['copies']),
                'holdings' => $this->holdings($source, $config['holdings']),
                default => $this->references($source, $config['references']),
            };
            arsort($count);
            $this->table(['method', 'count'], array_map(fn ($k, $n) => [$k, $n], array_keys($count), $count));
            if ($this->option('sample')) {
                $this->sample($links, $part);
            }
            if (! $this->option('all')) {
                $links = array_values(array_filter($links, fn ($l) => in_array($l['method'], self::ENABLED[$part], true)));
            }
            $this->info(count($links) . ' links');
            if (! $this->option('dry-run')) {
                $this->save($source, $part, $links, $config['copies']['marks'] ?? []);
            }
        }

        return self::SUCCESS;
    }

    /** The source's pages, each with its entries, a chunk at a time; only $ids when given. */
    private function pages(Source $source, callable $each, ?\Illuminate\Support\Collection $ids = null): void
    {
        DB::table('source_pages')->join('source_volumes', 'source_volumes.id', '=', 'source_pages.source_volume_id')
            ->where('source_volumes.source_id', $source->id)
            ->when($this->option('volume'), fn ($q, $v) => $q->where('source_volumes.volume_number', $v))
            ->when($ids !== null, fn ($q) => $q->whereIn('source_pages.id', $ids))
            ->orderBy('source_pages.id')
            ->select('source_pages.id', 'source_pages.page', 'source_pages.text', 'source_pages.source_volume_id', 'source_volumes.volume_number')
            ->chunk(500, function ($chunk) use ($each) {
                $items = SourceItem::whereIn('source_volume_id', $chunk->pluck('source_volume_id')->unique())
                    ->whereIn('kind', [SourceItem::KIND_ENTRY, SourceItem::KIND_SUB_ENTRY])
                    ->where('page_start', '<=', $chunk->max('page'))->where('page_end', '>=', $chunk->min('page'))
                    ->orderBy('position')->get(['id', 'source_volume_id', 'volume_number', 'page_start', 'page_end', 'title', 'text']);
                foreach ($chunk as $page) {
                    $each(new PageText($page, $items->filter(fn ($i) => $i->source_volume_id === $page->source_volume_id
                        && $i->page_start <= $page->page && $i->page_end >= $page->page)->values()));
                }
            });
    }

    private function copies(Source $source, array $config): array
    {
        $libraries = DB::table('source_page_links')->whereIn('kind', $config['marks'])->where('target_type', Library::class)
            ->whereNull('rejected_at')->get(['source_page_id', 'ordinal', 'target_id'])->groupBy('source_page_id');
        $links = [];
        $count = [];
        $this->pages($source, function (PageText $text) use ($libraries, $config, &$links, &$count) {
            $byOrdinal = $libraries[$text->page->id]->keyBy('ordinal');
            foreach ($text->marks() as $mark) {
                $library = $byOrdinal[$mark['ordinal']] ?? null;
                if (! $library || ! ($shelfmark = Shelfmarks::after($text->page->text, $mark, $config['skip_words'] ?? []))) {
                    continue;
                }
                $holder = $text->holder($mark['at']);
                [$copy, $method] = CopyResolver::resolve($library->target_id, $shelfmark, $holder);
                $count[$method] = ($count[$method] ?? 0) + 1;
                $links[] = ['page' => $text->page, 'ordinal' => $mark['ordinal'], 'kind' => $mark['kind'], 'text' => $mark['text'],
                    'offset' => $shelfmark['offset'], 'span' => $shelfmark['span'], 'target' => $copy, 'method' => $method,
                    'shelfmark' => $shelfmark, 'holder' => $holder];
            }
        }, $libraries->keys());

        return [array_values(array_filter($links, fn ($l) => $l['target'])), $count, $links];
    }

    private function references(Source $source, array $config): array
    {
        $resolver = new EntryResolver($source->id);
        $links = [];
        $count = [];
        $this->pages($source, function (PageText $text) use ($resolver, $config, &$links, &$count) {
            foreach (References::in($text, $config) as $ref) {
                $holder = $text->holder($ref['offset']);
                [$type, $id, $method] = $resolver->resolve($ref, $text->page, $holder);
                $count[$method] = ($count[$method] ?? 0) + 1;
                if ($type) {
                    $links[] = ['page' => $text->page, 'ordinal' => null, 'kind' => self::KIND_REFERENCE, 'text' => $ref['span'],
                        'offset' => $ref['offset'], 'span' => $ref['span'], 'target' => [$type, $id], 'method' => $method,
                        'ref' => $ref, 'holder' => $holder];
                }
            }
        });

        return [$links, $count];
    }

    private function holdings(Source $source, array $config): array
    {
        $resolver = new HoldingResolver($config['libraries']);
        $forms = $resolver->forms();
        $links = [];
        $count = [];
        $this->pages($source, function (PageText $text) use ($resolver, $forms, &$links, &$count) {
            foreach (Holdings::in($text->page->text, $forms) as $holding) {
                $holder = $text->holder($holding['offset']);
                [$copy, $method] = $resolver->resolve($holding, $holder);
                $count[$method] = ($count[$method] ?? 0) + 1;
                if ($copy) {
                    $links[] = ['page' => $text->page, 'ordinal' => null, 'kind' => self::KIND_HOLDING, 'text' => $holding['span'],
                        'offset' => $holding['offset'], 'span' => $holding['span'], 'target' => $copy, 'method' => $method, 'holder' => $holder];
                }
            }
        });

        return [$links, $count];
    }

    /** The links of the part made again, but for those an editor rejected. */
    private function save(Source $source, string $part, array $links, array $marks): void
    {
        $now = now();
        $types = $part === 'references' ? [SourceItem::class, SourcePage::class] : [Manuscript::class];
        DB::transaction(function () use ($source, $part, $links, $marks, $now, $types) {
            $scope = DB::table('source_page_links')->whereIn('target_type', $types)
                ->where(fn ($q) => $part === 'copies' ? $q->whereIn('kind', $marks) : $q->where('kind', $part === 'holdings' ? self::KIND_HOLDING : self::KIND_REFERENCE))
                ->whereIn('source_page_id', DB::table('source_pages')->join('source_volumes', 'source_volumes.id', '=', 'source_pages.source_volume_id')
                    ->where('source_volumes.source_id', $source->id)
                    ->when($this->option('volume'), fn ($q, $v) => $q->where('source_volumes.volume_number', $v))
                    ->select('source_pages.id'));
            $key = fn ($pageId, $ordinal, $offset) => $pageId . ':' . ($part === 'copies' ? $ordinal : $offset);
            $rejected = (clone $scope)->whereNotNull('rejected_at')->get(['source_page_id', 'ordinal', 'offset'])
                ->keyBy(fn ($r) => $key($r->source_page_id, $r->ordinal, $r->offset));
            $deleted = (clone $scope)->whereNull('rejected_at')->delete();
            $rows = [];
            foreach ($links as $l) {
                if (isset($rejected[$key($l['page']->id, $l['ordinal'], $l['offset'])])) {
                    continue;
                }
                [$type, $id] = is_array($l['target']) ? $l['target'] : [$l['target']::class, $l['target']->id];
                $rows[] = [
                    'source_page_id' => $l['page']->id, 'ordinal' => $l['ordinal'], 'offset' => $l['offset'], 'span' => $l['span'],
                    'kind' => $l['kind'], 'text' => mb_substr($l['text'], 0, 500), 'target_type' => $type, 'target_id' => $id,
                    'method' => $l['method'], 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('source_page_links')->insertOrIgnore($chunk);
            }
            $this->info('Saved ' . count($rows) . " links (removed {$deleted} of the last run, kept " . $rejected->count() . ' rejected)');
        });
    }

    private function sample(array $links, string $part): void
    {
        $out = [];
        $one = fn (string $s) => trim(preg_replace('/\s+/u', ' ', \App\Support\PageMarks::strip($s)));
        foreach (collect($links)->groupBy('method') as $method => $group) {
            $per = (int) $this->option('per-kind');
            $out[] = "===== {$part} {$method} ({$group->count()} links) =====";
            foreach (($group->count() <= $per ? $group : $group->shuffle(13155)->take($per))->values() as $n => $l) {
                $p = $l['page'];
                $at = $l['offset'] ?? 0;
                $context = $one(mb_substr($p->text, max(0, $at - 90), min(90, $at))) . ' [[' . $l['span'] . ']] ' . $one(mb_substr($p->text, $at + mb_strlen((string) $l['span']), 60));
                if ($part !== 'references') {
                    $m = $l['target'];
                    $target = "#{$m->id} {$m->libraryRecord?->name} {$m->shelfmark} «" . ($m->work?->title ?? '') . '»';
                    $context = $part === 'holdings' ? $context : '«' . $l['text'] . '» ' . ($l['offset'] === null ? '' : $context);
                } else {
                    [$type, $id] = $l['target'];
                    $target = $type === SourceItem::class
                        ? (fn ($i) => "مدخل ج {$i->volume_number} ص {$i->page_start} «{$i->title}»")(SourceItem::find($id))
                        : "صفحه ج {$l['ref']['volume']} ص {$l['ref']['page']}" . ($l['ref']['title'] ? " (عنوان «{$l['ref']['title']}»)" : '');
                }
                $out[] = sprintf("%s-%d ج %d ص %d، مدخل «%s»: …%s…\n    → %s", $method, $n + 1, $p->volume_number, $p->page,
                    $l['holder']?->title ?? '', $context, $target);
            }
        }
        file_put_contents($this->option('sample') . ".{$part}.txt", implode("\n", $out) . "\n");
        $this->info('Sample written to ' . $this->option('sample') . ".{$part}.txt");
    }
}
