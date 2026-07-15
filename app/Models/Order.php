<?php

namespace App\Models;

use App\Traits\ProtectsDemoData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory, ProtectsDemoData;

    protected static function booted()
    {
        static::updated(function ($order) {
            // Check if status changed to completed
            if ($order->isDirty('status') && $order->status === 'completed') {
                
                // 1. Auto Generate Certificates
                $orderCourses = $order->orderCourses()->where('certificate_purchased', true)->get();
                foreach ($orderCourses as $oc) {
                    try {
                        app(\App\Services\CertificateService::class)->autoGenerateCertificate($order->user_id, $oc->course_id);
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error('Auto Generate Certificate from Order Error: ' . $e->getMessage());
                    }
                }

                // 2. Decrement Promo Code Usage Limits safely
                try {
                    $promoCodeIds = $order->orderCourses()->whereNotNull('promo_code_id')->pluck('promo_code_id')->unique();
                    
                    if ($order->promo_code_id && !$promoCodeIds->contains($order->promo_code_id)) {
                        $promoCodeIds->push($order->promo_code_id);
                    }

                    foreach ($promoCodeIds as $promoId) {
                        $promo = \App\Models\PromoCode::find($promoId);
                        if ($promo && $promo->no_of_users !== null) {
                            $promo->decrement('no_of_users');
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Decrement Promo Code Usage Error: ' . $e->getMessage());
                }
            }
        });
    }

    protected $fillable = [
        'user_id',
        'order_number',
        'total_price',
        'tax_price',
        'final_price',
        'payment_method',
        'promo_code_id',
        'discount_amount',
        'promo_code',
        'status',
        'amount_egp',
        'exchange_rate_snapshot',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orderCourses()
    {
        return $this->hasMany(OrderCourse::class);
    }

    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function paymentTransaction()
    {
        return $this->hasOne(PaymentTransaction::class);
    }

    public function getSubtotalAttribute()
    {
        return $this->total_price ?? 0;
    }

    public function getTaxAmountAttribute()
    {
        return $this->tax_price ?? 0;
    }
}
