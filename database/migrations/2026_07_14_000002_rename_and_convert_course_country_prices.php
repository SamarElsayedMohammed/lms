<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // We will add new columns, convert data, then drop old columns.
        Schema::table('course_country_prices', function (Blueprint $table) {
            $table->decimal('price_local', 10, 2)->after('price_egp')->nullable();
            $table->decimal('discount_price_local', 10, 2)->after('discount_price_egp')->nullable();
        });

        // Convert existing data
        $prices = DB::table('course_country_prices')->get();
        
        // Fetch all currencies
        $currencies = DB::table('supported_currencies')->get()->keyBy(function($item) {
            return strtoupper($item->currency_code);
        });

        foreach ($prices as $priceRow) {
            $country = DB::table('countries')->where('iso_code', $priceRow->country_code)->first();
            $currencyCode = $country ? $country->currency_code : 'USD';
            if (!$currencyCode) {
                $currencyCode = 'USD';
            }
            $currencyCode = strtoupper($currencyCode);

            $currency = $currencies->get($currencyCode);
            $exchangeRate = $currency ? ((float)($currency->active_exchange_rate ?? 1.0)) : 1.0;

            // Local = EGP / Exchange Rate
            $localPrice = $priceRow->price_egp / $exchangeRate;
            $localDiscount = $priceRow->discount_price_egp ? ($priceRow->discount_price_egp / $exchangeRate) : null;

            DB::table('course_country_prices')
                ->where('id', $priceRow->id)
                ->update([
                    'price_local' => round($localPrice, 2),
                    'discount_price_local' => $localDiscount !== null ? round($localDiscount, 2) : null,
                ]);
        }

        Schema::table('course_country_prices', function (Blueprint $table) {
            $table->dropColumn(['price_egp', 'discount_price_egp']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('course_country_prices', function (Blueprint $table) {
            $table->decimal('price_egp', 10, 2)->after('price_local')->nullable();
            $table->decimal('discount_price_egp', 10, 2)->after('discount_price_local')->nullable();
        });

        $prices = DB::table('course_country_prices')->get();
        
        $currencies = DB::table('supported_currencies')->get()->keyBy(function($item) {
            return strtoupper($item->currency_code);
        });

        foreach ($prices as $priceRow) {
            $country = DB::table('countries')->where('iso_code', $priceRow->country_code)->first();
            $currencyCode = $country ? $country->currency_code : 'USD';
            if (!$currencyCode) {
                $currencyCode = 'USD';
            }
            $currencyCode = strtoupper($currencyCode);

            $currency = $currencies->get($currencyCode);
            $exchangeRate = $currency ? ((float)($currency->active_exchange_rate ?? 1.0)) : 1.0;

            $egpPrice = $priceRow->price_local * $exchangeRate;
            $egpDiscount = $priceRow->discount_price_local ? ($priceRow->discount_price_local * $exchangeRate) : null;

            DB::table('course_country_prices')
                ->where('id', $priceRow->id)
                ->update([
                    'price_egp' => round($egpPrice, 2),
                    'discount_price_egp' => $egpDiscount !== null ? round($egpDiscount, 2) : null,
                ]);
        }

        Schema::table('course_country_prices', function (Blueprint $table) {
            $table->dropColumn(['price_local', 'discount_price_local']);
        });
    }
};
