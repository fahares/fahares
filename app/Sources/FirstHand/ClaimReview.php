<?php

namespace App\Sources\FirstHand;

use App\Models\Claim;
use App\Models\FieldSuggestion;
use App\Models\User;
use App\Suggestions\SuggestionRegistry;

/**
 * The review of claims (backlog item 147, step 14.255): a claim the reviewer finds right in the printed catalogue
 * becomes a field suggestion of the system, approved on the suggestion's own page, where the field's whole path runs
 * (a date's Hijri year, a scribe's person, the opinions of the record); a claim found wrong is rejected here. The
 * suggestion's decision settles its claim (sync, from FieldSuggestion's saved hook).
 */
class ClaimReview
{
    /** The suggestion field of each claim field, where their names differ. */
    public const FIELDS = ['copy_date' => 'copy_date_raw'];

    public const ORIGIN = 'first_hand';

    public static function fieldName(Claim $claim): string
    {
        return self::FIELDS[$claim->field] ?? $claim->field;
    }

    /** The claim's suggestion, made once: the waiting one is reused. */
    public function suggestion(Claim $claim): FieldSuggestion
    {
        if ($claim->suggestion_id && ($open = FieldSuggestion::find($claim->suggestion_id))) {
            return $open;
        }
        $record = $claim->subject;
        $field = SuggestionRegistry::fieldOf($record, self::fieldName($claim));
        if (! $field) {
            throw new \InvalidArgumentException("No suggestion field for {$claim->field}");
        }
        $entry = $claim->sourceEntry;
        $suggestion = FieldSuggestion::create([
            'suggestable_type' => $record::class,
            'suggestable_id' => $record->id,
            'kind' => 'field',
            'field_name' => $field['name'] ?? self::fieldName($claim),
            'current_value' => SuggestionRegistry::currentValue($record, $field),
            'suggested_value' => $claim->value_text,
            // where the reviewer reads it in print: the entry's place, and the catalogue's own words
            'rationale_citation' => trim(($entry ? $entry->cardLabel($record) : '') . ($claim->raw_text ? '؛ متن فهرست: «' . $claim->raw_text . '»' : '')),
            'user_id' => null,
            'guest_name' => FieldSuggestion::MACHINE_ORIGINS[self::ORIGIN],
            'status' => 'pending',
            'resolution' => ['applied' => false, 'origin' => self::ORIGIN, 'claim_id' => $claim->id],
        ]);
        $claim->update(['suggestion_id' => $suggestion->id]);

        return $suggestion;
    }

    public function reject(Claim $claim, User $user): void
    {
        $claim->update(['status' => Claim::STATUS_REJECTED, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        if ($claim->suggestion_id) {
            FieldSuggestion::whereKey($claim->suggestion_id)->where('status', 'pending')
                ->update(['status' => 'rejected', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        }
    }

    public function reopen(Claim $claim): void
    {
        $claim->update(['status' => Claim::STATUS_CANDIDATE, 'reviewed_by' => null, 'reviewed_at' => null]);
    }

    /** A suggestion made from a claim was decided: the claim follows it (approved → accepted, rejected → rejected). */
    public static function sync(FieldSuggestion $suggestion): void
    {
        $status = match ($suggestion->status) {
            'approved' => Claim::STATUS_ACCEPTED,
            'rejected' => Claim::STATUS_REJECTED,
            'pending' => Claim::STATUS_CANDIDATE,
            default => null,
        };
        if ($status === null) {
            return;
        }
        Claim::where('suggestion_id', $suggestion->id)->update([
            'status' => $status,
            'reviewed_by' => $status === Claim::STATUS_CANDIDATE ? null : $suggestion->reviewed_by,
            'reviewed_at' => $status === Claim::STATUS_CANDIDATE ? null : ($suggestion->reviewed_at ?? now()),
        ]);
    }
}
