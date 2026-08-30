<?php

namespace App\Modules\Social\Exceptions;

/**
 * A publish failure that retrying will never fix (missing media, disconnected
 * account, expired token with no refresh path). The publisher records these
 * immediately and does not burn job retries on them.
 */
class PermanentPublishException extends \RuntimeException {}
