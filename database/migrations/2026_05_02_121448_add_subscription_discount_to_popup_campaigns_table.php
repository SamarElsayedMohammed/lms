<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * الـ Popup Campaigns أصبحت خاصة بالباقات (Subscription Plans).
     * نضيف حقول الخصم مباشرة بدلاً من FK لـ promo_codes (الخاصة بالكورسات).
     *
     * discount_value  : قيمة الخصم (مثلاً: 30)
     * discount_type   : نوع الخصم (percentage | amount)
     * promo_code      : الكود النصي فقط للعرض (SKILLS026) — اختياري
     *
     * نترك promo_code_id كما هو (nullable) للتوافق مع البيانات القديمة،
     * لكن لن نستخدمه في الـ Site response بعد الآن.
     */
    public function up(): void
    {
        Schema::table('popup_campaigns', function (Blueprint $table) {
            $table->string('promo_code')->nullable()->after('promo_code_id')
                  ->comment('Promo code string to display (e.g. SKILLS026)');
            $table->decimal('discount_value', 8, 2)->nullable()->after('promo_code')
                  ->comment('Discount value (e.g. 30 for 30%)');
            $table->enum('discount_type', ['percentage', 'amount'])->default('percentage')->after('discount_value')
                  ->comment('Type of discount');
            $table->string('cta_url')->nullable()->after('discount_type')
                  ->comment('Call-to-action URL (e.g. /subscription-plans)');
            $table->string('cta_text')->nullable()->after('cta_url')
                  ->comment('Call-to-action button text (e.g. اشترك الآن)');
        });
    }

    public function down(): void
    {
        Schema::table('popup_campaigns', function (Blueprint $table) {
            $table->dropColumn(['promo_code', 'discount_value', 'discount_type', 'cta_url', 'cta_text']);
        });
    }
};
