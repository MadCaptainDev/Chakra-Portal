<?php

namespace App\Services;

use RuntimeException;

/** OpenStreetMap's lookup would not answer -- try again later, remember nothing. */
class GeocoderUnavailable extends RuntimeException {}
