<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueProbeRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enqueued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'degraded_at' => 'datetime',
            'down_at' => 'datetime',
        ];
    }

    public function monitor()
    {
        return $this->belongsTo(Monitor::class);
    }
}
