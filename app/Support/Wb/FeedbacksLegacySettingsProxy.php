<?php

namespace App\Support\Wb;

use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;

/**
 * Makes legacy FeedbacksClients look like WbFeedbacksSettings for bots.
 */
class FeedbacksLegacySettingsProxy
{
    public function __construct(
        private readonly FeedbacksClients $client,
    ) {
    }

    public function __get(string $key): mixed
    {
        return $this->client->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->client->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->client->getAttribute($key) !== null;
    }

    public function save(): bool
    {
        return $this->client->save();
    }
}
