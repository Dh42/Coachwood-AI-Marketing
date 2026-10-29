<?php

return [

    /*
    | How long browsers remember to use only HTTPS for the platform
    | (Strict-Transport-Security), in seconds. 0 turns it off.
    */

    'hsts_seconds' => (int) env('HSTS_SECONDS', 31536000),

];
