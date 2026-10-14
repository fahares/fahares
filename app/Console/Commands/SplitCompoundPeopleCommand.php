<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\EntityRedirect;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Services\PersonMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Splits person records that hold several people («الف و ب», «الف و دیگران») into the
 * individual people, linking each manuscript or work to all of them through the pivots.
 *
 *   propose: classify every candidate and write a decisions file for review.
 *   apply:   carry out the reviewed decisions. Safe to re-run: split records are gone.
 *
 * A reviewed decision (overrides file) may also: keep a part as a name without a person record
 * (part "text": true), set fields on a part's person (part "set": bio_notes, is_translator), edit
 * the record itself without splitting (decision "edit" with "person_set"), set fields on the
 * entry's manuscripts ("manuscript_set": commissioned_by, is_autograph), and list duplicate
 * people to merge first ("merges").
 */
class SplitCompoundPeopleCommand extends Command
{
    protected $signature = 'fahares:split-compound-people
                            {action : propose | apply}
                            {--file= : Decisions JSON (default: database/data/compound_people.json)}
                            {--overrides= : With propose, a reviewed judgement file (default: database/data/compound_people_overrides.json)}
                            {--only= : Comma-separated person ids to limit propose or apply to}
                            {--dry-run : With apply, report what would change without writing}';

    protected $description = 'Split compound person records (e.g. «الف و ب», «الف و دیگران») into individual people';

    /** «و دیگران» and its variants at the end of a name */
    protected const OTHERS = '/\s*[،,]?\s*و\s*(?:دیگران|دیگری|غیره|جز\s*او|جز\s*آنها|جز\s*ایشان|سایرین|چند\s*تن\s*دیگر)\s*\.?\s*$/u';

    /** Phrases whose «و» belongs to a single person's description, not a list of people */
    protected const SINGLE_PERSON_PHRASES = [
        '/الاصل\s+و\s+/u', '/\s+و\s+(?:ال)?(?:مسکن|مولد|منشا|موطن|مدفن)/u', '/(?:اً|ا)\s+و\s+ال\S+\s+\S+(?:اً|ا)$/u',
        '/الاشباه\s+و\s+النظائر/u', '/(?:ملقب|متخلص|مشهور|معروف|شهیر)\s.*\s+و\s+(?:ملقب|متخلص|مشهور|معروف|شهیر)/u',
        '/لوالدیه/u', '/حروف\s+مقطع/u', '/سالگی/u', '/خط\s+خورده/u',
    ];

    /** A part starting with one of these is a description of the previous name, not a person */
    protected const DESCRIPTIVE_STARTS = [
        'متخلص', 'ملقب', 'مشهور', 'معروف', 'معرف', 'شهیر', 'مامور', 'مأمور', 'واعظ', 'خطیب', 'خادم', 'معید', 'مدرس', 'کحال', 'سقا',
        'والی', 'نسبه', 'ابن', 'پسرش', 'فرزندش', 'برادرش', 'حسینی', 'حسنی', 'موسوی', 'لوالدیه', 'از', 'که', 'در', 'با', 'به', 'من',
        'تا', 'خط', 'بخش', 'جزء', 'جلد', 'کتاب', 'کتابی', 'احکام', 'اصلا', 'اصلاً', 'بلدا', 'بلداً', 'مولدا', 'مولداً',
    ];

    /** Text inside a part that shows it is not a plain name */
    protected const NON_NAME_MARKERS = '/[:<>]|(?:^|\s)(?:است|علیهما|علیه\s+السلام)(?:\s|$)|\S+اً(?:\s|$)|(?:^|\s)(?:مولدا|اصلا|بلدا|نسبا|مذهبا|عقیده|عقيدة|منشا|منشاء|مسکنا)(?:\s|$)/u';

    protected array $nameIndex = [];
    protected array $compactIndex = [];

    /** People created during this apply run, by normalised name, so a repeated new name is created once */
    protected array $createdPeople = [];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/compound_people.json');

