<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            // Pixel size of the logo file, so a small logo is never drawn larger than it can be shown sharply
            $table->unsignedSmallInteger('logo_width')->nullable()->after('logo_path');
            $table->unsignedSmallInteger('logo_height')->nullable()->after('logo_width');
        });

        $disk = Storage::disk('public');
        foreach (DB::table('libraries')->whereNotNull('logo_path')->get(['id', 'logo_path']) as $row) {
            $size = $disk->exists($row->logo_path) ? @getimagesize($disk->path($row->logo_path)) : false;
            if ($size) {
                DB::table('libraries')->where('id', $row->id)->update(['logo_width' => $size[0], 'logo_height' => $size[1]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->dropColumn(['logo_width', 'logo_height']);
        });
    }
};
