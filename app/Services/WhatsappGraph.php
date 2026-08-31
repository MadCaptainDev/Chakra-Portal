<?php

namespace App\Services;

use App\Models\WhatsappSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The low-level conversation with Meta's Graph API.
 *
 * Everything that talks to Meta goes through here so that the token, the API
 * version and -- most importantly -- the error handling are decided once.
 * WhatsappSender is the meaning; this is the plumbing.
 */
class WhatsappGraph
{
    /** Meta answers quickly or not at all. */
    private const TIMEOUT_SECONDS = 15;

    public function __construct(private readonly WhatsappSetting $settings) {}

    public static function make(): self
    {
        return new self(WhatsappSetting::current());
    }

    public function isConfigured(): bool
    {
        return filled($this->settings->access_token);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        return $this->send('post', $path, $payload);
    }

    /**
     * Meta's DELETE endpoints (e.g. removing a template by name) take their
     * arguments as query parameters, not a JSON body -- unlike post(), so
     * this does not go through send(): Laravel's ->delete($url, $data) puts
     * $data in the request body, which Meta silently ignores on a DELETE.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function delete(string $path, array $query = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'WhatsApp is not configured: set the access token in Settings -> WhatsApp.'
            );
        }

        $url = $this->url($path).($query !== [] ? '?'.http_build_query($query) : '');

        $response = Http::withToken($this->settings->access_token)
            ->timeout(self::TIMEOUT_SECONDS)
            ->delete($url);

        if ($response->failed()) {
            $this->throwFrom($response, $path);
        }

        return $response->json() ?? [];
    }

    /**
     * Uploads a file ahead of sending it as a WhatsApp document/image/etc --
     * returns the media id a `document`/`image` message then references.
     *
     * Multipart, not JSON, so this bypasses send() entirely rather than
     * trying to make one method do both shapes: Meta's media endpoint wants
     * `messaging_product` and `type` as ordinary form fields alongside the
     * attached file, and asJson() (send()'s own Content-Type) would be
     * actively wrong here.
     *
     * @return string the media id
     */
    public function uploadMedia(string $path, string $contents, string $filename, string $mimeType): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'WhatsApp is not configured: set the access token in Settings -> WhatsApp.'
            );
        }

        $response = Http::withToken($this->settings->access_token)
            ->timeout(self::TIMEOUT_SECONDS)
            ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
            ->post($this->url($path), [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

        if ($response->failed()) {
            $this->throwFrom($response, $path);
        }

        $mediaId = $response->json('id');

        if (blank($mediaId)) {
            throw new RuntimeException('Meta accepted the media upload but returned no media id.');
        }

        return $mediaId;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'WhatsApp is not configured: set the access token in Settings -> WhatsApp.'
            );
        }

        $response = Http::withToken($this->settings->access_token)
            ->timeout(self::TIMEOUT_SECONDS)
            ->asJson()
            ->{$method}($this->url($path), $data);

        if ($response->failed()) {
            $this->throwFrom($response, $path);
        }

        return $response->json() ?? [];
    }

    public function url(string $path): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s',
            $this->settings->api_version ?: 'v22.0',
            ltrim($path, '/')
        );
    }

    /**
     * Meta's errors are the useful part of this integration and are surfaced
     * verbatim: "Recipient phone number not in allowed list" and "Unsupported
     * get request" are each one specific fix, and flattening them into
     * "request failed" throws that away.
     */
    private function throwFrom(Response $response, string $path): never
    {
        $error = $response->json('error', []);

        $message = trim(sprintf(
            '%s%s',
            $error['message'] ?? 'Graph API request failed with HTTP '.$response->status(),
            isset($error['error_data']['details']) ? ' — '.$error['error_data']['details'] : ''
        ));

        Log::error('WhatsApp Graph request failed.', [
            'path' => $path,
            'status' => $response->status(),
            'error' => $error ?: $response->body(),
        ]);

        throw new RuntimeException($message);
    }
}
