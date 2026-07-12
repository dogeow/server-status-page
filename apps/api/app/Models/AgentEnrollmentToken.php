<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentEnrollmentToken extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
