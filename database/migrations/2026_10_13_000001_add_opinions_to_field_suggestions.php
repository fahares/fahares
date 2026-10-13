<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            // The name the submitter agreed to show beside an approved correction; null keeps it anonymous
            $table->string('credit_name', 150)->nullable()->after('guest_email');
            // An approved field suggestion is one reading of the field: adopted (the value on the record),
            // alternative (kept with its evidence), superseded (shown to be wrong) or edit (typo fix, not a reading)
            $table->string('standing', 20)->nullable()->after('status')->index();
            // The clean value the reviewer applied or kept; suggested_value may carry the submitter's explanation
            $table->text('applied_value')->nullable()->after('suggested_value');
            // Shown to the submitter and, for alternative and superseded readings, on the record page
            $table->text('public_note')->nullable()->after('admin_notes');
            $table->foreignId('superseded_by')->nullable()->after('standing')->constrained('field_suggestions')->nullOnDelete();
        });

        // Approvals made before readings existed: applied field corrections become adopted readings or edits
        $approved = DB::table('field_suggestions')
            ->where('kind', 'field')->where('status', 'approved')
            ->orderBy('reviewed_at')->orderBy('id')
            ->get();

        foreach ($approved as $s) {
            $resolution = json_decode($s->resolution ?? 'null', true) ?? [];
            $annotation = DB::table('scholarly_annotations')->where('suggestion_id', $s->id)->latest('id')->first();
            // Approvals older than the resolution column wrote the column and left only an annotation
            if (empty($resolution['applied']) && ! $annotation) {
                continue;
            }
            $log = DB::table('audit_logs')->where('action', 'verify')->where('new_values', 'like', '%"suggestion_id":' . $s->id . '}%')->latest('id')->first();
            $logged = json_decode($log->new_values ?? 'null', true) ?? [];

            DB::table('field_suggestions')->where('id', $s->id)->update([
                'standing' => in_array($annotation?->revision_category, ['ocr_fix', 'parser_fix'], true) ? 'edit' : 'adopted',
                'applied_value' => $annotation?->corrected_value ?? (isset($logged[$s->field_name]) && is_scalar($logged[$s->field_name]) ? (string) $logged[$s->field_name] : $s->suggested_value),
            ]);
        }

        // Only the latest adopted reading of a field stays adopted
        $groups = DB::table('field_suggestions')->where('standing', 'adopted')
            ->select('suggestable_type', 'suggestable_id', 'field_name')
            ->groupBy('suggestable_type', 'suggestable_id', 'field_name')
            ->havingRaw('count(*) > 1')->get();
        foreach ($groups as $g) {
            $ids = DB::table('field_suggestions')->where('standing', 'adopted')
                ->where('suggestable_type', $g->suggestable_type)->where('suggestable_id', $g->suggestable_id)->where('field_name', $g->field_name)
                ->orderBy('reviewed_at')->orderBy('id')->pluck('id');
            $latest = $ids->pop();
            DB::table('field_suggestions')->whereIn('id', $ids)->update(['standing' => 'superseded', 'superseded_by' => $latest]);
        }
    }

    public function down(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by');
            $table->dropColumn(['credit_name', 'standing', 'applied_value', 'public_note']);
        });
    }
};
