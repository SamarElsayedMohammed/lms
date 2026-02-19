<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomFormField extends Model
{
    use SoftDeletes;

    protected $table = 'custom_form_fields';

    protected $fillable = [
        'name',
        'type',
        'is_required',
        'sort_order',
    ];

    // protected $casts = [
    //     'is_required' => 'boolean',
    //     'default_values' => 'array',
    // ];

    /**
     * Automatically set the sort order before creating a new record.
     */
    #[\Override]
    public static function boot()
    {
        parent::boot();

        static::creating(static function ($model): void {
            $maxSortOrder = static::max('sort_order') ?? 0;
            $model->sort_order = $maxSortOrder + 1;
        });
    }

    /**
     * @param $value
     * @return array|mixed
     * @throws JsonException
     */
    public function getDefaultValuesAttribute($value)
    {
        if (!empty($value) && !is_array($value)) {
            return json_decode((string) $value, false, 512, JSON_THROW_ON_ERROR);
        }
        return $value;
    }

    public function options()
    {
        return $this->hasMany(CustomFormFieldOption::class, 'custom_form_field_id');
    }
}
