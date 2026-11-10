<?php

namespace App\Services;

use RuntimeException;

/**
 * The shoot cannot leave: its kit is not listed, or not all picked up.
 *
 * Its own class so a caller that swallows ordinary "already wrapped" refusals
 * (CrewTrip does) can still let this one through to the person tapping --
 * it is the one refusal they have to act on.
 */
class KitNotReady extends RuntimeException {}
