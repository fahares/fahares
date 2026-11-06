<?php

namespace App\Console\Commands;

use App\Models\SourceCitationForm;
use App\Sources\CitationForms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the patterns of config/bibliography.php into source_citation_forms (step 14.57): one form of no citing source
 * per source of the registry, its pattern as the form's regex and its reading rules as the form's options. What shows
 * a citation (url, viewer, text) stays in config. Afterwards every citation reads as before, which --check proves on
 * the works' bibliographies; --revert removes the forms it made. Safe to run again: a source that has a form is left.
 */
class ImportCitationFormsCommand extends Command
{
    public const MARK = 'config/bibliography.php (گام ۱۴.۵۷)';

    protected $signature = 'sources:import-citation-forms
        {--dry-run : Report what would be made without saving}
        {--check : Read every bibliography string with the config and with the forms, and report where they differ}
        {--revert : Remove the forms this command made}';

    protected $description = 'Bring the citation patterns of config/bibliography.php into source_citation_forms';

    public function handle(): int
    {
        if ($this->option('revert')) {
            $removed = SourceCitationForm::where('notes', self::MARK)->delete();
            CitationForms::forget();
            $this->info("Removed {$removed} forms.");

            return self::SUCCESS;
        }
        if ($this->option('check')) {
            return $this->check();
        }

        $codes = DB::table('sources')->pluck('id', 'code');
        $made = 0;
        foreach (config('bibliography.sources', []) as $code => $src) {
            if (! isset($codes[$code])) {
                $this->warn("{$code}: not in the registry (sources:backfill --only=registry makes it); left in config");

                continue;
            }
            if (SourceCitationForm::where('cited_source_id', $codes[$code])->whereNull('citing_source_id')->where('context', 'bibliography')->exists()) {
                $this->line("{$code}: has a form");

                continue;
            }
            $options = array_intersect_key($src, array_flip(CitationForms::READING));
            $this->line("{$code}: " . $src['pattern'] . ($options ? ' ' . json_encode($options, JSON_UNESCAPED_UNICODE) : ''));
            if (! $this->option('dry-run')) {
                SourceCitationForm::create(['cited_source_id' => $codes[$code], 'citing_source_id' => null, 'context' => 'bibliography',
                    'regex' => $src['pattern'], 'options' => $options ?: null, 'notes' => self::MARK]);
            }
            $made++;
        }
        $this->info(($this->option('dry-run') ? 'Would make' : 'Made') . " {$made} forms.");

        return self::SUCCESS;
    }

    /** Every bibliography string read with the config alone and with the forms: the citations must be the same. */
    private function check(): int
    {
        $read = function () {
            $out = [];
            DB::table('source_entries')->where('legacy_type', 'work_bibliography')->orderBy('id')->select('id', 'citation_text')
                ->chunk(5000, function ($rows) use (&$out) {
                    foreach ($rows as $r) {
                        $out[$r->id] = md5(json_encode(\App\Support\BibliographyLinks::segments((string) $r->citation_text)));
                    }
                });

            return $out;
        };
        // the sources themselves first: each as config has it
        $config = config('bibliography.sources', []);
        foreach (CitationForms::sources() as $code => $src) {
            if (isset($config[$code]) && $src != $config[$code]) {
                $this->warn("{$code}: read otherwise than config: " . json_encode(array_diff_key($src, $config[$code]) + array_diff_key($config[$code], $src), JSON_UNESCAPED_UNICODE));
            }
        }
        foreach (array_diff_key($config, CitationForms::sources()) as $code => $src) {
            $this->warn("{$code}: in config and not read");
        }
        $withForms = $read();
        DB::beginTransaction();
        try {
            DB::table('source_citation_forms')->delete();
            CitationForms::forget();
            $withConfig = $read();
        } finally {
            DB::rollBack();
            CitationForms::forget();
        }
        $differ = array_keys(array_diff_assoc($withForms, $withConfig));
        $this->info(sprintf('%d strings read; %d differ.', count($withForms), count($differ)));
        foreach (array_slice($differ, 0, 20) as $id) {
            $this->line('  #' . $id . ' ' . DB::table('source_entries')->where('id', $id)->value('citation_text'));
        }

        return $differ ? self::FAILURE : self::SUCCESS;
    }
}
