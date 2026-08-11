<?php

namespace App\Models;

use App\Support\Html;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptSection extends Model
{
    /**
     * Section names the writers reach for most, offered as suggestions on the
     * add-section field. Suggestions, not a closed list -- the brief is
     * explicit that a writer must be able to name a block anything.
     *
     * @var list<string>
     */
    public const COMMON_HEADINGS = [
        'Hook',
        'Introduction',
        'Body',
        'CTA',
        'Scene',
        'Dialogue',
        'Voice Over',
        'Visual Direction',
        'B-Roll',
        'Caption',
        'Ending',
    ];

    /*
     * position and version are not fillable. position is owned by the reorder
     * action and version by the autosave's conflict check -- letting either
     * arrive from a form would hand a client the ability to jump the queue or
     * defeat the check entirely.
     */
    protected $fillable = [
        'script_id',
        'heading',
        'body',
    ];

    protected $casts = [
        'position' => 'integer',
        'version' => 'integer',
    ];

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    /**
     * Everything written to body goes through the allowlist, wherever it came
     * from. Putting it on the mutator rather than in the controller means a
     * seeder, a console command or a future import cannot bypass it.
     */
    public function setBodyAttribute(?string $value): void
    {
        $this->attributes['body'] = Html::sanitise($value);
    }

    /** Is there anything in this block yet? */
    public function isEmpty(): bool
    {
        return trim(strip_tags((string) $this->body)) === '';
    }
}
