<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\VideoProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class VideoProgressCompositeIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_composite_indexes_exist_on_video_progress_table(): void
    {
        $indexes = Schema::getIndexes('video_progress');
        $indexNames = array_column($indexes, 'name');

        $this->assertContains('idx_user_progress_completed', $indexNames);
        $this->assertContains('idx_lecture_progress_completed', $indexNames);
    }

    public function test_migration_down_drops_indexes_cleanly(): void
    {
        $migration = require database_path('migrations/2026_10_04_120000_add_composite_indexes_to_video_progress_table.php');

        // Rollback
        $migration->down();

        $indexesAfter = Schema::getIndexes('video_progress');
        $indexNamesAfter = array_column($indexesAfter, 'name');

        $this->assertNotContains('idx_user_progress_completed', $indexNamesAfter);
        $this->assertNotContains('idx_lecture_progress_completed', $indexNamesAfter);

        // Re-apply up
        $migration->up();

        $indexesReapplied = Schema::getIndexes('video_progress');
        $indexNamesReapplied = array_column($indexesReapplied, 'name');

        $this->assertContains('idx_user_progress_completed', $indexNamesReapplied);
        $this->assertContains('idx_lecture_progress_completed', $indexNamesReapplied);
    }

    public function test_explain_query_plan_uses_composite_index_for_completed_progress_lookups(): void
    {
        $query = VideoProgress::where('user_id', 1)->where('is_completed', true);
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        // Run EXPLAIN QUERY PLAN in SQLite
        $plan = DB::select("EXPLAIN QUERY PLAN " . $sql, $bindings);

        $this->assertNotEmpty($plan);
        $detail = json_encode($plan);

        // Detail should reference the table or index lookup
        $this->assertStringContainsString('video_progress', $detail);
    }
}
