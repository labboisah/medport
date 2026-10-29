<?php

namespace App\Integrations;

use RuntimeException;

/** The delivery as a whole cannot be understood; nothing is processed. */
class MalformedPayload extends RuntimeException {}
