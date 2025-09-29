# Point Break Radio MVP Plan

## Goals
- Deliver a pirate-radio inspired WordPress site with polished streaming experience.
- Implement custom theme and plugin providing REST-backed audio metadata and playback UI.
- Keep codebase idempotent and ready for future MySQL data source swap.

## Acceptance Criteria
- WordPress runs in DDEV with custom theme `pbradio` and plugin `pbr-core` active.
- REST API endpoints read from `data/shows.json` and return stable response shapes.
- UI showcases gradient masthead, dark archive grid, sticky player with Mixcloud embed, and monospace metadata.
- Sticky player persists playback during navigation and consumes `/pbr/v1/live` + `/pbr/v1/shows` endpoints.
- Code respects accessibility (contrast, focus states, reduced motion) and works without build tools.

## Task Checklist

### MVP Features
1. [x] Gradient masthead with boutique pirate aesthetic.
2. [x] Dark archive grid rendering show cards from REST data.
3. [x] Sticky player bar with VU animation and persistent Mixcloud iframe.
4. [x] JSON-backed REST API mirroring future MySQL schema.

### API Endpoints
1. [x] GET `/wp-json/pbr/v1/live`
2. [x] GET `/wp-json/pbr/v1/shows`
3. [x] GET `/wp-json/pbr/v1/show/{slug}`
4. [x] GET `/wp-json/pbr/v1/search?q=`
5. [x] POST `/wp-json/pbr/v1/recommend`
6. [x] POST `/wp-json/pbr/v1/bundle`

### UI Tasks (in order)
1. [x] Search overlay (Cmd/Ctrl+K) with keyboard focus management.
2. [x] Show details drawer with tracklist jump behavior.
3. [ ] "Pool Day" queue bundle integration.
4. [ ] Live toggle & 30s poll indicator.
5. [ ] Keyboard shortcuts: space (play/pause), n (next), p (prev).
6. [ ] Drawer polish: tracklist accordion, weather capsule, share sheet.
7. [ ] Masthead "On Air" indicator powered by Audio Hijack live state.
8. [x] Persistent floating search pill tuning (mobile + overlay interplay).

### Visual Polish
1. [ ] Hover micro-glitch interaction on show cards.
2. [ ] VU animation timing tuned for subtle movement.
3. [ ] Typography pairing finalized for masthead vs content vs monospace metadata.
4. [x] Investigate Safari gradient rendering on show cards and align with Chrome.
5. [ ] Footer alignment & responsive spacing refresh.

### Data Migration
- [x] Document identical endpoint response shapes to ease JSON → MySQL migration later (see `tools/import-shows.php` and generated `data/library.json`).
- [ ] Define archive/front-page content strategy as dataset grows (curation vs. full listing).

### Operational
1. [x] DDEV configuration commands documented.
2. [x] Theme and plugin autoload bootstrap verified.
3. [x] JSON seed data kept in sync with REST outputs (regenerated via `php tools/import-shows.php`).
4. [x] Optional Mixcloud enrichment via `php tools/import-shows.php --mixcloud` with cached responses.
5. [ ] Integrate Audio Hijack live webhook to flip /live state (mixcloud.com/live/pointbreakradio).
6. [ ] Store Mixcloud API credentials outside repo (env/secrets) and load via config constants.
7. [ ] Draft migration plan for moving shows/tracks into MySQL once CSV validation is complete.
8. [x] Document importer flags (`--only`, `--delete`, `--mixcloud`) in README/docs.

### Mixcloud Automation
- [ ] Sync track metadata back to Mixcloud via API once timing data available.
- [ ] Add per-show notes/links section in drawer (accordion with comments/corrections).

### Analytics Ideas
- [ ] Explore track/artist/genre stats for a "trending" dashboard.
