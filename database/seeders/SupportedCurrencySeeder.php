<?php

namespace Database\Seeders;

use App\Models\SupportedCurrency;
use Illuminate\Database\Seeder;

class SupportedCurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            [
                'country_code' => 'EG',
                'country_name' => 'Egypt',
                'currency_code' => 'EGP',
                'currency_symbol' => 'ج.م',
                'exchange_rate_to_egp' => 1.0000,
                'is_active' => true,
            ],
            [
                'country_code' => 'SA',
                'country_name' => 'Saudi Arabia',
                'currency_code' => 'SAR',
                'currency_symbol' => '﷼',
                'exchange_rate_to_egp' => 8.5000,
                'is_active' => true,
            ],
            [
                'country_code' => 'AE',
                'country_name' => 'United Arab Emirates',
                'currency_code' => 'AED',
                'currency_symbol' => 'د.إ',
                'exchange_rate_to_egp' => 8.5000,
                'is_active' => true,
            ],
            [
                'country_code' => 'US',
                'country_name' => 'United States',
                'currency_code' => 'USD',
                'currency_symbol' => '$',
                'exchange_rate_to_egp' => 31.0000,
                'is_active' => true,
            ],
            [
                'country_code' => 'GB',
                'country_name' => 'United Kingdom',
                'currency_code' => 'GBP',
                'currency_symbol' => '£',
                'exchange_rate_to_egp' => 39.0000,
                'is_active' => true,
            ],
            [
                'country_code' => 'KW',
                'country_name' => 'Kuwait',
                'currency_code' => 'KWD',
                'currency_symbol' => 'د.ك',
                'exchange_rate_to_egp' => 100.0000,
                'is_active' => true,
            ],
            [
                'country_code' => 'QA',
                'country_name' => 'Qatar',
                'currency_code' => 'QAR',
                'currency_symbol' => '﷼',
                'exchange_rate_to_egp' => 8.5000,
                'is_active' => true,
            ],
            [
                'country_code' => 'BH',
                'country_name' => 'Bahrain',
                'currency_code' => 'BHD',
                'currency_symbol' => 'د.ب',
                'exchange_rate_to_egp' => 82.0000,
                'is_active' => true,
            ],
            [
                'country_code' => 'OM',
                'country_name' => 'Oman',
                'currency_code' => 'OMR',
                'currency_symbol' => 'ر.ع',
                'exchange_rate_to_egp' => 80.0000,
                'is_active' => true,
            ],
        ];

        foreach ($currencies as $data) {
            SupportedCurrency::updateOrCreate(
                ['country_code' => $data['country_code']],
                $data
            );
        }
    }
}
