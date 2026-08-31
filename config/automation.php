<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inline automation execution
    |--------------------------------------------------------------------------
    |
    | When true, an automation run started by an event (a message arriving, a
    | contact being created, etc.) executes immediately in the same request
    | instead of being queued. This is the right default for shared hosting,
    | which has no long-running queue worker — a queued run would sit until the
    | next scheduler tick.
    |
    | Set AUTOMATION_EXECUTE_INLINE=false on a deployment that runs a real queue
    | worker (Redis + `queue:work`, Horizon) to move execution back off the
    | request. Delayed steps (Wait / Delay nodes) always go through the queue
    | regardless of this setting.
    |
    */

    'execute_inline' => (bool) env('AUTOMATION_EXECUTE_INLINE', true),

];
