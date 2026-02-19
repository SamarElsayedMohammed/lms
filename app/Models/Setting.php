<?php

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'value',
        'type',
    ];

    /**
     * Get the value attribute with special handling for file types.
     *
     * @param string $value
     * @return mixed
     */
    public function getValueAttribute($value)
    {
        $excludeFiles = ['firebase_service_file'];
        if ($this->type === 'file' && $value && !in_array($this->name, $excludeFiles)) {
            // Get env filesystem path file url
            return FileService::getFileUrl($value);
        }

        if (in_array($this->type, ['text']) && $value) {
            return htmlspecialchars_decode($value, ENT_QUOTES);
        }

        return $value;
    }

    /**
     * Set the value attribute with HTML character encoding.
     *
     * @param mixed $value
     * @return void
     */
    public function setValueAttribute($value)
    {
        if (in_array($this->type, ['text']) && $value) {
            $this->attributes['value'] = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        } else {
            $this->attributes['value'] = $value;
        }
    }
}
