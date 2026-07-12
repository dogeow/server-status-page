<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Monitor extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret_config'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'secret_config' => 'encrypted:array',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_event_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'last_alerted_at' => 'datetime',
        ];
    }

    public function component()
    {
        return $this->belongsTo(Component::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function results()
    {
        return $this->hasMany(CheckResult::class);
    }
}
