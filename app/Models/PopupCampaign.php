<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PopupCampaign extends Model
{
    protected $fillable = [
        'title',
        'description',
        'image',
        'promo_code_id',   // kept for backward compat — not used in site response
        'promo_code',      // display-only promo code string (e.g. SKILLS026)
        'discount_value',  // numeric value (e.g. 30)
        'discount_type',   // 'percentage' | 'amount'
        'cta_url',         // link for CTA button (e.g. /subscription-plans)
        'cta_text',        // label for CTA button (e.g. اشترك الآن)
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'starts_at'      => 'datetime',
        'ends_at'        => 'datetime',
        'discount_value' => 'float',
    ];

    /**
     * Legacy relation — promo_codes are for courses, not subscriptions.
     * Kept for backward compatibility only.
     */
    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }
}
