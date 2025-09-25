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

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    public function register_routes(): void
    {
        register_rest_route('pbr/v1', '/live', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'get_live'],
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
        $latest = $this->store->getLatestShow();

        return new WP_REST_Response([
            'is_live' => false,
            'show' => $latest ? $this->formatShow($latest) : null,
        ]);
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

    protected function formatShow(array $show): array
    {
        $formatted = $show;
        unset($formatted['_ft']);

        return $formatted;
    }
}
