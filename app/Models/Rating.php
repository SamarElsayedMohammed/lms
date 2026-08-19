<?php

namespace App\Models;

use App\Models\Course\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Rating extends Model
{
    protected $fillable = [
        'user_id',
        'rating',
        'review',
        'rateable_id',
        'rateable_type',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'rating' => 'integer',
    ];

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Restrict withAvg/withCount to approved reviews so course cards stay accurate.
     *
     * @return array<string, callable>
     */
    public static function approvedRelationConstraint(): array
    {
        return [
            'ratings' => static function (Builder $q): void {
                $q->where('status', 'approved');
            },
        ];
    }

    /**
     * Get the model (course/instructor/etc.) that this rating belongs to.
     */
    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who wrote the rating.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * SQL aggregates for approved reviews only — avoids loading every row.
     *
     * @return array{total_reviews: int, average_rating: float, rating_breakdown: array<string, int>, percentage_breakdown: array<string, float>}
     */
    public static function approvedStatistics(string $rateableType, ?int $rateableId = null): array
    {
        $query = static::query()
            ->where('rateable_type', $rateableType)
            ->where('status', 'approved');

        if ($rateableId !== null) {
            $query->where('rateable_id', $rateableId);
        }

        $row = $query
            ->selectRaw('
                COUNT(*) as total,
                AVG(rating) as average,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as s5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as s4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as s3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as s2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as s1
            ')
            ->first();

        $total = (int) ($row->total ?? 0);
        $breakdown = [
            '5_stars' => (int) ($row->s5 ?? 0),
            '4_stars' => (int) ($row->s4 ?? 0),
            '3_stars' => (int) ($row->s3 ?? 0),
            '2_stars' => (int) ($row->s2 ?? 0),
            '1_star' => (int) ($row->s1 ?? 0),
        ];

        $percent = static function (int $count) use ($total): float {
            return $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
        };

        return [
            'total_reviews' => $total,
            'average_rating' => $total > 0 ? round((float) $row->average, 2) : 0.0,
            'rating_breakdown' => $breakdown,
            'percentage_breakdown' => [
                '5_stars' => $percent($breakdown['5_stars']),
                '4_stars' => $percent($breakdown['4_stars']),
                '3_stars' => $percent($breakdown['3_stars']),
                '2_stars' => $percent($breakdown['2_stars']),
                '1_star' => $percent($breakdown['1_star']),
            ],
        ];
    }

    /**
     * Public list row — never includes email. Safe when the author was deleted.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $includeCourse = false): array
    {
        $user = $this->user;
        $name = $user?->name ?: 'مستخدم';

        $row = [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'review' => $this->review,
            'user' => [
                'id' => $user?->id,
                'name' => $name,
                'avatar' => $user?->profile,
            ],
            'created_at' => $this->created_at?->format('Y-m-d'),
            'timestamp' => $this->created_at?->toIso8601String(),
            'time_ago' => $this->created_at?->diffForHumans(),
        ];

        if ($includeCourse && $this->rateable instanceof Course) {
            $row['course'] = [
                'id' => $this->rateable->id,
                'title' => $this->rateable->title,
                'slug' => $this->rateable->slug,
            ];
        }

        return $row;
    }

    /**
     * Admin moderation row. Name only — no email.
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        $user = $this->user;
        $rateable = $this->rateable;
        $courseTitle = null;

        if ($rateable instanceof Course) {
            $courseTitle = $rateable->title;
        } elseif ($rateable instanceof Instructor) {
            $courseTitle = $rateable->user?->name
                ? 'مدرب: ' . $rateable->user->name
                : 'مدرب #' . $this->rateable_id;
        } elseif ($rateable) {
            $courseTitle = $rateable->title ?? $rateable->name ?? null;
        }

        return [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'review' => $this->review,
            'comment' => $this->review,
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name ?: 'مستخدم',
                'image' => $user?->profile,
            ],
            'user_name' => $user?->name ?: 'مستخدم',
            'user_avatar' => $user?->profile,
            'course' => $courseTitle ? ['title' => $courseTitle] : null,
            'course_title' => $courseTitle,
            'rateable_type' => $this->rateable_type,
            'rateable_id' => $this->rateable_id,
            'status' => $this->status ?? 'pending',
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
