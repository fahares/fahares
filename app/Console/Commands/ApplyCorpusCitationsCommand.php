<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Suggestions\ProtectedColumns;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Moves the catalogue citations corpus v1.1.6 found («[رایانه]»، «[الفبائی: -512]») from a copy's editorial notes
 * to its catalog_citation. A copy is changed only where it still has no citation and holds the note at the place
 * the old release put it; anything else is reported.
 */
class ApplyCorpusCitationsCommand extends Command
{
    protected $signature = 'fahares:apply-corpus-citations
                            {--file= : Path to the diff file (database/data/corpus_v1.1.6_citations.json)}
                            {--apply : Write the changes; without it the command only reports what it would do}';

    protected $description = 'Move the catalogue citations of a corpus release from the editorial notes of copies to their catalog_citation';

    public function handle(): int
    {
        $filePath = $this->option('file') ?: database_path('data/corpus_v1.1.6_citations.json');
        $diff = File::exists($filePath) ? json_decode(File::get($filePath), true) : null;
        if (! $diff || ! isset($diff['copies'])) {
            $this->error("Diff file missing or invalid: {$filePath}");
            return 1;
        }

        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'Applying' : 'Dry run of') . " corpus {$diff['from']} → {$diff['to']}: " . count($diff['copies']) . ' copies');

        $guard = ProtectedColumns::for(Manuscript::class);
        $counts = array_fill_keys(['moved', 'already', 'protected', 'conflict', 'missing'], 0);
        $problems = [];
        $updates = [];
        $used = [];

        // work slugs end in «-v{volume}-{number}»; the works of a volume are looked up once
        $works = [];
        foreach (collect($diff['copies'])->pluck(0)->unique() as $volume) {
            DB::table('works')->where('volume_number', $volume)->select(['id', 'slug'])->orderBy('id')
                ->each(function ($w) use (&$works, $volume) {
                    if (preg_match('/-v' . $volume . '-(\d+)$/', $w->slug, $m)) {
                        $works[$volume][(int) $m[1]][] = $w->id;
                    }
                });
        }

        foreach ($diff['copies'] as [$volume, $number, $sequence, $shelfmark, $citation, $at]) {
            $label = "v{$volume}-{$number} #{$sequence} «{$shelfmark}» {$citation}";
            $note = mb_substr($citation, 1, -1);
            $row = $this->find($works[$volume][$number] ?? [], $volume, $sequence, $shelfmark, $note, $citation, $used);
            if (! $row) {
                $counts['missing']++;
                $problems[] = "{$label}: no single copy found (moved or deleted?)";
                continue;
            }
            $used[$row->id] = true;

            $meta = json_decode($row->metadata ?? '{}', true) ?: [];
            $notes = $meta['editorial_notes'] ?? [];
            if (($meta['catalog_citation'] ?? null) === $citation && ! in_array($note, $notes, true)) {
                $counts['already']++;
                continue;
            }
            if (! empty($meta['catalog_citation']) || ($notes[$at] ?? null) !== $note) {
                $counts['conflict']++;
                $problems[] = "#{$row->id} {$label}: the site has another citation or edited notes, left as is";
                continue;
            }
            if (! $guard->strip($row->id, ['metadata' => true])) {
                $counts['protected']++;
                $problems[] = "#{$row->id} {$label}: metadata has a reviewed correction, left as is";
                continue;
            }

            array_splice($notes, $at, 1);
            $meta['editorial_notes'] = $notes;
            $meta['catalog_citation'] = $citation;
            $updates[$row->id] = json_encode($meta, JSON_UNESCAPED_UNICODE);
            $counts['moved']++;
        }

        if ($apply && $updates) {
            foreach (array_chunk($updates, 1000, true) as $chunk) {
                DB::transaction(function () use ($chunk) {
                    foreach ($chunk as $id => $metadata) {
                        DB::table('manuscripts')->where('id', $id)->update(['metadata' => $metadata]);
                    }
                    // the citation is the library catalogue page Fankha cites (step 13.4)
                    SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', array_keys($chunk))->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
                });
            }
        }

        $this->table(['outcome', 'copies'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info(($apply ? 'Updated ' : 'Would update ') . count($updates) . ' copies.');
        foreach ($problems as $line) {
            $this->warn($line);
        }

        return 0;
    }

    /**
     * The copy in its work with this sequence and shelfmark, the one holding the note when several share them (Fankha
     * lists a few copies twice, and some sequence numbers repeat in a work). When its work was merged or renumbered, the one copy of that volume and
     * shelfmark that still holds the note or the citation.
     */
    private function find(array $workIds, int $volume, int $sequence, ?string $shelfmark, string $note, string $citation, array $used): ?object
    {
        if ($workIds) {
            $rows = DB::table('manuscripts')->whereIn('work_id', $workIds)->where('sequence_number', $sequence)
                ->orderBy('id')->get(['id', 'shelfmark', 'metadata'])
                ->filter(fn ($r) => $r->shelfmark === $shelfmark && ! isset($used[$r->id]));
            if ($rows->isNotEmpty()) {
                return $rows->first(fn ($r) => $this->holds($r, $note, $citation)) ?? $rows->first();
            }
        }
        if ($shelfmark === null) {
            return null;
        }
        $rows = DB::table('manuscripts')->where('volume_number', $volume)->where('shelfmark', $shelfmark)->get(['id', 'shelfmark', 'metadata'])
            ->filter(fn ($r) => $this->holds($r, $note, $citation) && ! isset($used[$r->id]));

        return $rows->count() === 1 ? $rows->first() : null;
    }

    /** Whether the copy still holds the note, or already has the citation. */
    private function holds(object $row, string $note, string $citation): bool
    {
        $meta = json_decode($row->metadata ?? '{}', true) ?: [];

        return ($meta['catalog_citation'] ?? null) === $citation
            || (empty($meta['catalog_citation']) && in_array($note, $meta['editorial_notes'] ?? [], true));
    }
}
