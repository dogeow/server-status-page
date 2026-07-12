<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusPage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'settings' => 'array'];
    }

    public function groups()
    {
        return $this->hasMany(ComponentGroup::class)->orderBy('position');
    }

    public function incidents()
    {
        return $this->hasMany(Incident::class);
    }

    public function maintenanceWindows()
    {
        return $this->hasMany(MaintenanceWindow::class);
    }
}
