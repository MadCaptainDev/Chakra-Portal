<?php

namespace App\Services\Planner;

use App\Models\ContentItem;
use RuntimeException;

/** The item changed under the person moving it; carries it as it now stands. */
class PlannerConflict extends RuntimeException
{
    public function __construct(public readonly ContentItem $item, string $message)
    {
        parent::__construct($message);
    }
}
