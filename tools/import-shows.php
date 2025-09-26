#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$importDir   = $projectRoot . '/data/import';
$metaDir     = $importDir . '/meta';
$outputShows = $projectRoot . '/data/shows.json';
$outputLibrary = $projectRoot . '/data/library.json';

if (! is_dir($importDir)) {
    fwrite(STDERR, "Import directory not found: {$importDir}\n");
    exit(1);
}

$csvFiles = glob($importDir . '/*.csv');
if (! $csvFiles) {
    fwrite(STDOUT, "No CSV files found in {$importDir}.\n");
    exit(0);
}

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

    $mixcloudPath = $meta['mixcloud_path'] ?? $showSlug;
    $mixcloudUrl = $meta['mixcloud_url'] ?? buildMixcloudUrl($mixcloudPath);
    $mixcloudEmbedUrl = $meta['mixcloud_embed_url'] ?? buildMixcloudEmbedUrl($mixcloudPath);

    $shows[$showId] = [
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
    ];
}

if (! $shows) {
    fwrite(STDOUT, "No show data generated. Nothing to write.\n");
    exit(0);
}

usort($showTracks, static function (array $a, array $b): int {
    if ($a['show_id'] === $b['show_id']) {
        return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
    }

    return strcmp($a['show_id'], $b['show_id']);
});

uasort($tracks, static function (array $a, array $b): int {
    return strcmp($a['title'], $b['title']);
});

usort($artists, static function (array $a, array $b): int {
    return strcmp($a['name'], $b['name']);
});

usort($albums, static function (array $a, array $b): int {
    return strcmp($a['name'], $b['name']);
});

usort($shows, static function (array $a, array $b): int {
    return strcmp($b['date'], $a['date']);
});

$library = [
    'generated_at' => gmdate('c'),
    'artists' => array_values($artists),
    'albums' => array_values($albums),
    'tracks' => array_values($tracks),
    'shows' => array_values($shows),
    'show_tracks' => array_values($showTracks),
];

writeJson($outputLibrary, $library);

$artistLookup = mapById($library['artists']);
$albumLookup = mapById($library['albums']);
$trackLookup = mapById($library['tracks']);
$showLookup = mapById($library['shows']);

$denormalised = [];
foreach ($library['shows'] as $show) {
    $showTracksRecords = array_values(array_filter($library['show_tracks'], static function (array $record) use ($show): bool {
        return $record['show_id'] === $show['id'];
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
        'slug' => $show['slug'],
        'title' => $show['title'],
        'description' => $show['description'],
        'mixcloud_url' => $show['mixcloud_url'],
        'mixcloud_embed_url' => $show['mixcloud_embed_url'],
        'published_at' => $show['published_at'],
        'duration_seconds' => $show['duration_seconds'],
        'hero_image' => $show['hero_image'],
        'year' => $show['year'],
        'tags' => $show['tags'],
        'tracks' => $trackPayload,
        '_ft' => buildFullText($show, $trackPayload),
    ];
}

writeJson($outputShows, ['shows' => $denormalised]);

fwrite(STDOUT, sprintf("Imported %d shows → %s\n", count($denormalised), $outputShows));

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
    }

    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
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
