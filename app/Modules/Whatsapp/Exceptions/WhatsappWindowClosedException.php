<?php

namespace App\Modules\Whatsapp\Exceptions;

/**
 * A Cloud API free-form (non-template) send was attempted outside WhatsApp's
 * 24-hour customer-service window. Callers surface this as a 422 with a
 * `window_closed` flag so integrators know to re-engage with an approved
 * template. WhatsApp Web (personal number) sends never raise this.
 */
class WhatsappWindowClosedException extends \RuntimeException {}
