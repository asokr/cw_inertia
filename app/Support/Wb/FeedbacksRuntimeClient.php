<?php

namespace App\Support\Wb;

use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\Subscribers\Wb\WbCabinet;
use ArrayAccess;

/**
 * Unified view of a feedbacks "cabinet" for background bots:
 * either new WbCabinet + settings, or legacy FeedbacksClients.
 *
 * Supports both object and array access used in SubscriberWbFeedbacksAnswer.
 *
 * @implements ArrayAccess<string, mixed>
 */
class FeedbacksRuntimeClient implements ArrayAccess
{
    public const SOURCE_UNIFIED = 'unified';

    public const SOURCE_LEGACY = 'legacy';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $apikey,
        public readonly string $source,
        public readonly object $feedbacksSettings,
    ) {
    }

    public static function fromWbCabinet(WbCabinet $cabinet): self
    {
        $settings = $cabinet->feedbacksSettings
            ?? WbFeedbacksSettings::query()->firstOrCreate(
                ['cabinet_id' => $cabinet->id],
                [
                    'brands' => null,
                    'bot_status' => false,
                    'ai_status' => false,
                    'ai_ratings' => null,
                    'review_type' => null,
                ]
            );

        return new self(
            id: (int) $cabinet->id,
            name: (string) $cabinet->name,
            apikey: (string) $cabinet->apikey,
            source: self::SOURCE_UNIFIED,
            feedbacksSettings: $settings,
        );
    }

    public static function fromLegacy(FeedbacksClients $client): self
    {
        return new self(
            id: (int) $client->id,
            name: (string) $client->name,
            apikey: (string) $client->apikey,
            source: self::SOURCE_LEGACY,
            feedbacksSettings: new FeedbacksLegacySettingsProxy($client),
        );
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array((string) $offset, ['id', 'name', 'apikey', 'source'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ((string) $offset) {
            'id' => $this->id,
            'name' => $this->name,
            'apikey' => $this->apikey,
            'source' => $this->source,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Immutable runtime view.
    }

    public function offsetUnset(mixed $offset): void
    {
        // Immutable runtime view.
    }
}
