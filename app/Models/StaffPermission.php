<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Permission;

class StaffPermission extends Model
{
    use SoftDeletes;

    protected $table = 'staff_permissions';

    protected $fillable = [
        'staff_id',
        'permission_id',
    ];

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}
