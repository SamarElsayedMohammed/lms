<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Article extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'title',
        'description',
        'content',
        'author',
        'cover_image',
        'tags',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->where(function (Builder $inner) {
                $inner->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function coverImageUrl(): ?string
    {
        $value = trim((string) $this->cover_image);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'blob:')) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }

    public static function makeUniqueSlug(string $title, ?string $requested = null, ?int $ignoreId = null): string
    {
        $base = trim((string) $requested);
        if ($base === '') {
            $base = (string) Str::of($title)
                ->lower()
                ->replaceMatches('/\s+/u', '-')
                ->replaceMatches('/[^\p{L}\p{N}-]+/u', '')
                ->replaceMatches('/-+/u', '-')
                ->trim('-');
        }

        $base = trim($base, '-/');
        if ($base === '') {
            $base = 'article-' . Str::lower(Str::random(8));
        }

        $slug = $base;
        $suffix = 2;
        while (
            static::query()
                ->when($ignoreId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
