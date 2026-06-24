<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SubscriptionPlanPrice
 */
final class SubscriptionPlanPriceAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'country_id' => $this->country_id,
            'country_code' => $this->country_code,
            'currency_code' => $this->currency_code,
            'price' => (float) $this->price,
            'old_price' => $this->old_price !== null ? (float) $this->old_price : null,
            'offer_price' => $this->offer_price !== null ? (float) $this->offer_price : null,
            'is_active' => (bool) $this->is_active,
            'can_subscribe' => (bool) $this->can_subscribe,
        ];
    }
}
