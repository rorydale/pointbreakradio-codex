<?php

namespace PBR\Core\Includes;

use PBR\Core\Store;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Api
{
    /** @var Store */
    protected $store;

    /** @var string|null */
    protected $liveFile;

    public function __construct(Store $store, ?string $liveFile = null)
    {
        $this->store = $store;
        $this->liveFile = $liveFile;
    }

    public function register_routes(): void
    {
        register_rest_route('pbr/v1', '/live', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'get_live'],
        ]);

        register_rest_route('pbr/v1', '/live', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'update_live'],
        ]);

        register_rest_route('pbr/v1', '/shows', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'get_shows'],
            'args' => [
                'limit' => [
                    'validate_callback' => static function ($param): bool {
                        return null === $param || (is_numeric($param) && $param > 0);
                    },
                ],
                'offset' => [
                    'validate_callback' => static function ($param): bool {
                        return null === $param || (is_numeric($param) && $param >= 0);
                    },
                ],
            ],
        ]);

        register_rest_route('pbr/v1', '/show/(?P<slug>[a-z0-9\-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'get_show'],
        ]);

        register_rest_route('pbr/v1', '/search', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'search'],
            'args' => [
                'q' => [
                    'required' => true,
                    'validate_callback' => static function ($param): bool {
                        return is_string($param) && '' !== trim($param);
                    },
                ],
            ],
        ]);

        register_rest_route('pbr/v1', '/recommend', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'recommend'],
        ]);

        register_rest_route('pbr/v1', '/bundle', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'bundle'],
        ]);
    }

    public function get_live(WP_REST_Request $request): WP_REST_Response
    {
        $record = $this->readLivePayload();
        $resolved = $this->resolveLiveRecord($record);

        return new WP_REST_Response($resolved);
    }

    public function get_shows(WP_REST_Request $request)
    {
        $limit = $request->get_param('limit');
        $offset = $request->get_param('offset');

        $limit = null === $limit ? 12 : min(50, max(1, (int) $limit));
        $offset = null === $offset ? 0 : max(0, (int) $offset);

        $result = $this->store->getShows($limit, $offset);
        $items = array_map([$this, 'formatShow'], $result['items']);

        return new WP_REST_Response([
            'items' => $items,
            'total' => $result['total'],
            'limit' => $result['limit'],
            'offset' => $result['offset'],
        ]);
    }

    public function get_show(WP_REST_Request $request)
    {
        $slug = $request->get_param('slug');
        $show = $this->store->getShowBySlug($slug);

        if (! $show) {
            return new WP_Error('pbr_show_not_found', 'Show not found', ['status' => 404]);
        }

        return new WP_REST_Response($this->formatShow($show));
    }

    public function search(WP_REST_Request $request)
    {
        $term = strtolower(trim((string) $request->get_param('q')));
        if ('' === $term) {
            return new WP_Error('pbr_search_term_required', 'Search term is required.', ['status' => 400]);
        }

        $results = $this->store->searchShows($term, 12);

        $items = array_map(function (array $match): array {
            return [
                'show' => $this->formatShow($match['show']),
                'score' => $match['score'],
            ];
        }, $results);

        return new WP_REST_Response([
            'query' => $term,
            'items' => $items,
        ]);
    }

    public function recommend(WP_REST_Request $request)
    {
        $params = $request->get_json_params() ?? [];

        if (! is_array($params)) {
            return new WP_Error('pbr_invalid_payload', 'Invalid recommendation payload.', ['status' => 400]);
        }

        $recommendations = $this->store->recommend($params);

        $items = array_map(function (array $entry): array {
            return [
                'show' => $this->formatShow($entry['show']),
                'score' => $entry['score'],
            ];
        }, $recommendations['items']);

        return new WP_REST_Response([
            'input' => $recommendations['input'],
            'items' => $items,
        ]);
    }

    public function bundle(WP_REST_Request $request)
    {
        $params = $request->get_json_params() ?? [];

        if (! is_array($params)) {
            return new WP_Error('pbr_invalid_payload', 'Invalid bundle payload.', ['status' => 400]);
        }

        $minutes = isset($params['minutes']) ? (int) $params['minutes'] : 0;

        if ($minutes <= 0) {
            return new WP_Error('pbr_invalid_minutes', 'Bundle minutes must be a positive integer.', ['status' => 400]);
        }

        $bundle = $this->store->buildBundle($minutes);

        $items = array_map([$this, 'formatShow'], $bundle['items']);

        return new WP_REST_Response([
            'target_seconds' => $bundle['target_seconds'],
            'total_seconds' => $bundle['total_seconds'],
            'remaining_seconds' => $bundle['remaining_seconds'],
            'items' => $items,
        ]);
    }

    public function update_live(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();

        if (! is_array($payload)) {
            return new WP_Error('pbr_live_invalid_payload', 'Invalid live payload.', ['status' => 400]);
        }

        $secret = $this->resolveLiveSecret();
        if (! $secret) {
            return new WP_Error('pbr_live_secret_missing', 'Live secret not configured.', ['status' => 501]);
        }

        $provided = $request->get_header('x-pbr-secret');
        if (! $provided && isset($payload['secret'])) {
            $provided = (string) $payload['secret'];
        }
        if (! $provided && $request->get_param('secret')) {
            $provided = (string) $request->get_param('secret');
        }

        if (! $provided || ! hash_equals($secret, (string) $provided)) {
            return new WP_Error('pbr_live_forbidden', 'Invalid live secret.', ['status' => 403]);
        }

        if (! array_key_exists('is_live', $payload)) {
            return new WP_Error('pbr_live_flag_required', 'The "is_live" flag is required.', ['status' => 400]);
        }

        $isLive = filter_var($payload['is_live'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if (null === $isLive) {
            return new WP_Error('pbr_live_flag_invalid', 'The "is_live" flag must be boolean.', ['status' => 400]);
        }

        $existing = $this->readLivePayload();
        $slug = null;
        if (array_key_exists('slug', $payload)) {
            $slug = is_string($payload['slug']) ? trim($payload['slug']) : null;
            if ($slug === '') {
                $slug = null;
            }
        } elseif (isset($existing['slug']) && is_string($existing['slug'])) {
            $slug = $existing['slug'];
        }

        $nowPlaying = null;
        if (array_key_exists('now_playing', $payload)) {
            $nowPlaying = is_string($payload['now_playing']) ? trim($payload['now_playing']) : null;
            if ($nowPlaying === '') {
                $nowPlaying = null;
            }
        } elseif (isset($existing['now_playing']) && is_string($existing['now_playing'])) {
            $nowPlaying = $existing['now_playing'];
        }

        $record = [
            'is_live' => $isLive,
            'updated_at' => gmdate('c'),
            'slug' => $slug,
            'now_playing' => $nowPlaying,
            'source' => isset($payload['source']) && is_string($payload['source'])
                ? trim($payload['source'])
                : ($existing['source'] ?? 'automation'),
        ];

        $this->writeLivePayload($record);

        $resolved = $this->resolveLiveRecord($record);

        return new WP_REST_Response($resolved);
    }

    protected function formatShow(array $show): array
    {
        $formatted = $show;
        unset($formatted['_ft']);

        return $formatted;
    }

    protected function resolveLiveRecord(array $record): array
    {
        $isLive = (bool) ($record['is_live'] ?? false);
        $slug = isset($record['slug']) && is_string($record['slug']) ? $record['slug'] : null;
        $show = null;

        if ($slug) {
            $show = $this->store->getShowBySlug($slug);
        }

        if (! $show) {
            $show = $this->store->getLatestShow();
        }

        $response = [
            'is_live' => $isLive,
            'updated_at' => $record['updated_at'] ?? null,
            'now_playing' => $record['now_playing'] ?? null,
            'source' => $record['source'] ?? 'archive',
            'show' => $show ? $this->formatShow($show) : null,
        ];

        if ($slug) {
            $response['slug'] = $slug;
        }

        return $response;
    }

    protected function readLivePayload(): array
    {
        if (! $this->liveFile || ! file_exists($this->liveFile)) {
            return [];
        }

        $raw = file_get_contents($this->liveFile);
        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function writeLivePayload(array $record): void
    {
        if (! $this->liveFile) {
            return;
        }

        $dir = dirname($this->liveFile);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        file_put_contents($this->liveFile, wp_json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function resolveLiveSecret(): ?string
    {
        if (defined('PBR_LIVE_SECRET') && PBR_LIVE_SECRET) {
            return (string) PBR_LIVE_SECRET;
        }

        $env = getenv('PBR_LIVE_SECRET');

        return $env ? (string) $env : null;
    }
}
