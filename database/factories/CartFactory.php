<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use App\Models\Course\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
final class CartFactory extends Factory
{
    protected $model = Cart::class;

    #[\Override]
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'course_id' => Course::factory(),
            'promo_code_id' => null,
        ];
    }

    /**
     * Attach a promo code to the cart item
     */
    public function withPromoCode(int $promoCodeId): static
    {
        return $this->state(fn() => [
            'promo_code_id' => $promoCodeId,
        ]);
    }
}
