<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add slug column (nullable for now)
        Schema::table('helpdesk_groups', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        // Step 2: Populate existing records with slugs
        $groups = \App\Models\HelpdeskGroup::whereNull('slug')->orWhere('slug', '')->get();
        foreach ($groups as $group) {
            $group->slug = \Illuminate\Support\Str::slug($group->name);
            $group->save();
        }

        // Step 3: Make slug unique (after filling data)
        Schema::table('helpdesk_groups', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_groups', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
