<?php

namespace App\Services\Ticketmaster;

use RuntimeException;

/** Code is the HTTP status, or 0 when Ticketmaster could not be reached. */
class TicketmasterException extends RuntimeException
{
    public function isNotFound(): bool
    {
        return $this->getCode() === 404;
    }
}
