<?php

namespace App\Suggestions;

use App\Models\AuditLog;
use App\Models\CatalogVolume;
use App\Models\Cataloger;
use App\Models\Source;
use App\Sources\SourceMerger;
use App\Sources\VolumeEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The people of a source's volumes as a suggestion changes them (step 14.58): «the cataloger of this catalogue, or of
 * one of its volumes, is someone else» replaces the volume's people in that role, «it had another one too» adds one.
 * The change runs through VolumeEditor, as the admin's volume form does (step 14.50), so the copies' catalogers and the
 * counts follow; it is logged once for the source as «volume_people», with each volume's people before and after,
 * which the reviewer's reopen undoes.
 */
class VolumeCatalogers
{
    /** The roles a person has in a volume, as the admin's volume form names them. */
    public const ROLES = ['cataloger' => 'فهرست‌نگار', 'supervisor' => 'زیر نظر', 'translator' => 'مترجم'];

    public const ACTION = 'volume_people';

    /**
     * The volumes of the source with their people, for the suggestion form and the reviewer: a source volume and its
     * catalogue volume share the id. Volumes without a catalogue volume take no people and are left out.
     *
     * @return list<array{id: int, number: ?int, label: string, people: string}>
     */
    public static function volumes(Source $source): array
    {
        $volumes = $source->volumes()->with('catalogers:id,name')->orderBy('volume_number')->orderBy('id')->get();
        $catalogued = CatalogVolume::whereIn('id', $volumes->pluck('id'))->pluck('id')->all();

        return $volumes->filter(fn ($v) => in_array($v->id, $catalogued, true))->map(fn ($v) => [
            'id' => $v->id,
            'number' => $v->volume_number,
            'label' => $v->volume_number ? 'جلد ' . \App\Support\Fa::digits((string) $v->volume_number) : ($v->title ?: 'جلد #' . $v->id),
            'people' => $v->catalogers->map(fn ($c) => $c->name . (($c->pivot->role ?? 'cataloger') !== 'cataloger' ? ' (' . (self::ROLES[$c->pivot->role] ?? $c->pivot->role) . ')' : ''))->implode('، '),
        ])->values()->all();
    }

    /** Whether the volume is one of the source's own catalogue volumes. */
    public static function hasVolume(Source $source, int $volumeId): bool
    {
        return in_array($volumeId, array_column(self::volumes($source), 'id'), true);
    }

    /** A cataloger made from a name the reviewer wrote, as the import makes one. */
    public static function createCataloger(string $name, int $suggestionId): Cataloger
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 3) {
            throw new InvalidArgumentException('نام فهرست‌نگار تازه را بنویسید.');
        }
        $slug = Str::slug($name, '-', null) ?: 'cataloger';
        while (Cataloger::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::lower(Str::random(4));
        }

        return Cataloger::create(['name' => $name, 'slug' => $slug, 'metadata' => ['sources' => ["پیشنهاد #{$suggestionId}"]]]);
    }

    /**
     * Puts the cataloger into the volumes in the role: in place of the volume's people in that role ($replace), or
     * beside them. Logs it and returns the log's old and new people per volume.
     *
     * @param  list<int>  $volumeIds
     * @return array{old: array<int, list<array>>, new: array<int, list<array>>, log: AuditLog}
     */
    public function apply(Source $source, array $volumeIds, Cataloger $cataloger, string $role, bool $replace, int $suggestionId, array $extra = []): array
    {
        if (! array_key_exists($role, self::ROLES)) {
            throw new InvalidArgumentException('نقش را انتخاب کنید.');
        }
        $volumeIds = array_values(array_unique(array_map('intval', $volumeIds)));
        if (! $volumeIds || array_diff($volumeIds, array_column(self::volumes($source), 'id'))) {
            throw new InvalidArgumentException('دست‌کم یک جلد از همین منبع را انتخاب کنید.');
        }

        $old = [];
        $new = [];
        foreach ($volumeIds as $id) {
            $old[$id] = self::people($id);
            $people = collect($old[$id])
                ->reject(fn ($p) => $p['cataloger_id'] === $cataloger->id || ($replace && $p['role'] === $role))
                ->push(['cataloger_id' => $cataloger->id, 'role' => $role])->values()->all();
            $new[$id] = $people;
        }
        if ($old == $new) {
            throw new InvalidArgumentException('این شخص با همین نقش در جلدهای انتخاب‌شده ثبت شده است؛ تغییری لازم نیست.');
        }

        $effects = $this->write($new);
        $log = AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Source::class,
            'auditable_id' => $source->id,
            'action' => self::ACTION,
            'old_values' => ['volumes' => $old],
            'new_values' => ['volumes' => $new, 'suggestion_id' => $suggestionId, 'effect' => $effects] + $extra,
            'ip_address' => request()->ip(),
        ]);
        SourceMerger::forget([$source->id]);

        return ['old' => $old, 'new' => $new, 'log' => $log];
    }

    /** Takes a logged change back: each volume gets its people before it, unless it was changed again since. */
    public function undo(AuditLog $log): void
    {
        $volumes = (array) ($log->old_values['volumes'] ?? []);
        foreach ($volumes as $id => $_) {
            if (self::people((int) $id) != array_values((array) ($log->new_values['volumes'][$id] ?? []))) {
                throw new InvalidArgumentException('فهرست‌نگاران جلد #' . $id . ' پس از این تأیید دوباره تغییر کرده‌اند؛ آن را در صفحه‌ی جلد دستی بازگردانید.');
            }
        }
        $this->write(array_map(fn ($people) => array_values((array) $people), $volumes));
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'action' => 'rollback_' . self::ACTION,
            'old_values' => ['volumes' => $log->new_values['volumes'] ?? [], 'rolled_back_log_id' => $log->id],
            'new_values' => ['volumes' => $volumes],
            'ip_address' => request()->ip(),
        ]);
        SourceMerger::forget([(int) $log->auditable_id]);
        // the suggestion goes back to the queue, as every undone approval does
        if ($suggestionId = $log->new_values['suggestion_id'] ?? null) {
            \App\Models\FieldSuggestion::whereKey($suggestionId)->update(['status' => 'pending', 'resolution' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
        }
    }

    /** @return list<array{cataloger_id: int, role: string}> */
    public static function people(int $volumeId): array
    {
        return DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $volumeId)->orderBy('id')->get(['cataloger_id', 'role'])
            ->map(fn ($r) => ['cataloger_id' => (int) $r->cataloger_id, 'role' => $r->role])->all();
    }

    /** @param  array<int, list<array>>  $people  volume id => its people */
    private function write(array $people): array
    {
        $editor = app(VolumeEditor::class);
        $effects = [];
        foreach ($people as $id => $rows) {
            $volume = CatalogVolume::findOrFail((int) $id);
            $effect = $editor->run($volume, function () use ($volume, $rows) {
                DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $volume->id)->delete();
                foreach ($rows as $row) {
                    DB::table('catalog_volume_cataloger')->insert(['catalog_volume_id' => $volume->id, 'cataloger_id' => (int) $row['cataloger_id'],
                        'role' => $row['role'], 'created_at' => now(), 'updated_at' => now()]);
                }
            });
            $effects[$id] = $effect['cataloger'];
        }

        return $effects;
    }
}
