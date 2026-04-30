<?php

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Model;

class ManualDeposit extends Model
{
    protected $fillable = [
        'user_id',
        'manual_deposit_method_id',
        'amount',
        'transaction_id',
        'receipt',
        'status',
        'admin_notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function method()
    {
        return $this->belongsTo(ManualDepositMethod::class, 'manual_deposit_method_id');
    }

    public function getReceiptAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }
}
