<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentUpdate extends Model
{
    protected $guarded = [];

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
