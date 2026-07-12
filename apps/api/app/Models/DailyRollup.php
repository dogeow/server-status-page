<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyRollup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'uptime_percentage' => 'decimal:4'];
    }

    public function component()
    {
        return $this->belongsTo(Component::class);
    }
}
