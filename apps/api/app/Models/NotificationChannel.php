<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationChannel extends Model
{
    protected $guarded = [];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'enabled' => 'boolean'];
    }

    public function policies()
    {
        return $this->hasMany(NotificationPolicy::class);
    }
}
