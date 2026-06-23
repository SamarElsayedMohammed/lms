<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Country;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\SupportedCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing subscription plan country-specific pricing
 *
 * This service ensures database integrity by:
 * 1. Always deriving country_code and currency_code from the Countries table
 * 2. Never trusting frontend-provided values for these fields
 * 3. Validating country existence before creating prices
 * 4. Creating/updating SupportedCurrency records when needed
 */
final class SubscriptionPlanPriceService
{
    /**
     * Sync country prices for a subscription plan
     *
     * This method handles the complete sync of country-specific prices:
     * - Creates new prices for countries that don't have one
     * - Updates existing prices
     * - Removes prices for countries not in the input (if $removeOthers is true)
     *
     * @param SubscriptionPlan $plan The subscription plan
     * @param array $countryPrices Array of country price data
     * @param bool $removeOthers Whether to remove prices for countries not in $countryPrices
     * @return array Array of created/updated SubscriptionPlanPrice models
     */
    public function syncCountryPrices(
        SubscriptionPlan $plan,
        array $countryPrices,
        bool $removeOthers = true
    ): array {
        if (empty($countryPrices)) {
            if ($removeOthers) {
                $plan->countryPrices()->forceDelete();
            }
            return [];
        }

        return DB::transaction(function () use ($plan, $countryPrices, $removeOthers) {
            $processedCountryIds = [];
            $results = [];

            foreach ($countryPrices as $priceData) {
                $result = $this->upsertCountryPrice($plan, $priceData);
                if ($result !== null) {
                    $results[] = $result;
                    $processedCountryIds[] = $result->country_id;
                }
            }

            // Remove prices for countries not in the input
            if ($removeOthers && !empty($processedCountryIds)) {
                $plan->countryPrices()
                    ->whereNotIn('country_id', $processedCountryIds)
                    ->forceDelete();
            }

            return $results;
        });
    }

    /**
     * Create or update a single country price
     *
     * IMPORTANT: This method ALWAYS derives country_code and currency_code
     * from the Countries table to ensure database integrity. Frontend values
     * are ignored.
     *
     * @param SubscriptionPlan $plan The subscription plan
     * @param array $priceData Price data containing at minimum: country_id, price
     * @return SubscriptionPlanPrice|null The created/updated price or null if country not found
     */
    public function upsertCountryPrice(SubscriptionPlan $plan, array $priceData): ?SubscriptionPlanPrice
    {
        // country_id is required
        $countryId = $priceData['country_id'] ?? null;
        if ($countryId === null) {
            Log::warning('SubscriptionPlanPriceService: Missing country_id in price data', [
                'plan_id' => $plan->id,
                'price_data' => $priceData,
            ]);
            return null;
        }

        // Fetch country from database to ensure integrity
        $country = Country::find($countryId);
        if ($country === null) {
            Log::warning('SubscriptionPlanPriceService: Country not found', [
                'plan_id' => $plan->id,
                'country_id' => $countryId,
            ]);
            return null;
        }

        // ALWAYS derive country_code and currency_code from the database
        // Never trust frontend values for these fields
        $countryCode = strtoupper($country->iso_code);
        $currencyCode = strtoupper($country->currency_code ?? 'EGP');

        // Ensure the currency exists in SupportedCurrency table
        $this->ensureCurrencyExists($countryCode, $currencyCode);

        // Create or update the price record
        $price = SubscriptionPlanPrice::withTrashed()->updateOrCreate(
            [
                'plan_id' => $plan->id,
                'country_id' => $country->id,
            ],
            [
                'country_code' => $countryCode,
                'currency_code' => $currencyCode,
                'price' => (float) ($priceData['price'] ?? 0),
                'old_price' => isset($priceData['old_price']) && $priceData['old_price'] !== ''
                    ? (float) $priceData['old_price']
                    : null,
                'is_active' => (bool) ($priceData['is_active'] ?? true),
                'can_subscribe' => (bool) ($priceData['can_subscribe'] ?? true),
                'deleted_at' => null, // Restore if it was soft deleted
            ]
        );

        Log::debug('SubscriptionPlanPriceService: Country price upserted', [
            'plan_id' => $plan->id,
            'country_id' => $country->id,
            'country_code' => $countryCode,
            'currency_code' => $currencyCode,
            'price' => $price->price,
        ]);

        return $price;
    }

    /**
     * Ensure a currency exists in the SupportedCurrency table
     *
     * This is called automatically when creating country prices to ensure
     * the currency is available for exchange rate lookups.
     */
    private function ensureCurrencyExists(string $countryCode, string $currencyCode): void
    {
        if (class_exists(SupportedCurrency::class) && method_exists(SupportedCurrency::class, 'ensureCurrencyExists')) {
            SupportedCurrency::ensureCurrencyExists($countryCode, $currencyCode);
        }
    }

    /**
     * Get all country prices for a plan with country details
     *
     * @param SubscriptionPlan $plan
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCountryPricesWithDetails(SubscriptionPlan $plan)
    {
        return $plan->countryPrices()
            ->with('country')
            ->orderBy('country_code')
            ->get();
    }

    /**
     * Delete a specific country price
     *
     * @param SubscriptionPlan $plan
     * @param int $countryId
     * @return bool
     */
    public function deleteCountryPrice(SubscriptionPlan $plan, int $countryId): bool
    {
        return $plan->countryPrices()
            ->where('country_id', $countryId)
            ->forceDelete() > 0;
    }

    /**
     * Validate that country data is consistent
     *
     * This method can be used to validate incoming data before processing
     *
     * @param array $priceData
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validateCountryPrice(array $priceData): array
    {
        $errors = [];

        if (!isset($priceData['country_id'])) {
            $errors[] = 'country_id is required';
        } else {
            $country = Country::find($priceData['country_id']);
            if ($country === null) {
                $errors[] = "Country with ID {$priceData['country_id']} does not exist";
            }
        }

        if (!isset($priceData['price']) || !is_numeric($priceData['price'])) {
            $errors[] = 'price is required and must be numeric';
        } elseif ((float) $priceData['price'] < 0) {
            $errors[] = 'price cannot be negative';
        }

        if (isset($priceData['old_price']) && $priceData['old_price'] !== '' && $priceData['old_price'] !== null) {
            if (!is_numeric($priceData['old_price'])) {
                $errors[] = 'old_price must be numeric';
            } elseif ((float) $priceData['old_price'] < 0) {
                $errors[] = 'old_price cannot be negative';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