        return match ($this->argument('action')) {
            'propose' => $this->propose($path),
            'apply' => $this->apply($path),
            default => $this->invalidAction(),
        };
    }

    protected function sameName(string $a, string $b): bool
    {
        return TextNormalizer::normalize(CleanPageMarkersCommand::strip($a)) === TextNormalizer::normalize(CleanPageMarkersCommand::strip($b));
    }

    /** @return int[] */
    protected function onlyIds(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->option('only')))));
    }

    protected function invalidAction(): int
    {
        $this->error('Action must be "propose" or "apply".');
        return self::FAILURE;
    }

    // ------------------------------------------------------------------ propose

    protected function propose(string $path): int
    {
        $this->buildNameIndex();
        $overridesPath = $this->option('overrides') ?: database_path('data/compound_people_overrides.json');
        $overridesFile = is_file($overridesPath) ? json_decode(file_get_contents($overridesPath), true) : [];
        $overrides = collect($overridesFile['entries'] ?? [])->keyBy('person_id')->all();

        $candidates = Person::query()
            ->where(fn($q) => $q->where('name', 'like', '% و %')->orWhere('name', 'like', '%،و %'))
            ->when($this->onlyIds(), fn($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        $entries = [];
        $stats = [];
        foreach ($candidates as $person) {
            $entry = $this->classify($person);
            if (isset($overrides[$person->id])) {
                $entry = $this->applyOverride($entry, $overrides[$person->id]);
            }
            $stats[$entry['decision']] = ($stats[$entry['decision']] ?? 0) + 1;
            $entries[] = $entry;
        }

        $payload = [
            'generated_at' => now()->toDateString(),
            'note' => 'decision: split | others | edit | keep | review | not_a_name. Only split, others and edit are applied. Change decisions in the overrides file and re-run propose.',
            'stats' => $stats,
            'merges' => $overridesFile['merges'] ?? [],
            'entries' => $entries,
        ];

        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        foreach ($stats as $decision => $count) {
            $this->line(sprintf('%-11s %d', $decision, $count));
        }
        $this->info("Wrote {$path} (" . count($entries) . ' entries, ' . count($overrides) . ' overrides).');

        return self::SUCCESS;
    }

    protected function classify(Person $person): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $person->name));
        $entry = [
            'person_id' => $person->id,
            'name' => $person->name,
            'manuscript_ids' => DB::table('manuscript_scribe')->where('person_id', $person->id)->pluck('manuscript_id')->all(),
            'work_ids' => DB::table('work_author')->where('person_id', $person->id)->pluck('work_id')->all(),
            'decision' => 'review',
            'reason' => null,
            'has_others' => false,
            'parts' => [],
        ];

        // A work description glued onto an author name, e.g. «نوایی (؟). مثنوی‌ای است …»
        if (preg_match('/[.؟?)]\s*\.\s|\.\s+\S+\s+\S+\s+\S+/u', $name) && mb_strlen($name) > 60) {
            return ['decision' => 'not_a_name', 'reason' => 'description glued to the name'] + $entry;
        }

        $base = $name;
        if (preg_match(self::OTHERS, $base)) {
            $entry['has_others'] = true;
            $base = trim(preg_replace(self::OTHERS, '', $base));
        }

        foreach (self::SINGLE_PERSON_PHRASES as $pattern) {
            if (preg_match($pattern, $this->outsideParentheses($base))) {
                return ['decision' => $entry['has_others'] ? 'review' : 'keep', 'reason' => 'single-person phrase with «و»'] + $entry;
            }
        }

        $rawParts = $this->splitTopLevel($base);
        $entry['parts'] = array_map(fn($p) => $this->describePart($p, $person->id), $rawParts);

        if (count($rawParts) === 1) {
            if (! $entry['has_others']) {
                return ['decision' => 'keep', 'reason' => '«و» only inside parentheses'] + $entry;
            }
            $entry['decision'] = 'others';
            $entry['reason'] = 'one named person and unnamed others';
            return $this->flagUncertainParts($entry);
        }

        $entry['decision'] = 'split';
        $entry['reason'] = count($rawParts) . ' named people';
        return $this->flagUncertainParts($entry);
    }

    /**
     * Replace the heuristic reading with a reviewed one; names are still matched here.
     */
    protected function applyOverride(array $entry, array $override): array
    {
        if (! $this->sameName($override['name'] ?? '', $entry['name'])) {
            $this->warn("Override for #{$entry['person_id']} names «{$override['name']}», record is «{$entry['name']}»; ignored.");
            return $entry;
        }

        $entry['decision'] = $override['decision'];
        $entry['reason'] = $override['comment'] ?? 'reviewed';
        $entry['has_others'] = (bool) ($override['has_others'] ?? false);
        $entry['parts'] = [];
        unset($entry['proposed']);
        foreach (['person_set', 'manuscript_set'] as $key) {
            if (! empty($override[$key])) {
                $entry[$key] = $override[$key];
            }
        }

        foreach ($override['parts'] ?? [] as $part) {
            $text = (bool) ($part['text'] ?? false);
            $candidates = $text ? [] : array_values(array_diff($this->matchPeople($part['name']), [$entry['person_id']]));
            $entry['parts'][] = array_filter([
                'name' => CleanPageMarkersCommand::strip($part['name']),
                'note' => $part['note'] ?? null,
                'text' => $text ?: null,
                'person_id' => $part['person_id'] ?? (count($candidates) === 1 ? $candidates[0] : null),
                'candidates' => count($candidates) > 1 ? $candidates : [],
                'set' => $part['set'] ?? null,
            ], fn($v) => $v !== null) + ['note' => null, 'person_id' => null, 'candidates' => []];
        }

        $ambiguous = array_filter($entry['parts'], fn($p) => empty($p['text']) && ! $p['person_id'] && $p['candidates']);
        if ($ambiguous && in_array($entry['decision'], ['split', 'others'], true)) {
            $entry['proposed'] = $entry['decision'];
            $entry['decision'] = 'review';
            $entry['reason'] = implode('; ', array_map(fn($p) => "«{$p['name']}» matches " . count($p['candidates']) . ' people', $ambiguous));
        }

        return $entry;
    }

    /**
     * Downgrade to review when any part does not look like a standalone name or has several matches.
     */
    protected function flagUncertainParts(array $entry): array
    {
        $problems = [];
        foreach ($entry['parts'] as $part) {
            $tokens = preg_split('/\s+/u', $part['name']);
            $first = $tokens[0] ?? '';

            if ($part['name'] === '') {
                $problems[] = 'empty part';
            } elseif (count($entry['parts']) > 1 && count($tokens) < 2) {
                $problems[] = "single word «{$part['name']}»";
            } elseif (in_array($first, self::DESCRIPTIVE_STARTS, true) || str_starts_with($first, 'ال')
                || preg_match(self::NON_NAME_MARKERS, $part['name'])) {
                $problems[] = "«{$part['name']}» reads as a description";
            } elseif (count($tokens) > 14) {
                $problems[] = "«{$part['name']}» is unusually long";
            }

            if (str_contains((string) $part['note'], '؟')) {
                $problems[] = 'uncertain reading (؟)';
            }
            if (count($part['candidates']) > 1) {
                $problems[] = "«{$part['name']}» matches " . count($part['candidates']) . ' people';
            }
        }

        if ($problems) {
            $entry['proposed'] = $entry['decision'];
            $entry['decision'] = 'review';
            $entry['reason'] = implode('; ', array_unique($problems));
        }

        return $entry;
    }

    /**
     * Split on « و » and, in a list such as «الف، ب و ج», on «،» — never inside parentheses.
     */
    protected function splitTopLevel(string $text): array
    {
        $masked = $this->outsideParentheses($text);
        $hasTopLevelAnd = (bool) preg_match('/\s+و\s+/u', $masked);

        $parts = [];
        $offset = 0;
        $pattern = $hasTopLevelAnd ? '/\s+و\s+|\s*،\s*/u' : '/\s+و\s+/u';
        preg_match_all($pattern, $masked, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$sep, $pos]) {
            $parts[] = substr($text, $offset, $pos - $offset);
            $offset = $pos + strlen($sep);
        }
        $parts[] = substr($text, $offset);

        return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
    }

    /**
     * The text with every parenthesised span blanked out, keeping byte offsets intact.
     */
    protected function outsideParentheses(string $text): string
    {
        $depth = 0;
        $out = '';
        foreach (mb_str_split($text) as $ch) {
            if ($ch === '(' || $ch === '«') {
                $depth++;
            }
            $out .= $depth > 0 ? str_repeat('_', strlen($ch)) : $ch;
            if (($ch === ')' || $ch === '»') && $depth > 0) {
                $depth--;
            }
        }

        return $out;
    }

    /**
     * Separate a part into the person's name and the catalog's note on their share of the work.
     */
    protected function describePart(string $part, int $compoundId): array
    {
        $notes = [];
        $name = preg_replace_callback('/\s*\(([^()]*)\)/u', function ($m) use (&$notes) {
            $notes[] = trim($m[1]);
            return '';
        }, $part);

        // «… در 15 شعبان 1117» or «… در 1074ق»: the copying date of this person's share
        if (preg_match('/\s+(در\s+.*\d.*)$/u', $name, $m)) {
            $notes[] = trim($m[1]);
            $name = substr($name, 0, -strlen($m[0]));
        }

        $name = preg_replace(['/^[\s،,.]+|[\s،,.]+$/u', '/\s+/u'], ['', ' '], $name);
        $candidates = array_values(array_diff($this->matchPeople($name), [$compoundId]));

        return [
            'name' => $name,
            'note' => $notes ? implode('، ', array_filter($notes)) : null,
            'person_id' => count($candidates) === 1 ? $candidates[0] : null,
            'candidates' => count($candidates) > 1 ? $candidates : [],
        ];
    }

    protected function buildNameIndex(): void
    {
        foreach (Person::query()->select(['id', 'name', 'aliases'])->cursor() as $p) {
            foreach (array_merge([$p->name], (array) ($p->aliases ?? [])) as $n) {
                $key = TextNormalizer::normalize($n);
                if ($key === '') {
                    continue;
                }
                $this->nameIndex[$key][$p->id] = $p->id;
                $this->compactIndex[str_replace(' ', '', $key)][$p->id] = $p->id;
            }
        }
    }

    /**
     * People whose name or alias equals the given name (normalised; spacing ignored as a fallback).
     */
    protected function matchPeople(string $name): array
    {
        $key = TextNormalizer::normalize($name);
        if ($key === '') {
            return [];
        }

        $ids = $this->nameIndex[$key] ?? $this->compactIndex[str_replace(' ', '', $key)] ?? [];

        return array_values($ids);
    }

    // ------------------------------------------------------------------ apply

    protected function apply(string $path): int
    {
        if (! is_file($path)) {
            $this->error("Decisions file not found: {$path}");
            return self::FAILURE;
        }

        $map = json_decode(file_get_contents($path), true);
        $dry = (bool) $this->option('dry-run');
        $only = $this->onlyIds();
        $stats = ['merged' => 0, 'applied' => 0, 'edited' => 0, 'people_created' => 0, 'names_only' => 0, 'manuscripts' => 0, 'works' => 0, 'skipped' => 0];

        // Duplicates first, so parts linked to a merged record land on the record that is kept
        foreach ($map['merges'] ?? [] as $merge) {
            if ($only && ! array_intersect([(int) $merge['source_id'], (int) $merge['target_id']], $only)) {
                continue;
            }
            $source = Person::find($merge['source_id']);
            $target = Person::find($merge['target_id']);
            if (! $source || ! $target || ! $this->sameName($source->name, $merge['source_name']) || ! $this->sameName($target->name, $merge['target_name'])) {
                $this->warn("merge #{$merge['source_id']} → #{$merge['target_id']}: record missing or renamed; skipped.");
                $stats['skipped']++;
                continue;
            }
            $this->line("merge #{$source->id} «{$source->name}» → #{$target->id} «{$target->name}»");
            if (! $dry) {
                app(PersonMerger::class)->merge($source, $target, null, null, ['reason' => 'fahares:split-compound-people']);
            }
            $stats['merged']++;
        }

        foreach ($map['entries'] ?? [] as $entry) {
            if (! in_array($entry['decision'], ['split', 'others', 'edit'], true)) {
                continue;
            }
            if ($only && ! in_array((int) $entry['person_id'], $only, true)) {
                continue;
            }

            $person = Person::find($entry['person_id']);
            if (! $person) {
                $stats['skipped']++;
                continue;
            }
            if (! $this->sameName($person->name, $entry['name'])) {
                $this->warn("Person #{$person->id} is «{$person->name}», expected «{$entry['name']}»; skipped.");
                $stats['skipped']++;
                continue;
            }

            $manuscriptIds = DB::table('manuscript_scribe')->where('person_id', $person->id)->pluck('manuscript_id')->all();
            $workIds = DB::table('work_author')->where('person_id', $person->id)->pluck('work_id')->all();

            if ($entry['decision'] === 'edit') {
                $this->line(sprintf('#%d «%s» edit: %s', $person->id, $person->name,
                    json_encode(($entry['person_set'] ?? []) + ($entry['manuscript_set'] ?? []), JSON_UNESCAPED_UNICODE)));
                if (! $dry) {
                    DB::transaction(fn() => $this->applyEdit($person, $entry, $manuscriptIds, $workIds));
                }
                $stats['edited']++;
                continue;
            }

            $this->line(sprintf('#%d «%s» → %s%s (%d manuscripts, %d works)',
                $person->id, $person->name,
                implode(' | ', array_map(fn($p) => $p['name'] . (! empty($p['text']) ? ' [name only]' : ($p['person_id'] ? " #{$p['person_id']}" : ' [new]')) . ($p['note'] ? " ({$p['note']})" : ''), $entry['parts'])),
                $entry['has_others'] ? ' + و دیگران' : '',
                count($manuscriptIds), count($workIds)));

            if (! $dry) {
                $created = DB::transaction(fn() => $this->applyEntry($person, $entry, $manuscriptIds, $workIds));
                $stats['people_created'] += $created;
            } else {
                $stats['people_created'] += count(array_filter($entry['parts'], fn($p) => empty($p['text']) && ! $p['person_id']));
            }

            $stats['names_only'] += count(array_filter($entry['parts'], fn($p) => ! empty($p['text'])));
            $stats['applied']++;
            $stats['manuscripts'] += count($manuscriptIds);
            $stats['works'] += count($workIds);
        }

        $this->info(($dry ? '[dry run] ' : '') . sprintf(
            'Merged %d duplicates; split %d records (%d new people, %d names without a record) relinking %d manuscripts and %d works; edited %d records; %d already applied or changed.',
            $stats['merged'], $stats['applied'], $stats['people_created'], $stats['names_only'], $stats['manuscripts'], $stats['works'], $stats['edited'], $stats['skipped']
        ));

        return self::SUCCESS;
    }

    /**
     * The person whose id the decision names, following a merge redirect if the record was merged since.
     */
    protected function resolvePerson(?int $id): ?Person
    {
        if (! $id) {
            return null;
        }

        return Person::find($id) ?? Person::find(EntityRedirect::resolveTargetId('people', $id));
    }

    protected function applyEdit(Person $person, array $entry, array $manuscriptIds, array $workIds): void
    {
        $set = $entry['person_set'] ?? [];
        if (isset($set['name'])) {
            $set['normalized_name'] = mb_substr(TextNormalizer::normalize($set['name']), 0, 600);
        }
        if ($set) {
            $oldName = $person->name;
            $person->update($set);
            $person->searchable();
            // Records that printed only this person's (now cleaned) name show the new one
            if (isset($set['name'])) {
                Manuscript::whereIn('id', $manuscriptIds)->get(['id', 'scribe_name'])
                    ->filter(fn($m) => $this->sameName((string) $m->scribe_name, $oldName))
                    ->each(fn($m) => DB::table('manuscripts')->where('id', $m->id)->update(['scribe_name' => $set['name']]));
                Work::whereIn('id', $workIds)->get(['id', 'author_name'])
                    ->filter(fn($w) => $this->sameName((string) $w->author_name, $oldName))
                    ->each(fn($w) => DB::table('works')->where('id', $w->id)->update(['author_name' => $set['name']]));
                Manuscript::whereIn('id', $manuscriptIds)->searchable();
                Work::whereIn('id', $workIds)->searchable();
            }
        }
        $this->applyManuscriptSet($entry, $manuscriptIds);
    }

    protected function applyManuscriptSet(array $entry, array $manuscriptIds): void
    {
        $set = array_intersect_key($entry['manuscript_set'] ?? [], array_flip(['commissioned_by', 'is_autograph']));
        if ($set && $manuscriptIds) {
            Manuscript::whereIn('id', $manuscriptIds)->update($set);
        }
    }

    /**
     * @return int number of people created
     */
    protected function applyEntry(Person $compound, array $entry, array $manuscriptIds, array $workIds): int
    {
        $created = 0;
        $parts = [];
        foreach ($entry['parts'] as $part) {
            if (! empty($part['text'])) {
                $parts[] = ['person' => null, 'name' => $part['name'], 'note' => $part['note'] ?? null];
                continue;
            }
            $person = $this->resolvePerson($part['person_id'] ?? null);
            if (! $person) {
                $before = count($this->createdPeople);
                $person = $this->createPerson($part['name'], $compound);
                $created += count($this->createdPeople) - $before;
            }
            if (! empty($part['set'])) {
                $person->update(array_intersect_key($part['set'], array_flip(['bio_notes', 'is_translator'])));
            }
            $parts[] = ['person' => $person, 'name' => null, 'note' => $part['note'] ?? null];
        }
        $linked = array_values(array_filter($parts, fn($p) => $p['person']));
        $primary = $linked[0]['person'] ?? null;

        foreach ($manuscriptIds as $manuscriptId) {
            $this->relink('manuscript_scribe', 'manuscript_id', $manuscriptId, $compound->id, $parts);
            Manuscript::whereKey($manuscriptId)->update([
                'scribe_id' => $primary?->id,
                'has_other_scribes' => $entry['has_others'],
            ]);
        }

        foreach ($workIds as $workId) {
            $this->relink('work_author', 'work_id', $workId, $compound->id, $parts);
            Work::whereKey($workId)->update([
                'author_id' => $primary?->id,
                'has_other_authors' => $entry['has_others'],
            ]);
        }
        Manuscript::normalizeContributorPositions($manuscriptIds);
        Work::normalizeContributorPositions($workIds);
        $this->applyManuscriptSet($entry, $manuscriptIds);

        // With nobody left to point to, the old page simply goes
        if ($primary) {
            EntityRedirect::recordRedirect('people', $compound->id, $primary->id);
        }
        $compound->delete();

        foreach ($linked as ['person' => $p]) {
            $p->recountContributions();
            $p->searchable();
        }
        Manuscript::whereIn('id', $manuscriptIds)->searchable();
        Work::whereIn('id', $workIds)->searchable();

        return $created;
    }

    /**
     * Replace the compound person in one record's rows with the individual people and names,
     * keeping the compound's position and any other rows already on the record.
     */
    protected function relink(string $table, string $key, int $recordId, int $compoundId, array $parts): void
    {
        $rows = DB::table($table)->where($key, $recordId)->orderBy('position')->orderBy('id')->get();
        $ordered = [];
        $linked = [];
        foreach ($rows as $row) {
            if ((int) $row->person_id !== $compoundId) {
                $ordered[] = ['person_id' => $row->person_id, 'name' => $row->name, 'note' => $row->note];
                $linked[(int) $row->person_id] = true;
                continue;
            }
            foreach ($parts as ['person' => $p, 'name' => $name, 'note' => $note]) {
                if ($p && isset($linked[$p->id])) {
                    continue;
                }
                $ordered[] = ['person_id' => $p?->id, 'name' => $p ? null : $name, 'note' => $note];
                if ($p) {
                    $linked[$p->id] = true;
                }
            }
        }

        DB::table($table)->where($key, $recordId)->delete();
        foreach ($ordered as $position => $row) {
            DB::table($table)->insert([$key => $recordId, 'position' => $position] + $row);
        }
    }

    protected function createPerson(string $name, Person $compound): Person
    {
        $key = TextNormalizer::normalize($name);
        if (isset($this->createdPeople[$key]) && ($existing = Person::find($this->createdPeople[$key]))) {
            return $existing;
        }

        $person = Person::create([
            'name' => $name,
            'normalized_name' => mb_substr(TextNormalizer::normalize($name), 0, 600),
            'slug' => 'person-' . Str::random(12),
            'is_author' => false,
            'is_scribe' => false,
        ]);
        $person->update(['slug' => (Str::slug($name, '-', null) ?: 'person') . '-' . $person->id]);
        $this->createdPeople[$key] = $person->id;

        return $person;
    }
}
