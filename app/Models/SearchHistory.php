<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'query',
        'ip_address',
        'search_count',
        'last_searched_at',
    ];

    protected $casts = [
        'last_searched_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get recent searches for a user or by IP
     */
    public static function getRecentSearches($userId = null, $ipAddress = null, $limit = 10)
    {
        $query = static::query();

        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($ipAddress) {
            $query->where('ip_address', $ipAddress);
        }

        return $query->orderBy('last_searched_at', 'desc')->limit($limit)->get();
    }

    /**
     * Record a search query
     */
    public static function recordSearch($query, $userId = null, $ipAddress = null)
    {
        if (empty(trim((string) $query))) {
            return;
        }

        $searchHistory = static::where('query', $query);

        if ($userId) {
            $searchHistory->where('user_id', $userId);
        } elseif ($ipAddress) {
            $searchHistory->where('ip_address', $ipAddress);
        } else {
            $searchHistory->whereNull('user_id')->whereNull('ip_address');
        }

        $existing = $searchHistory->first();

        if ($existing) {
            $existing->increment('search_count');
            $existing->update(['last_searched_at' => now()]);
        } else {
            static::create([
                'user_id' => $userId,
                'query' => $query,
                'ip_address' => $ipAddress,
                'last_searched_at' => now(),
            ]);
        }
    }
}
