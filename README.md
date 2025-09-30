# Point Break Radio

- Project plan: see `PLAN.md`.
- Design language: see `MANIFESTO.md`.
- Importer docs: see `docs/IMPORTING.md`.
- Live automation: see `docs/live.md` and `tools/live.sh`.

Quick start (DDEV)
- `ddev start`
- Visit `http://pbr.ddev.site`
- Optional: create `.ddev/config.local.yaml` and set `web_environment: [PBR_LIVE_SECRET=change-me]`, then `ddev restart`.

Theme + plugin
- Theme: `wp/wp-content/themes/pbradio`
- Plugin: `wp/wp-content/plugins/pbr-core`

REST endpoints
- `GET /wp-json/pbr/v1/shows`
- `GET /wp-json/pbr/v1/show/{slug}`
- `GET /wp-json/pbr/v1/search?q=`
- `POST /wp-json/pbr/v1/recommend`
- `POST /wp-json/pbr/v1/bundle`
- `GET|POST /wp-json/pbr/v1/live` (see `docs/live.md`)

