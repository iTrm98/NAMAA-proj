<?php

namespace App\Services;

use RuntimeException;

/** The vision service (or the camera) did not answer, timed out or failed. */
class VisionUnavailable extends RuntimeException {}
