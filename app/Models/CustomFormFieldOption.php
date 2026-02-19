<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomFormFieldOption extends Model
{
    protected $fillable = ['custom_form_field_id', 'option'];

    use SoftDeletes;

    public function custom_form_field()
    {
        return $this->belongsTo(CustomFormField::class);
    }
}
