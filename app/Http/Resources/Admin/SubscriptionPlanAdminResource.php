<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SubscriptionPlan
 */
final class SubscriptionPlanAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'billing_cycle' => $this->billing_cycle,
            'billing_cycle_label' => $this->billing_cycle_label,
            'duration_days' => $this->duration_days,
            'duration' => (string) ($this->duration_days ?? $this->getDurationDays() ?? ''),
            'price' => (float) $this->price,
            'usd_price' => $this->usd_price !== null ? (float) $this->usd_price : null,
            'commission_type' => $this->commission_type ?? 'percentage',
            'commission_rate' => (float) $this->commission_rate,
            'features' => $this->features,
            'status' => (bool) $this->is_active,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            'country_prices' => SubscriptionPlanPriceAdminResource::collection($this->whenLoaded('countryPrices')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
