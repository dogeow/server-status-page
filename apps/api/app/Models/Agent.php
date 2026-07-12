<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'capabilities' => 'array',
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
            'enrolled_at' => 'datetime',
        ];
    }

    public function monitors()
    {
        return $this->hasMany(Monitor::class);
    }

    public function results()
    {
        return $this->hasMany(CheckResult::class);
    }
}
