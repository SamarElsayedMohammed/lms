<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PromoCode;
use App\Models\PromoRedemption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManualSubscriptionAdminResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestPayment = $this->payments ? $this->payments->first() : null;

        // 1. Safe Normalization for account_details & method_snapshot
        $snapshot = $this->normalizeSnapshot($latestPayment);

        // 2. Safe Normalization for submitted_fields
        $submittedFields = $this->normalizeSubmittedFields($latestPayment);

        // 3. Receipt handling (relative URL works with frontend proxy & direct calls)
        $rawReceipt = $latestPayment?->getRawOriginal('receipt');
        $hasReceipt = !empty($rawReceipt);
        $receiptUrl = $hasReceipt
            ? "/api/admin/manual-subscriptions/{$this->id}/receipt"
            : null;

        // 4. Financial breakdown
        $originalAmount = (float) ($latestPayment?->original_amount ?? $this->locked_price ?? $this->plan?->price ?? 0);
        $discountAmount = (float) ($latestPayment?->discount_amount ?? 0);
        $finalAmount = (float) ($latestPayment?->final_amount ?? $latestPayment?->amount ?? $this->locked_price ?? 0);
        $currency = $latestPayment?->currency_code ?? $this->locked_currency ?? 'EGP';
        $promoCode = $latestPayment?->promo_code ? strtoupper(trim((string) $latestPayment->promo_code)) : null;

        $discountPercent = 0.0;
        if ($originalAmount > 0 && $discountAmount > 0) {
            $discountPercent = round(($discountAmount / $originalAmount) * 100, 2);
        }

        $redemption = null;
        if ($latestPayment && $promoCode) {
            $redemption = PromoRedemption::where('subscription_payment_id', $latestPayment->id)->first();
        }

        $priceBreakdown = [
            'original_amount' => $originalAmount,
            'discount_amount' => $discountAmount,
            'discount_percent' => $discountPercent,
            'final_amount' => $finalAmount,
            'currency' => $currency,
            'promo_code' => $promoCode,
            'discount_type' => $redemption?->discount_type_snapshot ?? ($discountAmount > 0 ? 'percentage' : null),
            'discount_value' => $redemption?->discount_value_snapshot ?? ($discountPercent > 0 ? $discountPercent : $discountAmount),
            'wallet_amount' => (float) ($latestPayment?->wallet_amount ?? 0),
            'gateway_amount' => (float) ($latestPayment?->gateway_amount ?? $finalAmount),
        ];

        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name ?? '—',
                'email' => $this->user?->email ?? '—',
                'mobile' => $this->user?->mobile ?? $this->user?->phone ?? '—',
                'country' => $this->user?->country ?? $latestPayment?->resolved_country ?? 'EG',
            ],
            'plan' => [
                'id' => $this->plan?->id,
                'name' => $this->plan?->name ?? '—',
                'billing_cycle' => $this->plan?->billing_cycle ?? 'custom',
                'duration_days' => $this->plan?->getDurationDays(),
                'locked_price' => (float) ($this->locked_price ?? $this->plan?->price ?? 0),
                'locked_currency' => $this->locked_currency ?? 'EGP',
            ],
            'amount' => $finalAmount,
            'original_amount' => $originalAmount,
            'discount_amount' => $discountAmount,
            'promo_code' => $promoCode,
            'price_breakdown' => $priceBreakdown,
            'currency' => $currency,
            'resolved_country' => $latestPayment?->resolved_country ?? 'EG',
            'payment_method' => $latestPayment?->payment_method ?? 'manual',
            'payment_status' => $latestPayment?->status ?? 'pending',
            'status' => $this->status,
            'method_snapshot' => $snapshot,
            'submitted_fields' => $submittedFields,
            'transaction_id' => $latestPayment?->transaction_id,
            'has_receipt' => $hasReceipt,
            'receipt_url' => $receiptUrl,
            'admin_notes' => $latestPayment?->admin_notes,
            'cancellation_reason' => $this->cancellation_reason,
            'starts_at' => $this->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $this->ends_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function normalizeSnapshot($payment): array
    {
        $raw = $payment?->method_snapshot;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : ['instructions' => $raw];
        }

        if (!is_array($raw)) {
            $depositMethod = $payment?->manualDepositMethod;
            if ($depositMethod) {
                $raw = [
                    'id' => $depositMethod->id,
                    'name' => $depositMethod->name,
                    'type' => 'bank_transfer',
                    'instructions' => $depositMethod->instructions,
                    'account_number' => $depositMethod->account_details,
                ];
            } else {
                $raw = [];
            }
        }

        $instructions = $raw['instructions'] ?? null;
        if (is_string($instructions) && str_starts_with(trim($instructions), '{') && str_ends_with(trim($instructions), '}')) {
            $instructionsJson = json_decode($instructions, true);
            if (is_array($instructionsJson)) {
                // If it's the raw placeholder schema with all null values, clear it
                $nonNullCount = count(array_filter($instructionsJson, fn($v) => !empty($v)));
                $instructions = $nonNullCount > 0 ? ($instructionsJson['instructions'] ?? null) : null;
            }
        }

        $accountNumber = $raw['account_number'] ?? $raw['account_details'] ?? null;
        if (is_string($accountNumber) && str_starts_with(trim($accountNumber), '{') && str_ends_with(trim($accountNumber), '}')) {
            $accountJson = json_decode($accountNumber, true);
            if (is_array($accountJson)) {
                $accountNumber = $accountJson['account_number'] ?? $accountJson['instapay_id'] ?? null;
            }
        }

        return [
            'id' => $raw['id'] ?? null,
            'name' => $raw['name'] ?? 'الدفع اليدوي',
            'type' => $raw['type'] ?? 'bank_transfer',
            'account_name' => $raw['account_name'] ?? null,
            'account_number' => $accountNumber,
            'bank_name' => $raw['bank_name'] ?? null,
            'iban' => $raw['iban'] ?? null,
            'instapay_id' => $raw['instapay_id'] ?? null,
            'merchant_code' => $raw['merchant_code'] ?? null,
            'instructions' => $instructions,
            'dynamic_fields' => is_array($raw['dynamic_fields'] ?? null) ? $raw['dynamic_fields'] : [],
        ];
    }

    private function normalizeSubmittedFields($payment): array
    {
        $raw = $payment?->submitted_fields;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $key => $value) {
            if (is_scalar($value)) {
                $result[(string) $key] = (string) $value;
            }
        }
        return $result;
    }
}
