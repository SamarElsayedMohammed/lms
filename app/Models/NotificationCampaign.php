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
    ];

    public function plan()
    {
        return $this->belongsTo(\App\Models\SubscriptionPlan::class, 'plan_id');
    }
}
