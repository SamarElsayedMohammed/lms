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
        'sent_count',
        'image',
    ];

    protected $appends = ['image_url'];

    public function plan()
    {
        return $this->belongsTo(\App\Models\SubscriptionPlan::class, 'plan_id');
    }

    /**
     * Accessor: رابط الصورة (URL مباشر)
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ?: null;
    }
}
