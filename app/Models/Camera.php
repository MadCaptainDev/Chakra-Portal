<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One camera body, known by the serial in its clip sidecars
 * (M4ROOT/CLIP/C####M01.XML). clock_offset_seconds is what to add to the
 * camera's own clock to get real time: a body set to the wrong date or
 * zone still lines up with the crew's log once this is known. Chakra
 * Planner learns it while matching a card and saves it back here.
 */
class Camera extends Model
{
    protected $fillable = ['serial', 'name', 'clock_offset_seconds'];

    protected $casts = ['clock_offset_seconds' => 'integer'];

    /** @return array{serial: string, name: ?string, clock_offset_seconds: int} */
    public function toPlanner(): array
    {
        return [
            'serial' => $this->serial,
            'name' => $this->name,
            'clock_offset_seconds' => (int) $this->clock_offset_seconds,
        ];
    }
}
