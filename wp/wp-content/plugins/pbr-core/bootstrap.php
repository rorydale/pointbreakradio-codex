<?php

namespace PBR\Core;

use DateTimeImmutable;
use PBR\Core\Includes\Api;

class Store
{
    /** @var string */
    protected $dataFile;

    /** @var array|null */
    protected $payload;

    public function __construct(string $dataFile)
    {
        $this->dataFile = $dataFile;
    }

    public function getShows(?int $limit = null, int $offset = 0): array
    {
        $collection = $this->allShows();
        $sliced = $collection;

        if ($offset > 0) {
            $sliced = array_slice($sliced, $offset);
        }

        if (null !== $limit) {
            $sliced = array_slice($sliced, 0, $limit);
        }

        return [
            'items' => $sliced,
            'total' => count($collection),
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    public function getLatestShow(): ?array
    {
        $shows = $this->allShows();
        return $shows[0] ?? null;
    }

    public function getShowBySlug(string $slug): ?array
    {
        foreach ($this->allShows() as $show) {
            if ($show['slug'] === $slug) {
                return $show;
            }
        }

        return null;
    }

    public function searchShows(string $term, int $limit = 10): array
    {
        $term = strtolower($term);
        $results = [];

        foreach ($this->allShows() as $show) {
            $haystack = strtolower($show['_ft'] ?? $this->deriveSearchField($show));
            $score = $this->scoreMatch($term, $haystack, $show);

            if ($score <= 0) {
                continue;
            }

            $results[] = [
                'show' => $show,
                'score' => $score,
            ];
        }

        usort($results, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return self::timestampFromShow($b['show']) <=> self::timestampFromShow($a['show']);
            }

            return $b['score'] <=> $a['score'];
        });

        return array_slice($results, 0, max(1, $limit));
    }

