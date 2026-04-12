<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Country;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SubscriptionPlanService
{
    public function resolveDefaultCountryId(?string $currencyHint): ?int
    {
        if ($currencyHint !== null && $currencyHint !== '') {
            $hint = trim($currencyHint);
            $byCurrency = Country::active()
                ->where('currency_name', 'like', '%' . $hint . '%')
                ->orderBy('id')
                ->value('id');
            if ($byCurrency !== null) {
                return (int) $byCurrency;
            }
        }

        $first = Country::active()->orderBy('id')->value('id');

        return $first !== null ? (int) $first : null;
    }

    /**
     * @param  array<string, mixed>  $panel  Validated panel payload (name, duration, price, etc.)
     *
     * @throws Throwable
     */
    public function createFromPanelPayload(array $panel, int $countryId): SubscriptionPlan
    {
        $sale = (float) $panel['price'];
        $old = isset($panel['old_price']) && $panel['old_price'] !== '' && $panel['old_price'] !== null
            ? (float) $panel['old_price'] : null;

        if ($old !== null && $old > $sale) {
            $listPrice = $old;
            $offerPrice = $sale;
        } else {
            $listPrice = $sale;
            $offerPrice = null;
        }

        $canonical = [
            'name' => $panel['name'],
            'description' => $panel['description'] ?? null,
            'billing_cycle' => 'custom',
            'duration_days' => (int) $panel['duration'],
            'price' => $listPrice,
            'commission_rate' => 0,
            'features' => $panel['features'] ?? null,
            'sort_order' => 0,
            'countries' => [
                [
                    'country_id' => $countryId,
                    'price' => $listPrice,
                    'offer_price' => $offerPrice,
                ],
            ],
        ];

        return $this->createWithCountryPricing($canonical, (bool) ($panel['status'] ?? true));
    }

    /**
     * @param  array<string, mixed>  $validated  Canonical plan fields including countries[]
     *
     * @throws Throwable
     */
    public function createWithCountryPricing(array $validated, bool $isActive): SubscriptionPlan
    {
        return DB::transaction(function () use ($validated, $isActive): SubscriptionPlan {
            $countriesData = $validated['countries'];
            unset($validated['countries']);

            $validated['slug'] = $this->uniqueSlugForName($validated['name']);
            $validated['duration_days'] = $this->resolveDurationDays($validated);
            $validated['commission_rate'] = $validated['commission_rate'] ?? 0;
            $validated['sort_order'] = $validated['sort_order'] ?? 0;
            $validated['is_active'] = $isActive;
            $validated['price'] = $validated['price'] ?? 0;

            $plan = SubscriptionPlan::create($validated);

            foreach ($countriesData as $entry) {
                SubscriptionPlanPrice::create([
                    'plan_id' => $plan->id,
                    'country_id' => $entry['country_id'],
                    'price' => $entry['price'],
                    'offer_price' => (isset($entry['offer_price']) && $entry['offer_price'] !== '' && $entry['offer_price'] !== null)
                        ? (float) $entry['offer_price'] : null,
                ]);
            }

            return $plan->load('countryPrices');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDurationDays(array $data): ?int
    {
        if (($data['billing_cycle'] ?? '') === 'custom') {
            return isset($data['duration_days']) ? (int) $data['duration_days'] : null;
        }
        if (($data['billing_cycle'] ?? '') === 'lifetime') {
            return null;
        }

        return SubscriptionPlan::CYCLE_DAYS[$data['billing_cycle']] ?? null;
    }

    private function uniqueSlugForName(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'plan-' . Str::lower(Str::random(10));
        }
        $slug = $base;
        $n = 0;
        while (SubscriptionPlan::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$n;
        }

        return $slug;
    }
}
