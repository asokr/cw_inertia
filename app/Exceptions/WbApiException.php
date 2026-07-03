<?php

namespace App\Exceptions;

use Exception;

class WbApiException extends Exception
{
    public int $httpStatus;
    public array $payload;

    public function __construct(string $message, int $httpStatus = 0, array $payload = [])
    {
        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
        $this->payload = $payload;
    }
}
