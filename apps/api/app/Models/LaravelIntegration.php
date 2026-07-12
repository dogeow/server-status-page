<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LaravelIntegration extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['secret_current', 'secret_next'];

    protected function casts(): array
    {
        return [
            'secret_current' => 'encrypted',
            'secret_next' => 'encrypted',
            'enabled' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function statusPage()
    {
        return $this->belongsTo(StatusPage::class);
    }
}