    public function recommend(array $input, int $limit = 3): array
    {
        $normalized = $this->normalizeRecommendationInput($input);
        $recommendations = [];

        foreach ($this->allShows() as $show) {
            $score = 0.0;

            if (null !== $normalized['genre'] && in_array($normalized['genre'], $show['tags'], true)) {
                $score += 3.5;
            }

            if (null !== $normalized['year'] && null !== $show['year']) {
                $delta = abs($show['year'] - $normalized['year']);
                $score += max(0, 3 - min($delta, 3));
            }

            foreach ($show['tracks'] as $track) {
                if ($normalized['artist'] && $this->isEqual($normalized['artist'], $track['artist'] ?? '')) {
                    $score += 4.0;
                }

                if ($normalized['song'] && $this->isEqual($normalized['song'], $track['title'] ?? '')) {
                    $score += 3.5;
                }

                if ($normalized['album'] && $this->isEqual($normalized['album'], $track['album'] ?? '')) {
                    $score += 2.0;
                }

                if ($normalized['genre'] && isset($track['mood']) && $this->isEqual($normalized['genre'], $track['mood'])) {
                    $score += 1.0;
                }
            }

            if ($score > 0) {
                $recommendations[] = [
                    'show' => $show,
                    'score' => round($score, 2),
                ];
            }
        }

        usort($recommendations, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return self::timestampFromShow($b['show']) <=> self::timestampFromShow($a['show']);
            }

            return $b['score'] <=> $a['score'];
        });

        return [
            'input' => $normalized,
            'items' => array_slice($recommendations, 0, max(1, $limit)),
        ];
    }

    public function buildBundle(int $minutes): array
    {
        $targetSeconds = max(60, $minutes * 60);
        $shows = $this->allShows();

        usort($shows, static function (array $a, array $b): int {
            return ($b['duration_seconds'] ?? 0) <=> ($a['duration_seconds'] ?? 0);
        });

        $bundle = [];
        $accumulated = 0;

        foreach ($shows as $show) {
            if ($accumulated >= $targetSeconds) {
                break;
            }

            $bundle[] = $show;
            $accumulated += (int) ($show['duration_seconds'] ?? 0);
        }

        return [
            'target_seconds' => $targetSeconds,
            'total_seconds' => $accumulated,
            'remaining_seconds' => max(0, $targetSeconds - $accumulated),
            'items' => $bundle,
        ];
    }

    protected function allShows(): array
    {
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $payload = $this->loadPayload();
        $shows = $payload['shows'] ?? [];

        $normalized = [];
        foreach ($shows as $show) {
            $normalized[] = $this->normalizeShow($show);
        }

        usort($normalized, static function (array $a, array $b): int {
            return self::timestampFromShow($b) <=> self::timestampFromShow($a);
        });

        $cache = $normalized;

        return $cache;
    }

    protected function loadPayload(): array
    {
        if (null !== $this->payload) {
            return $this->payload;
        }

        if (! file_exists($this->dataFile)) {
            $this->payload = ['shows' => []];
            return $this->payload;
        }

        $contents = file_get_contents($this->dataFile);

        if (false === $contents) {
            $this->payload = ['shows' => []];
            return $this->payload;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            $decoded = ['shows' => []];
        }

        $this->payload = $decoded;

        return $this->payload;
    }

    protected function normalizeShow(array $show): array
    {
        $defaults = [
            'slug' => '',
            'title' => '',
            'description' => '',
            'mixcloud_url' => '',
            'mixcloud_embed_url' => '',
            'published_at' => null,
            'duration_seconds' => 0,
            'hero_image' => '',
            'year' => null,
            'tags' => [],
            'tracks' => [],
            '_ft' => null,
        ];

        $show = array_merge($defaults, $show);

        if (! is_array($show['tags'])) {
            $show['tags'] = array_values(array_filter((array) $show['tags']));
        }

        if (! is_array($show['tracks'])) {
            $show['tracks'] = [];
        } else {
            $trackDefaults = [
                'title' => '',
                'artist' => '',
                'album' => '',
                'duration_seconds' => null,
                'mood' => null,
                'order' => null,
                'tags' => [],
            ];

            $normalizedTracks = [];
            foreach ($show['tracks'] as $track) {
                if (! is_array($track)) {
                    continue;
                }

                $track = array_merge($trackDefaults, $track);

                if (! is_array($track['tags'])) {
                    $track['tags'] = array_values(array_filter((array) $track['tags']));
                }

                $normalizedTracks[] = $track;
            }

            $show['tracks'] = $normalizedTracks;
        }

        usort($show['tracks'], static function (array $a, array $b): int {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });

        if (! $show['_ft']) {
            $show['_ft'] = $this->deriveSearchField($show);
        }

        return $show;
    }

    protected static function timestampFromShow(array $show): int
    {
        $value = $show['published_at'] ?? null;

        if (! $value) {
            return 0;
        }

        try {
            $dt = new DateTimeImmutable($value);
            return (int) $dt->format('U');
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function deriveSearchField(array $show): string
    {
        $parts = [
            $show['title'] ?? '',
            $show['description'] ?? '',
            $show['mixcloud_url'] ?? '',
            implode(' ', $show['tags'] ?? []),
        ];

        foreach ($show['tracks'] ?? [] as $track) {
            if (! is_array($track)) {
                continue;
            }

            $parts[] = $track['title'] ?? '';
            $parts[] = $track['artist'] ?? '';
            $parts[] = $track['album'] ?? '';
            if (! empty($track['tags'])) {
                $parts[] = implode(' ', (array) $track['tags']);
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    }

    protected function scoreMatch(string $needle, string $haystack, array $show): float
    {
        if ('' === $needle) {
            return 0.0;
        }

        if (false === strpos($haystack, $needle)) {
            return 0.0;
        }

        $score = substr_count($haystack, $needle) * 2;
        $score += (false !== strpos(strtolower($show['title']), $needle)) ? 3 : 0;
        $score += (false !== strpos(strtolower(implode(' ', $show['tags'])), $needle)) ? 1.5 : 0;

        return round($score, 2);
    }

    protected function normalizeRecommendationInput(array $input): array
    {
        $normalized = [
            'artist' => $this->sanitizeField($input['artist'] ?? null),
            'song' => $this->sanitizeField($input['song'] ?? null),
            'album' => $this->sanitizeField($input['album'] ?? null),
            'genre' => $this->sanitizeField($input['genre'] ?? null),
            'year' => null,
        ];

        if (isset($input['year'])) {
            $year = (int) $input['year'];
            if ($year > 1900 && $year < 2100) {
                $normalized['year'] = $year;
            }
        }

        return $normalized;
    }

    protected function sanitizeField(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : strtolower($value);
    }

    protected function isEqual(string $expected, string $actual): bool
    {
        return strtolower(trim($actual)) === strtolower(trim($expected));
    }
}

function bootstrap(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    require_once __DIR__ . '/includes/Api.php';

    $root_dir = dirname(__DIR__, 4);
    if (! $root_dir || ! is_dir($root_dir . '/data')) {
        $root_dir = dirname(__DIR__, 3);
    }

    $data_file = $root_dir . '/data/shows.json';
    $live_file = $root_dir . '/data/live.json';

    $store = new Store($data_file);
    $api = new Api($store, $live_file);

    add_action('rest_api_init', [$api, 'register_routes']);

    $bootstrapped = true;
}
