<?php

namespace App\Services\Planner;

use RuntimeException;

/** Notion could not take a change, so nothing was changed. The message is for the person. */
class NotionWriteFailed extends RuntimeException {}
