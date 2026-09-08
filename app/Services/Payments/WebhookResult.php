<?php

namespace App\Services\Payments;

enum WebhookResult: string
{
    case Created = 'created';
    case Duplicate = 'duplicate';
}
