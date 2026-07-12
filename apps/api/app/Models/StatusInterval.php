<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusInterval extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'is_maintenance' => 'boolean'];
    }

    public function component()
    {
        return $this->belongsTo(Component::class);
    }
}
