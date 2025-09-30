Live status automation (Audio Hijack + DDEV)

Overview
- The site exposes GET/POST `wp-json/pbr/v1/live`.
- A shared secret `PBR_LIVE_SECRET` authorizes POSTs (set via env or a PHP constant).
- The masthead indicator polls every ~30s and flips instantly on successful POST.

Configure secret
- DDEV (local): create `.ddev/config.local.yaml` with the following and run `ddev restart`:

  web_environment:
    - PBR_LIVE_SECRET=change-me

- WordPress config (alternative): in a non-committed override (e.g. `wp-config-local.php`) add:

  define('PBR_LIVE_SECRET', 'change-me');

Audio Hijack script
- Use the Script block (JavaScript – JXA). On Session Started:

  (function() {
    var endpoint = "http://pbr.ddev.site/wp-json/pbr/v1/live";
    var secret   = Application.currentApplication();
    secret.includeStandardAdditions = true;
    // Pull from Keychain (recommended) or replace with your value while testing
    var token = secret.doShellScript("security find-generic-password -s PBR_LIVE_SECRET -w 2>/dev/null || echo ''");
    function q(s){return "'"+String(s).replace(/'/g,"'\\''")+"'";}
    var payload = { is_live:true, slug:null, now_playing:"Point Break Radio Live", source:"audio-hijack" };
    var cmd = ["curl -sS -X POST", q(endpoint), "-H 'Content-Type: application/json'", "-H "+q("X-PBR-Secret: "+token), "-d "+q(JSON.stringify(payload))].join(" ");
    secret.doShellScript(cmd);
  })();

- On Session Stopped:

  (function() {
    var endpoint = "http://pbr.ddev.site/wp-json/pbr/v1/live";
    var app = Application.currentApplication(); app.includeStandardAdditions = true;
    var token = app.doShellScript("security find-generic-password -s PBR_LIVE_SECRET -w 2>/dev/null || echo ''");
    function q(s){return "'"+String(s).replace(/'/g,"'\\''")+"'";}
    var payload = { is_live:false, source:"audio-hijack" };
    var cmd = ["curl -sS -X POST", q(endpoint), "-H 'Content-Type: application/json'", "-H "+q("X-PBR-Secret: "+token), "-d "+q(JSON.stringify(payload))].join(" ");
    app.doShellScript(cmd);
  })();

CLI helper (optional)
- `tools/live.sh` posts on/off to the current environment using `PBR_LIVE_SECRET`.
- Usage:

  export PBR_LIVE_SECRET=change-me
  ./tools/live.sh on 2021-04-10 "Point Break Radio Live"
  ./tools/live.sh off

