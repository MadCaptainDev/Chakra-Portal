<?php

namespace App\Services\Instagram;

use App\Models\AiSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The video of somebody else's public reel, through Instagram's official
 * business discovery (graph.facebook.com): with a Facebook-login token for
 * the studio's own Instagram business account, it lists any business or
 * creator account's posts, video links included. The reel is found by its
 * code among the account's recent posts.
 *
 * Who posted a reel is not in its link, so it is asked of Instagram's oEmbed
 * (same token) and otherwise has to be given. Personal accounts are not
 * reachable this way, and reels with licensed music come without their video.
 */
class PublicReelFinder
{
    private const GRAPH = 'https://graph.facebook.com/v23.0';

    /** 6 pages of 50: the account's last 300 posts. */
    private const PAGES = 6;

    public function ready(): bool
    {
        $s = AiSetting::current();

        return filled($s->meta_discovery_token) && filled($s->meta_discovery_ig_id);
    }

    /**
     * Check a pasted token and find the studio's Instagram business account
     * behind it (through its Facebook Page).
     *
     * @return array{id: string, username: string}
     */
    public function connect(string $token): array
    {
        $pages = $this->get('me/accounts', $token, ['fields' => 'name,instagram_business_account{id,username}', 'limit' => 50])['data'] ?? [];
        foreach ($pages as $page) {
            if ($ig = $page['instagram_business_account'] ?? null) {
                return ['id' => (string) $ig['id'], 'username' => (string) ($ig['username'] ?? '')];
            }
        }

        throw new RuntimeException('That token works, but none of its Facebook Pages has an Instagram business account linked. '
            .'Link Chakra\'s Instagram to its Facebook Page (Instagram → Settings → Account type and tools / Meta Business Suite), then make the token again.');
    }

    /** @return array{video: string, username: string, caption: ?string} */
    public function find(string $shortcode, ?string $username = null): array
    {
        $s = AiSetting::current();
        if (! $this->ready()) {
            throw new RuntimeException('Public reels need Chakra\'s Instagram connected through Facebook (Settings → Assistant → Public reels). Until then, upload the video.');
        }
        $token = $s->meta_discovery_token;
        $username = ltrim(trim((string) $username), '@') ?: $this->author($shortcode, $token)
            ?? throw new RuntimeException('Instagram did not say who posted that reel. Give the account\'s username too (e.g. @riyamakeover_artistry).');

        $after = null;
        for ($page = 0; $page < self::PAGES; $page++) {
            $media = 'media.limit(50)'.($after ? '.after('.$after.')' : '').'{permalink,media_url,media_product_type,caption}';
            try {
                $found = $this->get($s->meta_discovery_ig_id, $token, ['fields' => "business_discovery.username({$username}){{$media}}"]);
            } catch (RuntimeException $e) {
                throw new RuntimeException(str_contains($e->getMessage(), 'Invalid user id') || str_contains($e->getMessage(), 'Cannot find User')
                    ? "@{$username} is a personal account (or does not exist): Instagram only opens business and creator accounts. Upload the video instead."
                    : $e->getMessage());
            }
            $posts = data_get($found, 'business_discovery.media.data', []);
            foreach ($posts as $post) {
                if (str_contains((string) ($post['permalink'] ?? ''), '/'.$shortcode.'/')) {
                    return [
                        'video' => $post['media_url'] ?? throw new RuntimeException('Instagram does not hand over the video for that reel (it usually uses licensed music). Upload the video instead.'),
                        'username' => $username,
                        'caption' => $post['caption'] ?? null,
                    ];
                }
            }
            $after = data_get($found, 'business_discovery.media.paging.cursors.after');
            if (! $after || count($posts) === 0) {
                break;
            }
        }

        throw new RuntimeException("That reel is not among @{$username}'s last 300 posts. Upload the video instead.");
    }

    /** Who posted it, from Instagram's oEmbed -- null when the app is not allowed oEmbed. */
    private function author(string $shortcode, string $token): ?string
    {
        try {
            $embed = $this->get('instagram_oembed', $token, ['url' => "https://www.instagram.com/reel/{$shortcode}/", 'fields' => 'author_name']);
        } catch (RuntimeException) {
            return null;
        }

        return filled($embed['author_name'] ?? null) ? (string) $embed['author_name'] : null;
    }

    /** @return array<string, mixed> */
    private function get(string $path, string $token, array $query): array
    {
        $response = Http::timeout(30)->get(self::GRAPH.'/'.ltrim($path, '/'), $query + ['access_token' => $token]);

        return $this->result($response);
    }

    /** @return array<string, mixed> */
    private function result(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException('Instagram: '.mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }

        return (array) $response->json();
    }
}
