<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_automatic' => 'boolean', 'is_public' => 'boolean', 'started_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function statusPage()
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function components()
    {
        return $this->belongsToMany(Component::class, 'incident_components');
    }

    public function updates()
    {
        return $this->hasMany(IncidentUpdate::class)->latest();
    }
}
