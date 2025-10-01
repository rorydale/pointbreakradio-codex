Live status automation (Audio Hijack + DDEV)

Overview
- Endpoints power the masthead Live badge and can be called from automation.
- The badge polls every ~30s and flips instantly on a successful POST.

Endpoints
- `GET /wp-json/pbr/v1/live`
  - Response: `{ is_live, updated_at, now_playing, source, slug?, show }`
- `POST /wp-json/pbr/v1/live`
  - Auth: header `X-PBR-Secret: <secret>` is required
  - JSON body (minimal): `{ "is_live": true|false }`
  - Optional fields: `slug` (show slug), `now_playing` (string), `source` (string)

Auth (server side)
- Secret is read as follows (first match wins):
  1. PHP constant `PBR_LIVE_SECRET` (e.g., in wp-config-local.php)
  2. Environment variable `PBR_LIVE_SECRET` (e.g., DDEV `web_environment`)

Configure secret
- DDEV (local): create `.ddev/config.local.yaml` with the following and run `ddev restart`:

  web_environment:
    - PBR_LIVE_SECRET=change-me

- WordPress config (alternative): in a non-committed override (e.g. `wp-config-local.php`) add:

  define('PBR_LIVE_SECRET', 'change-me');

Using Keychain in commands
- Fetch the secret from macOS Keychain (recommended):

  token=$(security find-generic-password -s PBR_LIVE_SECRET -w 2>/dev/null || echo 'change-me')

- Use it in a curl call:

  curl -sS -X POST 'http://pbr.ddev.site/wp-json/pbr/v1/live' \\
    -H 'Content-Type: application/json' \\
    -H "X-PBR-Secret: $token" \\
    -d '{"is_live":true,"slug":"2021-04-18","now_playing":"Point Break Radio Live","source":"manual"}'

Audio Hijack (JavaScript Script block)
- The Script block runs JavaScript with the helper `app.runShellCommand()`.
- Session Started (On Air):

  // Session Started script (JavaScript)
  const endpoint = 'http://pbr.ddev.site/wp-json/pbr/v1/live';
  const [secStatus, secOut, secErr] = app.runShellCommand("/usr/bin/security find-generic-password -s PBR_LIVE_SECRET -w 2>/dev/null || echo 'change-me'");
  const token = (secStatus === 0 ? secOut : '').trim() || 'change-me';

  const slug = '2021-04-18';
  const nowPlaying = 'Point Break Radio Live';
  const payload = JSON.stringify({
    is_live: true,
    slug: slug || null,
    now_playing: nowPlaying,
    source: 'audio-hijack'
  });

  const cmd = [
    '/usr/bin/curl',
    '-sS',
    '-X', 'POST',
    app.shellEscapeArgument(endpoint),
    '-H', app.shellEscapeArgument('Content-Type: application/json'),
    '-H', app.shellEscapeArgument('X-PBR-Secret: ' + token),
    '-d', app.shellEscapeArgument(payload)
  ].join(' ');

  const [curlStatus, stdout, stderr] = app.runShellCommand(cmd);
  if (curlStatus !== 0) {
    app.print('Live on call failed: ' + stderr);
  }

- Session Stopped (Off Air):

  const endpoint = 'http://pbr.ddev.site/wp-json/pbr/v1/live';
  const [secStatus, secOut, secErr] = app.runShellCommand("/usr/bin/security find-generic-password -s PBR_LIVE_SECRET -w 2>/dev/null || echo 'change-me'");
  const token = (secStatus === 0 ? secOut : '').trim() || 'change-me';

  const payload = JSON.stringify({
    is_live: false,
    source: 'audio-hijack'
  });

  const cmd = [
    '/usr/bin/curl',
    '-sS',
    '-X', 'POST',
    app.shellEscapeArgument(endpoint),
    '-H', app.shellEscapeArgument('Content-Type: application/json'),
    '-H', app.shellEscapeArgument('X-PBR-Secret: ' + token),
    '-d', app.shellEscapeArgument(payload)
  ].join(' ');

  const [curlStatus, stdout, stderr] = app.runShellCommand(cmd);
  if (curlStatus !== 0) {
    app.print('Live off call failed: ' + stderr);
  }

Audio Hijack (Run Shell Script block)
- Add an Automation → Run Shell Script block.
- Set Interpreter to `/bin/bash`.
- Session Started (On Air):

  #!/usr/bin/env bash
  endpoint="http://pbr.ddev.site/wp-json/pbr/v1/live"
  token="$(security find-generic-password -s PBR_LIVE_SECRET -w 2>/dev/null || echo 'change-me')"
  slug="2021-04-18"    # optional; leave empty for null
  now_playing="Point Break Radio Live"

  payload=$(printf '{"is_live":true,"slug":"%s","now_playing":"%s","source":"audio-hijack"}' "$slug" "$now_playing")
  curl -sS -X POST "$endpoint" \
    -H 'Content-Type: application/json' \
    -H "X-PBR-Secret: $token" \
    -d "$payload" >/dev/null

- Session Stopped (Off Air):

  #!/usr/bin/env bash
  endpoint="http://pbr.ddev.site/wp-json/pbr/v1/live"
  token="$(security find-generic-password -s PBR_LIVE_SECRET -w 2>/dev/null || echo 'change-me')"

  curl -sS -X POST "$endpoint" \
    -H 'Content-Type: application/json' \
    -H "X-PBR-Secret: $token" \
    -d '{"is_live":false,"source":"audio-hijack"}' >/dev/null

CLI helper (optional)
- `tools/live.sh` posts on/off to the current environment using `PBR_LIVE_SECRET`.
- Usage:

  export PBR_LIVE_SECRET=change-me
  ./tools/live.sh on 2021-04-10 "Point Break Radio Live"
  ./tools/live.sh off

Troubleshooting
- zsh interprets bare `-H`/`-d` on new lines as commands. Use backslashes or a single line.
- If POST returns `pbr_live_invalid_payload`, your JSON body likely didn’t reach the server; check quoting.
- Confirm the secret is loaded in PHP: `ddev exec php -r 'echo getenv("PBR_LIVE_SECRET"), "\n";'`.
