<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Reservation extends Model
{
    protected $fillable = ['user_id', 'starts_at', 'ends_at', 'people_count'];

    protected $appends = ['location'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * La ubicacion de una reserva es la de sus mesas (todas coinciden por
     * construccion del punto c). Requiere la relacion tables cargada.
     */
    public function getLocationAttribute(): ?string
    {
        return $this->tables->first()?->location;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tables(): BelongsToMany
    {
        return $this->belongsToMany(Table::class);
    }
}
