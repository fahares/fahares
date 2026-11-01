<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Suggestions\ContributorRelations;
use App\Suggestions\ProtectedColumns;
use App\Support\KnownRulers;
use App\Support\KnownSubjects;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Gives a work whose title names its subject («مناقب امیرالمؤمنین», «مقتل الحسین», «شرح حال شاه نعمت الله ولی») a
 * work_people row with the role subject (backlog item 35). The Prophet, Fatima and the twelve Imams are linked from
 * KnownSubjects; anyone else only when the decision file (the user's review of --candidates) names the person for
 * that phrase. The title's word («مقتل») is kept as label_raw, the row cites the work's Fankha entry and goes through
 * ContributorRelations::addRole; a work whose people a reviewer set is left alone, and a row that is there is not
 * written again. Without --apply it only counts.
 */
class BackfillWorkSubjectsCommand extends Command
{
    protected $signature = 'fahares:backfill-work-subjects
        {--decisions= : The reviewed phrases (database/data/work_subjects_20261101.json)}
        {--candidates= : Write the phrases not linked, with the person a name match suggests, to this JSON file for review}
        {--apply : Write; without it the command only counts}
        {--show=15 : How many examples to list per kind}';

    protected $description = 'Link the subject a work\'s title names (the Prophet, the Imams, and reviewed others) with the subject role';

    private array $counts = [];

    private array $examples = [];

    public function handle(ContributorRelations $relations): int
    {
        $apply = (bool) $this->option('apply');
        $this->counts = [];
        $this->examples = [];
        $path = $this->option('decisions') ?: database_path('data/work_subjects_20261101.json');
        $decided = [];
        foreach (File::exists($path) ? (json_decode(File::get($path), true)['phrases'] ?? []) : [] as $phrase => $name) {
            if ($name) {
                $decided[KnownRulers::compact($phrase)] = $name;
            }
        }
        $matcher = new PersonNameMatcher;
        $people = [];
        $person = function (string $name) use ($matcher, &$people): ?Person {
            if (! array_key_exists($name, $people)) {
                $ids = $matcher->ids(TextNormalizer::normalize($name));
                $people[$name] = count($ids) === 1 ? Person::find($ids[0]) : null;
                if (count($ids) !== 1) {
                    $this->warn((count($ids) ? 'Several records' : 'No record') . " for {$name}");
                }
            }

            return $people[$name];
        };
        $guard = ProtectedColumns::for(Work::class);
        $candidates = [];

        Work::query()->select(['id', 'primary_title'])->orderBy('id')->chunkById(5000, function ($works) use ($apply, $relations, $guard, $person, $decided, $matcher, &$candidates) {
            foreach ($works as $work) {
                $read = KnownSubjects::fromTitle($work->primary_title);
                if (! $read) {
                    continue;
                }
                [$word, $pieces] = $read;
                $this->count('works:titled');
                foreach ($pieces as $piece) {
                    $name = KnownSubjects::find($piece) ?? $decided[KnownRulers::compact($piece)] ?? null;
                    if (! $name) {
                        $this->count('pieces:not_linked');
                        $key = KnownRulers::compact($piece);
                        $candidates[$key] ??= ['phrase' => $piece, 'works' => [], 'suggested' => null];
                        $candidates[$key]['works'][] = "#{$work->id} {$work->primary_title}";
                        continue;
                    }
                    $subject = $person($name);
                    $label = "#{$work->id} «{$work->primary_title}» → {$name}";
                    if (! $subject) {
                        $this->count('pieces:no_record', $label);
                        continue;
                    }
                    if ($guard->protectsRows($work->id, 'people')) {
                        $this->count('pieces:protected', $label);
                        continue;
                    }
                    if ($work->workPeople()->where('role', 'subject')->where('person_id', $subject->id)->exists()) {
                        $this->count('pieces:exists');
                        continue;
                    }
                    $this->count(KnownSubjects::find($piece) ? 'linked:known' : 'linked:reviewed', $label);
                    if (! $apply) {
                        continue;
                    }
                    $entry = SourceEntry::where('citable_type', Work::class)->where('citable_id', $work->id)
                        ->where('origin', SourceEntry::ORIGIN_FANKHA)->orderByDesc('is_primary')->orderBy('id')->value('id');
                    try {
                        $relations->addRole($work, 'subject', $subject, null, null, null, ['source_entry_id' => $entry, 'label_raw' => mb_substr($word, 0, 60)]);
                    } catch (InvalidArgumentException $e) {
                        $this->count('pieces:refused', "{$label} ({$e->getMessage()})");
                    }
                }
            }
        });

        if ($file = $this->option('candidates')) {
            foreach ($candidates as &$c) {
                $ids = $matcher->ids(TextNormalizer::normalize($c['phrase']));
                $c['suggested'] = count($ids) === 1 ? Person::whereKey($ids[0])->value('name') : null;
            }
            unset($c);
            uasort($candidates, fn ($a, $b) => [count($b['works']), $a['phrase']] <=> [count($a['works']), $b['phrase']]);
            File::put($file, json_encode(array_values($candidates), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
            $this->line('Candidates written: ' . count($candidates) . ' phrases, ' . count(array_filter($candidates, fn ($c) => $c['suggested'])) . ' with a suggested person.');
        }

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        ksort($this->counts);
        foreach ($this->counts as $kind => $n) {
            $this->line(sprintf('  %-22s %6d', $kind, $n));
        }
        foreach ($this->examples as $kind => $lines) {
            $this->line("-- {$kind}");
            foreach ($lines as $line) {
                $this->line("   {$line}");
            }
        }

        return self::SUCCESS;
    }

    private function count(string $kind, ?string $example = null): void
    {
        $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;
        if ($example !== null && count($this->examples[$kind] ?? []) < (int) $this->option('show')) {
            $this->examples[$kind][] = $example;
        }
    }
}
