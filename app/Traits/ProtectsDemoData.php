<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

trait ProtectsDemoData
{
    /**
     * Demo data cutoff date - entries before this date are protected
     */
    protected static $demoDataCutoffDate = '2025-11-12 00:00:00';

    /**
     * Boot the trait
     */
    protected static function bootProtectsDemoData()
    {
        // Prevent deletion of demo data
        static::deleting(static function ($model) {
            // Allow Cart, Order, and Wishlist operations even in demo mode
            if (static::shouldAllowOperationInDemoMode($model)) {
                return;
            }

            if (static::isDemoModeEnabled() && static::isModelDemoData($model)) {
                Log::warning('Attempt to delete demo data prevented', [
                    'model' => $model::class,
                    'id' => $model->id,
                    'created_at' => $model->created_at,
                ]);
                return false;
            }
        });

        // Prevent updating of demo data
        static::updating(static function ($model) {
            // Allow Cart, Order, and Wishlist operations even in demo mode
            if (static::shouldAllowOperationInDemoMode($model)) {
                return;
            }

            if (static::isDemoModeEnabled() && static::isModelDemoData($model)) {
                Log::warning('Attempt to update demo data prevented', [
                    'model' => $model::class,
                    'id' => $model->id,
                    'created_at' => $model->created_at,
                ]);
                return false;
            }
        });
    }

    /**
     * Check if operation should be allowed in demo mode
     * Cart, Order, and Wishlist operations should always be allowed
     */
    protected static function shouldAllowOperationInDemoMode($model)
    {
        $modelClass = $model::class;

        // Allow Cart, Order, Wishlist, User wallet updates, etc. even in demo mode
        return in_array($modelClass, [
            \App\Models\Cart::class,
            \App\Models\Order::class,
            \App\Models\OrderCourse::class,
            \App\Models\Transaction::class,
            \App\Models\Wishlist::class,
            \App\Models\User::class,
            \App\Models\WalletHistory::class,
        ]);
    }

    /**
     * Check if demo mode is enabled
     */
    protected static function isDemoModeEnabled()
    {
        return config('app.demo_mode') == 1 || env('DEMO_MODE') == 1;
    }

    /**
     * Check if the model is demo data (created before cutoff date)
     */
    protected static function isModelDemoData($model)
    {
        if (!$model->created_at) {
            return false;
        }

        $cutoffDate = Carbon::parse(static::$demoDataCutoffDate);
        return $model->created_at->lt($cutoffDate);
    }

    /**
     * Check if current model instance is demo data
     */
    public function isDemoData()
    {
        if (!$this->created_at) {
            return false;
        }

        $cutoffDate = Carbon::parse(static::$demoDataCutoffDate);
        return $this->created_at->lt($cutoffDate);
    }

    /**
     * Scope to exclude demo data from queries
     */
    public function scopeExcludeDemoData($query)
    {
        if (static::isDemoModeEnabled()) {
            $cutoffDate = Carbon::parse(static::$demoDataCutoffDate);
            return $query->where('created_at', '>=', $cutoffDate);
        }
        return $query;
    }

    /**
     * Scope to get only demo data
     */
    public function scopeOnlyDemoData($query)
    {
        if (static::isDemoModeEnabled()) {
            $cutoffDate = Carbon::parse(static::$demoDataCutoffDate);
            return $query->where('created_at', '<', $cutoffDate);
        }
        return $query->whereRaw('1 = 0'); // Return empty result if demo mode not enabled
    }
}
