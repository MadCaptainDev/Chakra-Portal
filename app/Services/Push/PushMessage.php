<?php

namespace App\Services\Push;

/**
 * What one push notification says, before it is addressed to any device.
 *
 * Data-only by construction -- toPayload() has no `notification` key at
 * all, and there is nowhere in this class to add one. See PushSender's
 * docblock for why: this app does not load the Firebase SW SDK into
 * public/sw.js, so a `notification` key would arrive at a device and
 * display nothing, and Chrome would show its own generic "This site has
 * been updated in the background" instead of anything useful.
 */
final class PushMessage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url = '/',
        public readonly ?string $tag = null,
    ) {}

    /**
     * FCM's HTTP v1 `message.data` map. Every value cast to a string --
     * data values that are not strings are refused outright with a 400
     * INVALID_ARGUMENT, and an int or bool slipped in here is the kind of
     * bug that only shows up for one of several notification types.
     *
     * @return array<string, string>
     */
    public function toData(): array
    {
        return array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
        ], fn (?string $v) => $v !== null);
    }
}
