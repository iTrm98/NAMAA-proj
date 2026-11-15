<?php

namespace App\Services;

use RuntimeException;

/** The camera (or the USB camera bridge) did not answer with a picture. */
class CameraOffline extends RuntimeException {}
