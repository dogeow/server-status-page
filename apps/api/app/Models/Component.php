<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Component extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean', 'status_changed_at' => 'datetime'];
    }

    public function group()
    {
        return $this->belongsTo(ComponentGroup::class, 'component_group_id');
    }

    public function monitors()
    {
        return $this->hasMany(Monitor::class);
    }

    public function rollups()
    {
        return $this->hasMany(DailyRollup::class);
    }

    public function intervals()
    {
        return $this->hasMany(StatusInterval::class);
    }

    public function incidents()
    {
        return $this->belongsToMany(Incident::class, 'incident_components');
    }

    public function maintenanceWindows()
    {
        return $this->belongsToMany(MaintenanceWindow::class, 'maintenance_window_components');
    }
}
