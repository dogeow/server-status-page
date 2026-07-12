<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceWindow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'exclude_from_uptime' => 'boolean'];
    }

    public function statusPage()
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function components()
    {
        return $this->belongsToMany(Component::class, 'maintenance_window_components');
    }
}
