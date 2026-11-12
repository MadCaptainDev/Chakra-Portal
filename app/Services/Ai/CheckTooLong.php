<?php

namespace App\Services\Ai;

use RuntimeException;

/** The AI check ran past its time limit; it is failed, not tried again by itself. */
class CheckTooLong extends RuntimeException {}
