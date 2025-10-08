#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = $argv;
$useMixcloud = in_array('--mixcloud', $options, true);
$deleteMode = in_array('--delete', $options, true);

if ($deleteMode && ! in_array('--only=', $options, true)) {
    $hasOnly = false;
    foreach ($options as $arg) {
        if (str_starts_with($arg, '--only=')) {
            $hasOnly = true;
            break;
        }
    }
    if (! $hasOnly) {
        fwrite(STDERR, "--delete requires --only to specify which show(s) to remove.\n");
        exit(1);
    }
}

$onlyOption = null;
foreach ($options as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $onlyOption = substr($arg, 7);
        break;
    }
}

$onlySlugs = [];
if ($onlyOption) {
    $onlySlugs = array_values(array_filter(array_map(static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\.csv$/i', '', $value) ?? $value;
        return strtolower($value);
    }, explode(',', $onlyOption))));

    if (! $onlySlugs) {
        fwrite(STDERR, "No valid values provided for --only option.\n");
        exit(1);
    }
}

$onlySlugs = array_unique($onlySlugs);

$projectRoot = dirname(__DIR__);
$importDir   = $projectRoot . '/data/import';
$metaDir     = $importDir . '/meta';
$outputShows = $projectRoot . '/data/shows.json';
$outputLibrary = $projectRoot . '/data/library.json';
$cacheDir    = $projectRoot . '/data/cache/mixcloud';

$existingLibrary = file_exists($outputLibrary) ? json_decode((string) file_get_contents($outputLibrary), true) : null;
$existingLibraryShows = [];
if (is_array($existingLibrary['shows'] ?? null)) {
    foreach ($existingLibrary['shows'] as $existingShow) {
        if (! is_array($existingShow)) {
            continue;
        }
        $slug = strtolower((string) ($existingShow['slug'] ?? ''));
        if ($slug !== '') {
            $existingLibraryShows[$slug] = $existingShow;
        }

        $dateKey = strtolower((string) ($existingShow['date'] ?? ''));
        if ($dateKey !== '') {
            $existingLibraryShows[$dateKey] = $existingShow;
        }
    }
}

if (! is_dir($importDir)) {
    fwrite(STDERR, "Import directory not found: {$importDir}\n");
    exit(1);
}

if ($useMixcloud && ! is_dir($cacheDir)) {
    if (! mkdir($cacheDir, 0775, true) && ! is_dir($cacheDir)) {
        fwrite(STDERR, "Failed to create Mixcloud cache directory at {$cacheDir}\n");
        exit(1);
    }
}

$csvFiles = glob($importDir . '/*.csv');
if (! $csvFiles) {
    fwrite(STDOUT, "No CSV files found in {$importDir}.\n");
    exit(0);
}

$mixcloudClient = $useMixcloud ? new MixcloudClient('pointbreakradio', $cacheDir) : null;
$mixcloudEnricher = $mixcloudClient ? new MixcloudEnricher($mixcloudClient) : null;

$artists = [];
$artistMap = [];
$albums = [];
$albumMap = [];
$tracks = [];
$trackMap = [];
$shows = [];
$showTracks = [];

