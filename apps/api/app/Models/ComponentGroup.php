<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComponentGroup extends Model
{
    protected $guarded = [];

    public function statusPage()
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function components()
    {
        return $this->hasMany(Component::class)->orderBy('position');
    }
}
