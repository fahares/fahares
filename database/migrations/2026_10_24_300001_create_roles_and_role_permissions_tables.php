<?php

use App\Auth\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin panel's access matrix (backlog item 65): the roles users.role refers to, and which permission
     * of App\Auth\Permissions each role holds. The admin role holds them all and has no rows here.
     * Seeded with the defaults agreed with the user on 2 Aban 1405.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('label');
            // Built-in roles cannot be removed once adding roles from the panel exists
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 30);
            $table->string('permission', 60);
            $table->unique(['role', 'permission']);
        });

        $roles = [
            ['admin', 'مدیر کل'],
            ['editor', 'داور (دبیر علمی)'],
            ['researcher', 'پژوهشگر ارشد'],
            ['user', 'کاربر عادی'],
        ];
        foreach ($roles as $i => [$key, $label]) {
            DB::table('roles')->insert(['key' => $key, 'label' => $label, 'is_system' => true, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (Permissions::defaults() as $role => $keys) {
            DB::table('role_permissions')->insert(array_map(fn ($key) => ['role' => $role, 'permission' => $key], $keys));
        }
        Permissions::flush();
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
