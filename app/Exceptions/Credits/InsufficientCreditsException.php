<?php

namespace App\Exceptions\Credits;

use App\Support\CreditsFormatter;
use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $required,
        public readonly int $available,
    ) {
        parent::__construct('Недостаточно кредитов');
    }

    /**
     * @return array{required: int, available: int}
     */
    public function context(): array
    {
        return [
            'required' => $this->required,
            'available' => $this->available,
        ];
    }

    public function userMessage(): string
    {
        return sprintf(
            'Недостаточно кредитов. Нужно %s, доступно %s.',
            CreditsFormatter::amount($this->required),
            CreditsFormatter::amount($this->available),
        );
    }
}
