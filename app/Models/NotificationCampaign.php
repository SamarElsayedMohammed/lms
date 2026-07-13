<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationCampaign extends Model
{
    protected $fillable = [
        'title',
        'message',
        'target_type',
        'plan_id',
        'plan_ids',
        'channels',
        'sent_count',
        'image',
        'icon',
        'icon_color',
    ];

    protected $casts = [
        'plan_ids' => 'array',
        'channels' => 'array',
    ];

    protected $appends = ['image_url'];

    /** Legacy single-plan relationship (kept for backwards compat) */
    public function plan()
    {
        return $this->belongsTo(\App\Models\SubscriptionPlan::class, 'plan_id');
    }

    /** Multi-plan relationship */
    public function plans()
    {
        return $this->belongsToMany(
            \App\Models\SubscriptionPlan::class,
            null,
            null,
            null
        )->whereIn('subscription_plans.id', $this->plan_ids ?? []);
    }

    /**
     * Accessor: رابط الصورة (URL مباشر)
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ?: null;
    }
}
