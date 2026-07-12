<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['events' => 'array', 'component_ids' => 'array', 'quiet_hours' => 'array', 'enabled' => 'boolean'];
    }

    public function channel()
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }
}