foreach ($csvFiles as $csvPath) {
    $baseName = basename($csvPath, '.csv');
    $slugDate = extractDateSlug($baseName);
    $normalizedSlugDate = strtolower($slugDate ?? '');

    if ($deleteMode && $onlySlugs) {
        $normalizedName = strtolower($baseName);
        if (in_array($normalizedSlugDate, $onlySlugs, true) || in_array($normalizedName, $onlySlugs, true)) {
            unset($existingLibraryShows[$normalizedSlugDate], $existingLibraryShows[$normalizedName]);
            continue;
        }
    }

    if (! $slugDate) {
        fwrite(STDERR, "Skipping {$csvPath}: unable to determine date slug.\n");
        continue;
    }

    $file = new SplFileObject($csvPath);
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

    $headers = [];
    $rowIndex = 0;

    foreach ($file as $row) {
        if ($row === [null] || $row === false) {
            continue;
        }

        $row = array_map('trim', $row);

        if ($rowIndex === 0) {
            $headers = normaliseHeaders($row);
            $rowIndex++;
            continue;
        }

        $rowIndex++;
        $data = mapRow($headers, $row);

        if (! $data['artist'] || ! $data['track']) {
            continue;
        }

        $artistId = ensureArtist($data['artist'], $artists, $artistMap);
        $albumId  = $data['album'] ? ensureAlbum($data['album'], $albums, $albumMap) : null;

        $trackKey = strtolower($artistId . '::' . $data['track']);
        if (! isset($trackMap[$trackKey])) {
            $trackId = 'track:' . substr(sha1($trackKey), 0, 12);
            $tracks[$trackId] = [
                'id' => $trackId,
                'slug' => slugify($data['track']),
                'title' => $data['track'],
                'artist_id' => $artistId,
                'album_id' => $albumId,
                'duration_seconds' => $data['duration'],
                'tags' => $data['tags'],
            ];
            $trackMap[$trackKey] = $trackId;
        }

        $trackId = $trackMap[$trackKey];
        $showId  = 'show:' . $slugDate;

        $showTracks[] = [
            'show_id' => $showId,
            'track_id' => $trackId,
            'order' => $data['order'],
            'tags' => $data['tags'],
        ];
    }

    $meta = loadMeta($metaDir, $slugDate);

    $showSlug = $meta['slug'] ?? $slugDate;
    $showId = 'show:' . $slugDate;
    $publishedAt = $meta['published_at'] ?? ($slugDate ? $slugDate . 'T00:00:00Z' : null);
    $year = $meta['year'] ?? ($slugDate ? (int) substr($slugDate, 0, 4) : null);

    $normalizedShowSlug = strtolower($showSlug);
    if ($deleteMode && $onlySlugs && (in_array($normalizedShowSlug, $onlySlugs, true) || in_array($normalizedSlugDate, $onlySlugs, true))) {
        continue;
    }

    $mixcloudPath = $meta['mixcloud_path'] ?? $showSlug;
    $mixcloudUrl = $meta['mixcloud_url'] ?? buildMixcloudUrl($mixcloudPath);
    $mixcloudEmbedUrl = $meta['mixcloud_embed_url'] ?? buildMixcloudEmbedUrl($mixcloudPath);

    $normalizedSlugDate = strtolower($slugDate);
    $normalizedShowSlug = strtolower($showSlug);
    $shouldMixcloud = $mixcloudEnricher && (! $onlySlugs || in_array($normalizedSlugDate, $onlySlugs, true) || in_array($normalizedShowSlug, $onlySlugs, true));

    $showRecord = [
        'id' => $showId,
        'slug' => $showSlug,
        'date' => $slugDate,
        'title' => $meta['title'] ?? ('Point Break Radio ' . $slugDate),
        'description' => $meta['description'] ?? '',
        'mixcloud_url' => $mixcloudUrl,
        'mixcloud_embed_url' => $mixcloudEmbedUrl,
        'published_at' => $publishedAt,
        'duration_seconds' => $meta['duration_seconds'] ?? null,
        'hero_image' => $meta['hero_image'] ?? '',
        'tags' => isset($meta['tags']) && is_array($meta['tags']) ? array_values(array_unique($meta['tags'])) : [],
        'year' => $year,
        '_enriched' => false,
    ];

    if ($shouldMixcloud) {
        $showRecord = $mixcloudEnricher->enrich($showRecord, $mixcloudPath);
    }

    $normalizedKey = strtolower($showSlug);
    if (isset($existingLibraryShows[$normalizedKey])) {
        $showRecord = mergeExistingShow($showRecord, $existingLibraryShows[$normalizedKey]);
    } elseif (isset($existingLibraryShows[$normalizedSlugDate])) {
        $showRecord = mergeExistingShow($showRecord, $existingLibraryShows[$normalizedSlugDate]);
    }

    $shows[$showId] = $showRecord;
}

if (! $shows && ! $deleteMode) {
    fwrite(STDOUT, "No show data generated. Nothing to write.\n");
    exit(0);
}

$newLibraryData = [
    'generated_at' => gmdate('c'),
    'artists' => array_values($artists),
    'albums' => array_values($albums),
    'tracks' => array_values($tracks),
    'shows' => array_values($shows),
    'show_tracks' => array_values($showTracks),
];

$library = mergeLibraryData($existingLibrary, $newLibraryData, $onlySlugs, $deleteMode);

