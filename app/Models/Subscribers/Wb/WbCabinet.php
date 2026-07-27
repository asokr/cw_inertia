<?php

namespace App\Models\Subscribers\Wb;

use App\Casts\EncryptCast;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WbCabinet extends Model
{
    /** Immediate hard-stop for jobs that should not keep calling WB API. */
    public const FATAL_ERROR_CODES = [401, 403];

    /**
     * Codes that prevent new stocks jobs from being dispatched
     * (includes chronic rate-limit disable after threshold).
     */
    public const SKIP_DISPATCH_ERROR_CODES = [401, 403, 429];

    /** Consecutive 429 responses within the window before auto-disable. */
    public const RATE_LIMIT_DISABLE_THRESHOLD = 8;

    protected $table = 'wb_cabinets';

    protected $fillable = [
        'user_id',
        'name',
        'apikey',
        'api_key_hash',
        'error_code',
        'error_message',
    ];

    protected $hidden = [
        'apikey',
        'api_key_hash',
    ];

    protected $casts = [
        'apikey' => EncryptCast::class,
        'error_code' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function feedbacksSettings(): HasOne
    {
        return $this->hasOne(WbFeedbacksSettings::class, 'cabinet_id');
    }

    public function clearApiError(): void
    {
        if ($this->error_code === null && $this->error_message === null) {
            return;
        }

        $this->forceFill([
            'error_code' => null,
            'error_message' => null,
        ])->save();
    }

    public function markApiError(int $code, ?string $message = null): void
    {
        $this->forceFill([
            'error_code' => $code,
            'error_message' => $message,
        ])->save();
    }

    public function shouldSkipDispatch(): bool
    {
        return in_array((int) $this->error_code, self::SKIP_DISPATCH_ERROR_CODES, true);
    }

    public function getCreatedAtAttribute($value): string
    {
        return Carbon::parse($value)
            ->setTimezone('Europe/Moscow')
            ->format('d.m.Y H:i');
    }

    public function getUpdatedAtAttribute($value): string
    {
        return Carbon::parse($value)
            ->setTimezone('Europe/Moscow')
            ->format('d.m.Y H:i');
    }
}
