#!/usr/bin/env bash
set -euo pipefail

ENDPOINT_DEFAULT="http://pbr.ddev.site/wp-json/pbr/v1/live"
ENDPOINT="${PBR_LIVE_ENDPOINT:-$ENDPOINT_DEFAULT}"
SECRET="${PBR_LIVE_SECRET:-}"

usage() {
  cat <<USAGE
Usage:
  PBR_LIVE_SECRET=... ${0##*/} on [slug] [now_playing]
  PBR_LIVE_SECRET=... ${0##*/} off

Options via env:
  PBR_LIVE_ENDPOINT  Live endpoint (default: $ENDPOINT_DEFAULT)

Examples:
  PBR_LIVE_SECRET=changeme ${0##*/} on 2021-04-10 "Point Break Radio Live"
  PBR_LIVE_SECRET=changeme ${0##*/} off
USAGE
}

require_secret() {
  if [[ -z "$SECRET" ]]; then
    echo "PBR_LIVE_SECRET is required." >&2
    usage
    exit 1
  fi
}

cmd=${1:-}
shift || true

case "$cmd" in
  on)
    require_secret
    slug=${1:-}
    now_playing=${2:-"Point Break Radio Live"}
    payload=$(jq -n --arg slug "$slug" --arg np "$now_playing" '{is_live:true, slug: ($slug|select(length>0)//null), now_playing:$np, source:"cli"}')
    ;;
  off)
    require_secret
    payload='{"is_live":false,"source":"cli"}'
    ;;
  *)
    usage
    exit 1
    ;;
esac

curl -sS -X POST "$ENDPOINT" \
  -H 'Content-Type: application/json' \
  -H "X-PBR-Secret: $SECRET" \
  -d "$payload"

echo

