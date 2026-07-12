<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    protected $guarded = [];

    protected $hidden = ['confirmation_token_hash', 'unsubscribe_token_hash'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    public function components()
    {
        return $this->belongsToMany(Component::class, 'subscriber_components');
    }
}