usort($library['show_tracks'], static function (array $a, array $b): int {
    if (($a['show_id'] ?? '') === ($b['show_id'] ?? '')) {
        return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
    }

    return strcmp((string) ($a['show_id'] ?? ''), (string) ($b['show_id'] ?? ''));
});

usort($library['tracks'], static function (array $a, array $b): int {
    return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
});

usort($library['artists'], static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

usort($library['albums'], static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

usort($library['shows'], static function (array $a, array $b): int {
    return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
});

$library['generated_at'] = gmdate('c');

writeJson($outputLibrary, $library);

$denormalised = buildDenormalisedShowsFromLibrary($library);

writeJson($outputShows, ['shows' => $denormalised]);

$mixcloudNotice = $useMixcloud
    ? 'Mixcloud enrichment enabled (cached responses stored in data/cache/mixcloud).'
    : 'Mixcloud enrichment skipped. Pass --mixcloud to enable remote lookups.';

$actionVerb = $deleteMode ? 'Processed' : 'Imported';
fwrite(STDOUT, sprintf(
    "%s %d shows → %s\n%s\n",
    $actionVerb,
    count($denormalised),
    $outputShows,
    $mixcloudNotice
));

// Helper functions
function normaliseHeaders(array $headers): array
{
    return array_map(static function ($header) {
        $header = strtolower(trim((string) $header));
        return preg_replace('/[^a-z0-9]+/', '_', $header);
    }, $headers);
}

function mapRow(array $headers, array $row): array
{
    $data = [];
    foreach ($headers as $index => $header) {
        $data[$header] = $row[$index] ?? null;
    }

    $tags = [];
    if (! empty($data['tags'])) {
        $segments = preg_split('/[|,]/', (string) $data['tags']);
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment !== '') {
                $tags[] = strtolower($segment);
            }
        }
    }

    return [
        'date' => trim((string) ($data['date'] ?? '')),
        'order' => (int) ($data['order'] ?? 0),
        'artist' => trim((string) ($data['artist'] ?? '')),
        'track' => trim((string) ($data['track'] ?? '')),
        'album' => trim((string) ($data['album'] ?? '')),
        'duration' => isset($data['duration']) && $data['duration'] !== '' ? (int) $data['duration'] : null,
        'tags' => $tags,
    ];
}

function ensureArtist(string $name, array &$artists, array &$map): string
{
    $key = strtolower($name);
    if (! isset($map[$key])) {
        $slug = slugify($name);
        $id = 'artist:' . $slug;
        $artists[] = [
            'id' => $id,
            'slug' => $slug,
            'name' => $name,
        ];
        $map[$key] = $id;
    }

    return $map[$key];
}

function ensureAlbum(string $name, array &$albums, array &$map): string
{
    $key = strtolower($name);
    if (! isset($map[$key])) {
        $slug = slugify($name);
        $id = 'album:' . $slug;
        $albums[] = [
            'id' => $id,
            'slug' => $slug,
            'name' => $name,
        ];
        $map[$key] = $id;
    }

    return $map[$key];
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim($value, '-') ?: 'n-a';
}

function extractDateSlug(string $input): ?string
{
    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $input, $matches)) {
        return $matches[1];
    }

    return null;
}

