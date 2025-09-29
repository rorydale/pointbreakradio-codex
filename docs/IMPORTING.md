# Importing And Managing Show Data

The `tools/import-shows.php` script compiles the CSV metadata under `data/import/` into the JSON payloads used by the plugin (`data/shows.json` and `data/library.json`). It can also pull extra metadata from Mixcloud and, when needed, remove individual shows from the generated datasets.

## Standard Import

```bash
php tools/import-shows.php
```

- Reads every `.csv` file in `data/import/`
- Rebuilds `data/shows.json` and `data/library.json`
- Skips remote Mixcloud enrichment unless you pass `--mixcloud`

## Targeted Imports With `--only`

Use `--only=` to limit work to one or more shows. You can supply either the date slug (`YYYY-MM-DD`) or the CSV filename without the `.csv` suffix.

```bash
# Reimport a single show from its local CSV
php tools/import-shows.php --only=2023-06-23

# Reimport multiple shows and hit Mixcloud for fresh metadata
php tools/import-shows.php --only=2023-06-23,2023-06-30 --mixcloud
```

When `--mixcloud` is provided alongside `--only`, the script calls Mixcloud **only** for the requested shows (responses are cached in `data/cache/mixcloud`).
The importer automatically filters the intro/outro underscore tracks ("BND" by No Doubt and "The Blue Wrath" by I Monster) so they do not appear in tracklist output or search results.


## Deleting Shows

To remove a show from the generated JSON files, combine `--only` with `--delete`.

```bash
php tools/import-shows.php --only=2023-06-23 --delete
```

- The matching show (by date slug or CSV basename) is stripped from `shows.json` and `library.json`
- Associated track relations are removed as well
- The script preserves Mixcloud enrichment data already captured for the remaining shows
- The CSV file is *not* deleted, so running the importer again without `--delete` will bring the show back

Passing `--delete` without an accompanying `--only` will abort with an error.

## Tips

- CSV filenames should include the show date (e.g. `2023-06-23_scrobbled.csv`) so the slug can be derived automatically.
- Keep Mixcloud credentials outside of version control (e.g. in an `.env` file); the importer only needs them when you opt into enrichment.
- Regenerated JSON files are idempotent—rerunning the script produces a fresh snapshot each time.
- Need a custom Mixcloud path? Drop a `data/import/meta/<slug>.json` file with overrides (e.g. `{"mixcloud_path":"point-break-radio-live-20201112-2350"}`) and the importer will use it for local URLs and Mixcloud enrichment.
