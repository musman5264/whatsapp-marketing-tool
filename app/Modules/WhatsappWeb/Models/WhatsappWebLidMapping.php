<?php

namespace App\Modules\WhatsappWeb\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A LID sender's phone number as WAHA reported it, kept per WhatsApp Web session.
 * See app/logic/waha-sync-and-labels.md ("Inbound from a LID sender").
 */
class WhatsappWebLidMapping extends Model
{
    protected $fillable = ['session_name', 'lid', 'phone_e164', 'name'];
}
