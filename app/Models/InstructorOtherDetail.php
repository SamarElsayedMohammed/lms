<?php

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Model;

class InstructorOtherDetail extends Model
{
    protected $fillable = [
        'instructor_id',
        'custom_form_field_id',
        'custom_form_field_option_id',
        'value',
    ];

    public function instructor()
    {
        return $this->belongsTo(Instructor::class);
    }

    public function custom_form_field()
    {
        return $this->belongsTo(CustomFormField::class);
    }

    public function custom_form_field_option()
    {
        return $this->belongsTo(CustomFormFieldOption::class);
    }

    public function getValueAttribute($value)
    {
        // If custom_form_field_option_id exists, try to get the option value
        if ($this->custom_form_field_option_id) {
            // Check if relationship is loaded, if not load it
            if (!$this->relationLoaded('custom_form_field_option')) {
                $this->load('custom_form_field_option');
            }

            // If custom_form_field_option exists and has an option value, return that instead
            if ($this->custom_form_field_option && $this->custom_form_field_option->option) {
                return $this->custom_form_field_option->option;
            }
        }

        // Check if custom_form_field relationship exists before accessing its properties
        // This handles cases where the relationship is null (e.g., soft deleted custom_form_field)
        $customFormField = $this->custom_form_field;
        if ($customFormField !== null && $customFormField->type == 'file') {
            return FileService::getFileUrl($value);
        }
        return $value;
    }
}
