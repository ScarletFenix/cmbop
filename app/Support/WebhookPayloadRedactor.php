<?php

namespace App\Support;

class WebhookPayloadRedactor
{
    /**
     * Scalar metadata keys that are enough to route a Stripe event again.
     *
     * @var list<string>
     */
    private const STRIPE_METADATA_KEYS = [
        'type',
        'user_id',
        'reference_code',
        'deposit_id',
        'site_id',
    ];

    /**
     * Store ids and type only — never the full Stripe Event (card/customer dumps).
     *
     * @return array<string, mixed>
     */
    public static function stripe(object|array $event): array
    {
        $id = self::scalar($event, 'id');
        $type = self::scalar($event, 'type');
        $created = self::scalar($event, 'created');

        $data = is_array($event) ? ($event['data'] ?? null) : ($event->data ?? null);
        $object = is_array($data) ? ($data['object'] ?? null) : ($data->object ?? null);

        $redacted = array_filter([
            'id' => $id,
            'type' => $type,
            'created' => $created,
            'object_id' => self::scalar($object, 'id'),
        ], fn ($value) => $value !== null && $value !== '');

        $metadata = self::stripeMetadata($object);
        if ($metadata !== []) {
            $redacted['metadata'] = $metadata;
        }

        return $redacted;
    }

    /**
     * Store ids and event type only — never payer/resource dumps.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function paypal(array $event): array
    {
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];

        return array_filter([
            'id' => isset($event['id']) && is_scalar($event['id']) ? (string) $event['id'] : null,
            'event_type' => isset($event['event_type']) && is_scalar($event['event_type']) ? (string) $event['event_type'] : null,
            'create_time' => isset($event['create_time']) && is_scalar($event['create_time']) ? (string) $event['create_time'] : null,
            'resource_id' => isset($resource['id']) && is_scalar($resource['id']) ? (string) $resource['id'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, scalar>
     */
    private static function stripeMetadata(mixed $object): array
    {
        $raw = is_array($object)
            ? ($object['metadata'] ?? null)
            : (is_object($object) ? ($object->metadata ?? null) : null);

        if ($raw === null) {
            return [];
        }

        if (is_object($raw) && method_exists($raw, 'toArray')) {
            $raw = $raw->toArray();
        } elseif (is_object($raw)) {
            $raw = (array) $raw;
        }

        if (! is_array($raw)) {
            return [];
        }

        $kept = [];
        foreach (self::STRIPE_METADATA_KEYS as $key) {
            if (! array_key_exists($key, $raw) || ! is_scalar($raw[$key]) || $raw[$key] === '') {
                continue;
            }
            $kept[$key] = $raw[$key];
        }

        return $kept;
    }

    private static function scalar(mixed $source, string $key): mixed
    {
        if (is_array($source) && array_key_exists($key, $source) && is_scalar($source[$key])) {
            return $source[$key];
        }

        if (is_object($source) && isset($source->{$key}) && is_scalar($source->{$key})) {
            return $source->{$key};
        }

        return null;
    }
}