function loadMeta(string $metaDir, string $slugDate): array
{
    $path = rtrim($metaDir, '/') . '/' . $slugDate . '.json';
    if (! file_exists($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function buildMixcloudUrl(string $path): string
{
    $path = trim($path, '/');
    return 'https://www.mixcloud.com/pointbreakradio/' . $path . '/';
}

function buildMixcloudEmbedUrl(string $path): string
{
    $path = trim($path, '/');
    $feed = '/' . ltrim('pointbreakradio/' . $path . '/', '/');
    return 'https://www.mixcloud.com/widget/iframe/?hide_cover=1&mini=1&feed=' . rawurlencode($feed);
}

function writeJson(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($path, $json . "\n");
}

function buildFullText(array $show, array $tracks): string
{
    $parts = [
        $show['title'] ?? '',
        $show['description'] ?? '',
        $show['mixcloud_url'] ?? '',
        implode(' ', $show['tags'] ?? []),
    ];

    foreach ($tracks as $track) {
        $parts[] = $track['title'] ?? '';
        $parts[] = $track['artist'] ?? '';
        $parts[] = $track['album'] ?? '';
        if (! empty($track['tags'])) {
            $parts[] = implode(' ', (array) $track['tags']);
        }
    }

    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
}

function mergeExistingShow(array $show, array $existing): array
{
    $fields = ['description', 'mixcloud_url', 'mixcloud_embed_url', 'published_at', 'hero_image', 'duration_seconds', 'human_date'];
    foreach ($fields as $field) {
        if ((empty($show[$field]) || $show[$field] === '') && ! empty($existing[$field])) {
            $show[$field] = $existing[$field];
        }
    }

    if (empty($show['tags']) && ! empty($existing['tags']) && is_array($existing['tags'])) {
        $show['tags'] = $existing['tags'];
    }

    return $show;
}

function mergeLibraryData(?array $existingLibrary, array $newLibraryData, array $onlySlugs, bool $deleteMode): array
{
    $existingLibrary = is_array($existingLibrary) ? $existingLibrary : null;

    if (! $existingLibrary) {
        return [
            'generated_at' => $newLibraryData['generated_at'] ?? gmdate('c'),
            'artists' => array_values(mapById($newLibraryData['artists'] ?? [])),
            'albums' => array_values(mapById($newLibraryData['albums'] ?? [])),
            'tracks' => array_values(mapById($newLibraryData['tracks'] ?? [])),
            'shows' => array_values(mapById($newLibraryData['shows'] ?? [])),
            'show_tracks' => array_values($newLibraryData['show_tracks'] ?? []),
        ];
    }

    $removeSet = [];
    foreach ($onlySlugs as $value) {
        $normalized = strtolower(trim((string) $value));
        if ($normalized !== '') {
            $removeSet[$normalized] = true;
        }
    }

    $existingArtists = mapById($existingLibrary['artists'] ?? []);
    $existingAlbums = mapById($existingLibrary['albums'] ?? []);
    $existingTracks = mapById($existingLibrary['tracks'] ?? []);
    $existingShows = mapById($existingLibrary['shows'] ?? []);

    $newArtists = mapById($newLibraryData['artists'] ?? []);
    $newAlbums = mapById($newLibraryData['albums'] ?? []);
    $newTracks = mapById($newLibraryData['tracks'] ?? []);
    $newShows = mapById($newLibraryData['shows'] ?? []);

    foreach ($newArtists as $id => $artist) {
        $existingArtists[$id] = $artist;
    }

    foreach ($newAlbums as $id => $album) {
        $existingAlbums[$id] = $album;
    }

    foreach ($newTracks as $id => $track) {
        $existingTracks[$id] = $track;
    }

    $newShowIds = array_keys($newShows);
    $newShowIdSet = array_fill_keys($newShowIds, true);
    $removedShowIds = [];

    foreach ($existingShows as $id => $show) {
        if (isset($newShowIdSet[$id])) {
            unset($existingShows[$id]);
            continue;
        }

        $slug = strtolower((string) ($show['slug'] ?? ''));
        $date = strtolower((string) ($show['date'] ?? ''));

        if ($deleteMode && (($slug !== '' && isset($removeSet[$slug])) || ($date !== '' && isset($removeSet[$date])))) {
            $removedShowIds[$id] = true;
            unset($existingShows[$id]);
        }
    }

    foreach ($newShows as $id => $show) {
        $existingShows[$id] = $show;
    }

    $skipShowIds = $removedShowIds + $newShowIdSet;

    $finalShowTracks = [];
    foreach ($existingLibrary['show_tracks'] ?? [] as $record) {
        $showId = $record['show_id'] ?? null;
        if ($showId && isset($skipShowIds[$showId])) {
            continue;
        }

        $finalShowTracks[] = $record;
    }

    foreach ($newLibraryData['show_tracks'] ?? [] as $record) {
        $finalShowTracks[] = $record;
    }

    return [
        'generated_at' => $newLibraryData['generated_at'] ?? gmdate('c'),
        'artists' => array_values($existingArtists),
        'albums' => array_values($existingAlbums),
        'tracks' => array_values($existingTracks),
        'shows' => array_values($existingShows),
        'show_tracks' => $finalShowTracks,
    ];
}

function buildDenormalisedShowsFromLibrary(array $library): array
{
    $artistLookup = mapById($library['artists'] ?? []);
    $albumLookup = mapById($library['albums'] ?? []);
    $trackLookup = mapById($library['tracks'] ?? []);

    $denormalised = [];
    foreach ($library['shows'] ?? [] as $show) {
        $showTracksRecords = array_values(array_filter($library['show_tracks'] ?? [], static function (array $record) use ($show): bool {
            return isset($record['show_id'], $show['id']) && $record['show_id'] === $show['id'];
        }));

        usort($showTracksRecords, static function (array $a, array $b): int {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });

        $trackPayload = [];
        foreach ($showTracksRecords as $record) {
            $track = $trackLookup[$record['track_id']] ?? null;
            if (! $track) {
                continue;
            }

            $artist = $artistLookup[$track['artist_id']] ?? null;
            $album = $track['album_id'] ? ($albumLookup[$track['album_id']] ?? null) : null;

            $trackPayload[] = [
                'title' => $track['title'],
                'artist' => $artist['name'] ?? null,
                'album' => $album['name'] ?? null,
                'duration_seconds' => $track['duration_seconds'],
                'order' => $record['order'],
                'tags' => $track['tags'],
            ];
        }

        $denormalised[] = [
            'slug' => $show['slug'] ?? null,
            'title' => $show['title'] ?? null,
            'description' => $show['description'] ?? null,
            'mixcloud_url' => $show['mixcloud_url'] ?? null,
            'mixcloud_embed_url' => $show['mixcloud_embed_url'] ?? null,
            'published_at' => $show['published_at'] ?? null,
            'duration_seconds' => $show['duration_seconds'] ?? null,
            'hero_image' => $show['hero_image'] ?? null,
            'year' => $show['year'] ?? null,
            'tags' => $show['tags'] ?? [],
            'tracks' => $trackPayload,
            '_ft' => buildFullText($show, $trackPayload),
            '_enriched' => $show['_enriched'] ?? false,
        ];
    }

    return $denormalised;
}

function mapById(array $items): array
{
    $map = [];
    foreach ($items as $item) {
        if (isset($item['id'])) {
            $map[$item['id']] = $item;
        }
    }

    return $map;
}

class MixcloudClient
{
    private const BASE = 'https://api.mixcloud.com';
    private const USER_AGENT = 'PointBreakRadio Importer/0.1';
    private const CACHE_TTL = 86400;
    private float $lastRequest = 0.0;

    public function __construct(private string $profile, private string $cacheDir)
    {
    }

    public function fetchShow(string $slug): ?array
    {
        $path = sprintf('/%s/%s/', $this->profile, $slug);
        $content = $this->request($path, $slug . '-show.json');
        if (! $content) {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function fetchEmbedHtml(string $slug): ?string
    {
        $path = sprintf('/%s/%s/embed-html/', $this->profile, $slug);
        $content = $this->request($path, $slug . '-embed.html');
        if (! $content) {
            return null;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded['html'])) {
            return (string) $decoded['html'];
        }

        return $content;
    }

    private function request(string $path, string $cacheKey): ?string
    {
        $cacheFile = $this->cacheDir . '/' . $cacheKey;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $contents = file_get_contents($cacheFile);
            return $contents === false ? null : $contents;
        }

        $this->throttle();

        $url = self::BASE . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $this->lastRequest = microtime(true);

        if ($response === false || $status >= 400) {
            fwrite(STDERR, sprintf("Mixcloud request failed (%s): %s\n", $url, $err ?: 'HTTP ' . $status));
            return null;
        }

        file_put_contents($cacheFile, $response);

        return $response;
    }

    private function throttle(): void
    {
        if ($this->lastRequest <= 0) {
            return;
        }

        $elapsed = microtime(true) - $this->lastRequest;
        $minimumGap = 0.5;
        if ($elapsed < $minimumGap) {
            usleep((int) (($minimumGap - $elapsed) * 1_000_000));
        }
    }
}

class MixcloudEnricher
{
    public function __construct(private MixcloudClient $client)
    {
    }

    public function enrich(array $show, string $mixcloudPath): array
    {
        $needs = $this->needsEnrichment($show);
        if (! $needs) {
            return $show;
        }

        $data = $this->client->fetchShow($mixcloudPath);
        if ($data) {
            $show = $this->mergeShowData($show, $data);
        }

        if (empty($show['mixcloud_embed_url'])) {
            $embedHtml = $this->client->fetchEmbedHtml($mixcloudPath);
            $embedSrc = $this->extractEmbedSrc($embedHtml);
            if ($embedSrc) {
                $show['mixcloud_embed_url'] = $embedSrc;
                $show['_enriched'] = true;
            }
        }

        return $show;
    }

    private function needsEnrichment(array $show): bool
    {
        $defaultTitle = isset($show['date']) ? 'Point Break Radio ' . $show['date'] : null;
        $titleMissing = empty($show['title']) || $show['title'] === $defaultTitle;
        $descriptionMissing = empty($show['description']);
        $embedMissing = empty($show['mixcloud_embed_url']);
        $imageMissing = empty($show['hero_image']);

        return $titleMissing || $descriptionMissing || $embedMissing || $imageMissing;
    }

    private function mergeShowData(array $show, array $data): array
    {
        if (! empty($data['name'])) {
            [$niceDate, $niceTitle] = $this->splitHumanReadableTitle((string) $data['name']);
            if ($niceTitle) {
                $show['title'] = $niceTitle;
                if ($niceDate) {
                    $show['human_date'] = $niceDate;
                }
            }
            $show['_enriched'] = true;
        }

        if (! empty($data['description'])) {
            $cleanDescription = $this->stripIntro((string) $data['description'], $show['human_date'] ?? null, $show['title'] ?? '');
            if ($cleanDescription && empty($show['description'])) {
                $show['description'] = $cleanDescription;
                $show['_enriched'] = true;
            }
        }

        if (! empty($data['audio_length']) && empty($show['duration_seconds'])) {
            $show['duration_seconds'] = (int) $data['audio_length'];
            $show['_enriched'] = true;
        }

        if (! empty($data['created_time']) && empty($show['published_at'])) {
            $show['published_at'] = $data['created_time'];
            $show['_enriched'] = true;
        }

        if (! empty($data['url'])) {
            $show['mixcloud_url'] = $this->resolveUrl($data['url']);
        }

        if (! empty($data['pictures']) && empty($show['hero_image'])) {
            $show['hero_image'] = $this->resolvePicture($data['pictures']);
            $show['_enriched'] = true;
        }

        if (! empty($data['tags']) && is_array($data['tags'])) {
            $normalizedTags = [];
            foreach ($data['tags'] as $tag) {
                if (! is_array($tag) || empty($tag['name'])) {
                    continue;
                }
                $name = trim((string) $tag['name']);
                if ($name === '') {
                    continue;
                }
                $normalizedTags[] = strtolower($name);
            }

            if ($normalizedTags) {
                $show['tags'] = array_values(array_unique(array_merge($show['tags'], $normalizedTags)));
                $show['_enriched'] = true;
            }
        }

        return $show;
    }

    private function resolveUrl(string $value): string
    {
        if (str_starts_with($value, 'http')) {
            return $value;
        }

        return 'https://www.mixcloud.com' . $value;
    }

    private function resolvePicture(array $pictures): string
    {
        foreach (['extra_large', 'large', 'medium'] as $key) {
            if (! empty($pictures[$key])) {
                return (string) $pictures[$key];
            }
        }

        return '';
    }

    private function splitHumanReadableTitle(string $value): array
    {
        $pattern = '/^(?P<date>[A-Za-z]+,?\s+[A-Za-z]+\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4})\s+-\s+(?P<title>.+)$/';
        if (! preg_match($pattern, $value, $matches)) {
            return [null, $this->capitalize($value)];
        }

        $date = trim((string) ($matches['date'] ?? ''));
        $title = $this->capitalize(trim((string) ($matches['title'] ?? '')));

        return [$date ?: null, $title ?: $value];
    }

    private function stripIntro(string $description, ?string $date, string $title): string
    {
        $description = trim($description);
        if (! $date || $date === '' || $title === '') {
            return $this->capitalize($description);
        }

        $prefix = $date . ' - ' . $title;
        if (stripos($description, $prefix) === 0) {
            $description = substr($description, strlen($prefix));
            $description = ltrim($description, " \-–—");
        }

        return $this->capitalize(trim($description));
    }

    private function capitalize(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    private function extractEmbedSrc(?string $html): ?string
    {
        if (! $html) {
            return null;
        }

        if (preg_match('/src\s*=\s*"([^"]+)"/i', $html, $matches)) {
            $src = trim($matches[1]);
            if ($src !== '') {
                return $src;
            }
        }

        return null;
    }
}
