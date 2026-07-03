<?php

namespace App\Models\Subscribers\Wb\Feedbacks;

use Illuminate\Database\Eloquent\Casts\Attribute;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedbacksTemplates extends Model
{
    use HasFactory;

    protected $table = 'subs_wb_feedbacks_templates';
    protected $fillable = [
        'client_id',
        'text',
        'rating'
    ];

    protected function rating(): Attribute
    {
        return Attribute::make(
            get: fn(string $value) => explode('-', $value),
            set: fn(array $value) => implode('-', $value),
        );
    }

}
