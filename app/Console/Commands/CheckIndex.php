<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class CheckIndex extends Command {
    protected $signature = 'check:index';
    public function handle() {
        $indexes = DB::select('SHOW INDEXES FROM subscription_plan_prices');
        foreach ($indexes as $index) {
            $this->info($index->Key_name . ' -> ' . $index->Column_name);
        }
    }
}
