<?php

declare(strict_types=1);

namespace App\Http\Resources;

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

        // 3. Receipt handling
        $rawReceipt = $latestPayment?->getRawOriginal('receipt');
        $hasReceipt = !empty($rawReceipt);
        $receiptUrl = $hasReceipt
            ? route('admin.manual-subscriptions.receipt', ['id' => $this->id])
            : null;

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
            'amount' => (float) ($latestPayment?->final_amount ?? $latestPayment?->amount ?? $this->locked_price ?? 0),
            'currency' => $latestPayment?->currency_code ?? $this->locked_currency ?? 'EGP',
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

        return [
            'id' => $raw['id'] ?? null,
            'name' => $raw['name'] ?? 'الدفع اليدوي',
            'type' => $raw['type'] ?? 'bank_transfer',
            'account_name' => $raw['account_name'] ?? null,
            'account_number' => $raw['account_number'] ?? $raw['account_details'] ?? null,
            'bank_name' => $raw['bank_name'] ?? null,
            'iban' => $raw['iban'] ?? null,
            'instapay_id' => $raw['instapay_id'] ?? null,
            'merchant_code' => $raw['merchant_code'] ?? null,
            'instructions' => $raw['instructions'] ?? null,
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
