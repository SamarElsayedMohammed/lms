<?php

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Model;

class InstructorPersonalDetail extends Model
{
    protected $fillable = [
        'instructor_id',
        'qualification',
        'years_of_experience',
        'skills',
        'bank_account_number',
        'bank_name',
        'bank_account_holder_name',
        'bank_ifsc_code',
        'team_name',
        'team_logo',
        'about_me',
        'id_proof',
        'id_proof_extension',
        'preview_video',
        'preview_video_extension',
    ];

    public function instructor()
    {
        return $this->belongsTo(Instructor::class);
    }

    public function getTeamLogoAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }

    public function getIdProofAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }

    public function getPreviewVideoAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }
}
