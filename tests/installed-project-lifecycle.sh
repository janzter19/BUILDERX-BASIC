#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
FORBIDDEN_ROOT="${BUILDERX_FORBIDDEN_SOURCE_ROOT:-/var/www/html/${BUILDERX_DEVELOPER_DIRECTORY_NAME:-developer}}"
BASE_URL="${BUILDERX_INSTALLED_BASE_URL:-}"

[[ "$PROJECT_ROOT" != "$FORBIDDEN_ROOT" && "$PROJECT_ROOT" != "$FORBIDDEN_ROOT"/* ]] || {
    printf 'Isolation lifecycle error: run this test from a different installed project.\n' >&2
    exit 1
}

php -l "$PROJECT_ROOT/index.php" >/dev/null
php -l "$PROJECT_ROOT/phases/index.php" >/dev/null
OPEN_BASEDIR="$PROJECT_ROOT:/tmp:/var/lib/php/sessions"
php -d "open_basedir=$OPEN_BASEDIR" "$PROJECT_ROOT/tests/installed-project-web-lifecycle.php"
BUILDERX_LIFECYCLE_ENTRY=phases php -d "open_basedir=$OPEN_BASEDIR" "$PROJECT_ROOT/tests/installed-project-web-lifecycle.php"
node --check "$PROJECT_ROOT/tools/builderx-bridge/extension/extension.js"
MYSQL_HEALTH="$(php "$PROJECT_ROOT/tools/builderx-ai-job.php" health)"
printf '%s' "$MYSQL_HEALTH" | PROJECT_ROOT="$PROJECT_ROOT" php -r '$payload=json_decode(stream_get_contents(STDIN),true); if(!is_array($payload)||($payload["ok"]??false)!==true||($payload["transport"]??"")!=="mysql"||($payload["workspace"]??"")!==getenv("PROJECT_ROOT")||($payload["job_table_ready"]??false)!==true||($payload["context_table_ready"]??false)!==true){fwrite(STDERR,"Installed MySQL AI transport health failed.\n");exit(1);} echo json_encode(["mysql_ai_transport"=>true,"workspace"=>$payload["workspace"],"manual_service_required"=>false],JSON_UNESCAPED_SLASHES),PHP_EOL;'

if [[ -n "$BASE_URL" ]]; then
    PORTAL_STATUS="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/")"
    PHASES_STATUS="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/phases/")"
    [[ "$PORTAL_STATUS" == 200 && "$PHASES_STATUS" == 200 ]] || {
        printf 'Installed Apache lifecycle failed: portal=%s phases=%s\n' "$PORTAL_STATUS" "$PHASES_STATUS" >&2
        exit 1
    }
    printf '{"apache_portal":%s,"apache_phases":%s}\n' "$PORTAL_STATUS" "$PHASES_STATUS"
fi
