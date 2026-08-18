<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Course\Course;
use App\Traits\ProtectsDemoData;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasPermissions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $email
 * @property string|null $mobile
 * @property string $password
 * @property bool $is_active
 * @property string|null $country_calling_code
 * @property string|null $country_code
 * @property string|null $profile
 * @property float $wallet_balance
 * @property string|null $type
 * @property Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $instructor_process_status
 * @property-read Collection|Course[] $courses
 * @property-read int|null $courses_count
 * @property-read Instructor|null $instructor_details
 * @property-read Collection|Cart[] $carts
 * @property-read int|null $carts_count
 * @property-read Collection|Wishlist[] $wishlists
 * @property-read int|null $wishlists_count
 * @property-read Collection|Order[] $orders
 * @property-read int|null $orders_count
 * @property-read Collection|Course[] $wishlistCourses
 * @property-read int|null $wishlist_courses_count
 * @property-read Collection|Course[] $trackedCourses
 * @property-read int|null $tracked_courses_count
 * @property-read Role|null $assignedRole
 * @property-read Collection|WalletHistory[] $walletHistories
 * @property-read int|null $wallet_histories_count
 * @property-read Collection|RefundRequest[] $refundRequests
 * @property-read int|null $refund_requests_count
 * @property-read Collection|RefundRequest[] $processedRefunds
 * @property-read int|null $processed_refunds_count
 * @property-read Collection|Rating[] $ratings
 * @property-read int|null $ratings_count
 * @property-read UserBillingDetail|null $billingDetails
 */
final class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes, HasPermissions, ProtectsDemoData;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'email',
        'mobile',
        'password',
        'is_active',
        'country_calling_code',
        'country_code',
        'profile',
        'wallet_balance',
        'type',
        'referred_by',
        'allowed_devices_count',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'wallet_balance' => 'decimal:2',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'avatar',
        'avatar_url',
        'phone',
    ];

    protected function profile(): Attribute
    {
        return Attribute::get(function (null|string $value): null|string {
            if ($value !== null && $value !== '') {
                if (filter_var($value, FILTER_VALIDATE_URL) || str_starts_with($value, 'data:') || str_starts_with($value, 'blob:')) {
                    return $value;
                }
                $clean = preg_replace('#^/?storage/#', '', $value);
                return asset('storage/' . $clean);
            }
            return $value;
        });
    }

    public function getAvatarAttribute(): ?string
    {
        return $this->profile;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->profile;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->mobile;
    }

    /**
     * Get the courses for the user.
     */
    public function courses(): User|HasMany
    {
        return $this->hasMany(Course::class, 'user_id', 'id');
    }

    /**
     * Get the instructor process status for the user.
     */
    public function instructor_details(): HasOne|User
    {
        return $this->hasOne(Instructor::class);
    }

    /**
     * Get the instructor process status for the user.
     */
    public function instructorProcessStatus(): Attribute
    {
        return Attribute::get(fn() => $this->instructor_details->status ?? 'pending');
    }

    public function carts(): User|HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function wishlists(): User|HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function orders(): User|HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function wishlistCourses(): BelongsToMany
    {
        return $this->belongsToMany(
            Course::class,
            'wishlists', // Pivot table name
            'user_id', // Foreign key on the pivot table for this model
            'course_id', // Foreign key on the pivot table for related model
        );
    }

    public function trackedCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_user_tracks')->withPivot('status')->withTimestamps();
    }

    public function courseProgress(): HasMany
    {
        return $this->hasMany(UserCourseProgress::class, 'user_id');
    }

    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function walletHistories(): User|HasMany
    {
        return $this->hasMany(WalletHistory::class);
    }

    public function refundRequests(): User|HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function processedRefunds(): User|HasMany
    {
        return $this->hasMany(RefundRequest::class, 'processed_by');
    }

    /**
     * Get the ratings given by the user.
     */
    public function ratings(): User|HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * Get the billing details for the user.
     */
    public function billingDetails(): HasOne|User
    {
        return $this->hasOne(UserBillingDetail::class);
    }

    /**
     * Get all subscriptions for the user.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the active subscription for the user.
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest('starts_at');
    }

    /**
     * Get the affiliate link for the user.
     */
    public function affiliateLink(): HasOne
    {
        return $this->hasOne(AffiliateLink::class);
    }

    /**
     * Get the user who referred this user (affiliate).
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * Get the user's credit cards.
     */
    public function creditCards(): HasMany
    {
        return $this->hasMany(UserCreditCard::class);
    }

    /**
     * Get users referred by this user.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    /**
     * Determine if the user is an admin.
     */
    public function getIsAdminAttribute(): bool
    {
        $adminRoles = [
            'Super Admin',
            'Admin',
            'Supervisor',
            'Staff',
            'Accountant',
            'Sales',
            'Moderator'
        ];
        return $this->hasAnyRole($adminRoles, 'web');
    }
}
