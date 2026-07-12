<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckResult extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'received_at' => 'datetime', 'metrics' => 'array'];
    }

    public function monitor()
    {
        return $this->belongsTo(Monitor::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
