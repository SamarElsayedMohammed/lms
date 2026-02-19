<?php

declare(strict_types=1);

namespace App\Models\Course\CourseChapter\Lecture;

use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\User;
use App\Services\FileService;
use App\Traits\HasChapterOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 *
 * @property int $id
 * @property int $user_id
 * @property int $course_chapter_id
 * @property string $title
 * @property string $slug
 * @property string $type
 * @property string|null $file
 * @property string|null $file_extension
 * @property string|null $youtube_url
 * @property int $hours
 * @property int $minutes
 * @property int $seconds
 * @property string|null $description
 * @property int $chapter_order
 * @property bool $is_active
 * @property bool $free_preview
 * @property string|null $hls_status
 * @property string|null $hls_manifest_path
 * @property string|null $hls_error_message
 * @property Carbon|null $hls_encoded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read int $duration
 * @property-read string $file_type API response file type (hls|video|yt|doc)
 * @property-read string|null $file_url_for_api API response file URL (HLS endpoint or direct URL)
 * @property-read CourseChapter $chapter
 * @property-read User $user
 * @property Collection<int, LectureResource> $resources
 * @property-read int|null $resources_count
 * @property-read Collection<int, LectureUserTrack> $userTracks
 * @property-read int|null $user_tracks_count
 *
 */
final class CourseChapterLecture extends Model
{
    use HasFactory, SoftDeletes, HasChapterOrder;

    protected $fillable = [
        'user_id',
        'course_chapter_id',
        'title',
        'slug',
        'type',
        'file',
        'file_extension',
        'youtube_url',
        'hours',
        'minutes',
        'seconds',
        'description',
        'chapter_order',
        'is_active',
        'free_preview',
        'is_free',
        'hls_status',
        'hls_manifest_path',
        'hls_error_message',
        'hls_encoded_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'free_preview' => 'boolean',
        'is_free' => 'boolean',
        'hls_encoded_at' => 'datetime',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(CourseChapter::class, 'course_chapter_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LectureResource>
     */
    public function resources(): HasMany
    {
        /** @var HasMany<LectureResource> $relation */
        $relation = $this->hasMany(\App\Models\Course\CourseChapter\Lecture\LectureResource::class, 'lecture_id');
        $relation->orderBy('order');
        return $relation;
    }

    public function userTracks(): HasMany
    {
        return $this->hasMany(LectureUserTrack::class, 'lecture_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(\App\Models\LectureAttachment::class, 'lecture_id')->orderBy('sort_order');
    }

    public function getDurationAttribute(): int
    {
        return ($this->hours * 3600) + ($this->minutes * 60) + $this->seconds;
    }

    /**
     * Get File URl
     */
    public function getFileAttribute(mixed $value): mixed
    {
        if ($this->type == 'file') {
            return FileService::getFileUrl($value);
        }
        return $value;
    }

    /**
     * Check if lecture has HLS version available
     */
    public function hasHls(): bool
    {
        return $this->hls_status === 'completed' && $this->hls_manifest_path !== null;
    }

    /**
     * Check if lecture needs HLS encoding
     */
    public function needsHlsEncoding(): bool
    {
        // Only file type videos need encoding
        if ($this->type !== 'file') {
            return false;
        }

        // Check if file exists and is a video
        $videoExtensions = ['mp4', 'avi', 'mov', 'webm', 'mkv', 'flv', 'wmv'];
        if (!in_array(strtolower($this->file_extension ?? ''), $videoExtensions, true)) {
            return false;
        }

        // Needs encoding if not completed or failed
        return $this->hls_status === null || $this->hls_status === 'failed' || $this->hls_status === 'pending';
    }

    /**
     * Get the full HLS manifest URL
     */
    public function getHlsManifestUrl(): null|string
    {
        if (!$this->hasHls()) {
            return null;
        }

        return FileService::getFileUrl($this->hls_manifest_path);
    }

    /**
     * Get file type for API response (accessor)
     * Laravel automatically makes this available as $lecture->file_type
     *
     * @return string hls|video|yt|doc
     */
    public function getFileTypeAttribute(): string
    {
        // YouTube videos
        if ($this->type === 'youtube_url') {
            return 'yt';
        }

        // File type lectures
        if ($this->type === 'file') {
            // Check if HLS is available
            if ($this->hasHls() && !$this->free_preview) {
                return 'hls';
            }

            // Check if it's a video file (use direct video streaming)
            $videoExtensions = ['mp4', 'avi', 'mov', 'webm', 'mkv', 'flv', 'wmv', 'm4v', '3gp', '3g2'];
            if (in_array(strtolower($this->file_extension ?? ''), $videoExtensions, true)) {
                return 'video';
            }

            // Otherwise it's a document/audio
            return 'doc';
        }

        return 'doc';
    }

    /**
     * Get file URL for API response (accessor)
     * Laravel automatically makes this available as $lecture->file_url_for_api
     * Returns HLS endpoint if available, otherwise direct file URL
     */
    public function getFileUrlForApiAttribute(): null|string
    {
        // YouTube videos
        if ($this->type === 'youtube_url') {
            return $this->youtube_url;
        }

        // File type lectures
        if ($this->type === 'file') {
            // Check if HLS is available
            if ($this->hasHls() && !$this->free_preview) {
                // Return API endpoint for HLS streaming (requires authentication)
                return url("/api/video/{$this->id}/stream");
            }

            // Return direct file URL
            return $this->file;
        }

        return null;
    }
}
