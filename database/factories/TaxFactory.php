<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tax>
 */
final class TaxFactory extends Factory
{
    protected $model = Tax::class;

    #[\Override]
    public function definition(): array
    {
        return [
            'name' => 'GST',
            'percentage' => 18.0,
            'country_code' => null,
            'is_active' => true,
            'is_default' => true,
            'is_inclusive' => false,
        ];
    }

    /**
     * Tax for a specific country
     */
    public function forCountry(string $countryCode, float $percentage): static
    {
        return $this->state(fn() => [
            'country_code' => $countryCode,
            'percentage' => $percentage,
            'is_default' => false,
        ]);
    }

    /**
     * Default tax (applies when no country-specific tax found)
     */
    public function default(float $percentage = 18.0): static
    {
        return $this->state(fn() => [
            'country_code' => null,
            'percentage' => $percentage,
            'is_default' => true,
        ]);
    }

    /**
     * Inactive tax
     */
    public function inactive(): static
    {
        return $this->state(fn() => [
            'is_active' => false,
        ]);
    }
}
