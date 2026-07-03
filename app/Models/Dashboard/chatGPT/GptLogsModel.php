<?php

namespace App\Models\Dashboard\chatGPT;

use Carbon\Carbon;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GptLogsModel extends Model
{
    use HasFactory;

    protected $table = 'gpt_logs';
    protected $fillable = [
        'user_id', 'type', 'promt', 'response', 'model'
    ];

    public function getCreatedAtAttribute($value)
    {
        return Carbon::create($value)->setTimezone('Europe/Moscow')->format('d-m-Y H:i:s');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
