<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Step 14.133: a photograph linked to the original it names, when that original is on the site */
class LinkNamedMothersCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_photograph_is_linked_to_the_original_it_names_once()
    {
        $work = Work::create(['primary_title' => 'اثر عکسی', 'clean_title' => 'اثر عکسی', 'slug' => 'photo-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $other = Work::create(['primary_title' => 'اثر دیگر', 'clean_title' => 'اثر دیگر', 'slug' => 'other-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $minovi = Library::create(['name' => 'مینوی', 'city' => 'تهران', 'slug' => 'minovi-' . uniqid()]);
        $ehya = Library::create(['name' => 'مرکز احیاء', 'city' => 'قم', 'slug' => 'ehya-' . uniqid()]);
        $make = fn ($w, $lib, $shelf, $photo) => Manuscript::create(['work_id' => $w->id, 'sequence_number' => 1, 'volume_number' => 1,
            'page_start' => 1, 'library_id' => $lib->id, 'library' => $lib->name, 'city' => $lib->city, 'shelfmark' => $shelf,
            'is_facsimile' => $photo, 'raw_text' => $photo ? 'نسخه اصل: مینوی ش 144؛ بی‌کا' : 'خط: نسخ']);
        $mother = $make($work, $minovi, '144', false);
        $photo = $make($work, $ehya, '2662/1', true);
        $elsewhere = $make($other, $ehya, '2663', true);
        $rec = fn ($p, $m) => ['facsimile' => $p->id, 'facsimile_shelfmark' => $p->shelfmark, 'mother' => $m->id,
            'mother_shelfmark' => $m->shelfmark, 'note' => 'مینوی ش 144'];
        $file = tempnam(sys_get_temp_dir(), 'mothers');
        file_put_contents($file, json_encode(['records' => [$rec($photo, $mother), $rec($elsewhere, $mother)]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:link-named-mothers', ['--file' => $file])->expectsOutputToContain('Would link 1')->assertSuccessful();
        $this->assertSame(0, DB::table('manuscript_relations')->where('manuscript_id', $photo->id)->count());

        $this->artisan('fahares:link-named-mothers', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('not the same work')->expectsOutputToContain('Linked 1')->assertSuccessful();
        $this->artisan('fahares:link-named-mothers', ['--file' => $file, '--apply' => true])->expectsOutputToContain('Linked 0')->assertSuccessful();
        @unlink($file);

        $this->assertSame($mother->id, (int) DB::table('manuscript_relations')->where('manuscript_id', $photo->id)->where('type', 'facsimile_of')->value('related_manuscript_id'));
        $this->assertSame(1, DB::table('audit_logs')->where('auditable_id', $photo->id)->where('action', 'corpus_update')->count());
        $this->get(route('manuscripts.show', $mother))->assertOk()->assertSee('عکسی و میکروفیلم‌های این نسخه');
    }
}
