<?php

namespace App\Plugins;

enum ConnectionMethod: string
{
    /** The person fills in fields, such as a store domain and API token. */
    case Fields = 'fields';

    /** The person signs in to the service and approves access. */
    case OAuth = 'oauth';
}
