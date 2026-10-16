#!/usr/bin/env bash
# Create (or update) the read-only API user `pwa-watch` for the Pack Leader phone app's watcher (pk9-app Phase 4:
# push notifications). Owner OK 2026-10-13. Run on fTower from ~/homelab:
#   bash scripts/packleader-add-pwa-watch.sh                       # live office book -> /opt/docker/pk9-app/secrets/pwa-watch.key
#   bash scripts/packleader-add-pwa-watch.sh pk9dev-espocrm 8241 /tmp/x.key [username]   # test on the dev book
# Role "PWA watch (read only)": read all, nothing else, on Lead, Stay, Dog, RequestDog, Contact, Meeting, LessonPackage.
# Works with admin 2FA on: it runs inside the office-book container with EspoCRM's own code (no admin login).
# Idempotent: re-running keeps the user and its key, and re-applies the role. The key is never printed.
set -euo pipefail
C=${1:-espocrm}
PORT=${2:-8210}
KEYFILE=${3:-/opt/docker/pk9-app/secrets/pwa-watch.key}
USERNAME=${4:-pwa-watch}

docker inspect -f '{{.Name}}' "$C" >/dev/null
# The PHP prints only the API key (to stdout, straight into the key file) and status lines to stderr.
docker exec -i -u www-data -e PWA_USER="$USERNAME" "$C" php -r '
require "bootstrap.php";
$app = new \Espo\Core\Application();
$app->setupSystemUser();
$em = $app->getContainer()->getByClass(\Espo\ORM\EntityManager::class);
$entities = ["Lead", "Stay", "Dog", "RequestDog", "Contact", "Meeting", "LessonPackage"];
$data = [];
foreach ($entities as $e) { $data[$e] = ["create" => "no", "read" => "all", "edit" => "no", "delete" => "no"]; }
$data["Lead"]["stream"] = "no"; $data["Meeting"]["stream"] = "no";
$roleName = "PWA watch (read only)";
$role = $em->getRDBRepository("Role")->where(["name" => $roleName])->findOne() ?? $em->getNewEntity("Role");
$role->set(["name" => $roleName, "data" => (object) $data, "fieldData" => (object) [],
            "assignmentPermission" => "no", "userPermission" => "no"]);
$em->saveEntity($role);
fwrite(STDERR, "role: " . $roleName . " (" . $role->getId() . ")\n");
$name = getenv("PWA_USER");
$user = $em->getRDBRepository("User")->where(["userName" => $name])->findOne();
if (!$user) {
    $user = $em->getNewEntity("User");
    $user->set(["userName" => $name, "lastName" => "PWA watch", "type" => "api", "authMethod" => "ApiKey",
                "apiKey" => bin2hex(random_bytes(16)), "isActive" => true]);
    $em->saveEntity($user);
    fwrite(STDERR, "API user " . $name . ": created\n");
} else {
    if (!$user->get("apiKey")) { $user->set("apiKey", bin2hex(random_bytes(16))); }
    $user->set(["type" => "api", "authMethod" => "ApiKey", "isActive" => true]);
    $em->saveEntity($user);
    fwrite(STDERR, "API user " . $name . ": exists, kept\n");
}
$rel = $em->getRDBRepository("User")->getRelation($user, "roles");
if (!$rel->isRelated($role)) { $rel->relate($role); }
echo $user->get("apiKey");
' | sudo -n tee "$KEYFILE.new" >/dev/null
sudo -n test -s "$KEYFILE.new" || { echo "no key came back; nothing written" >&2; sudo -n rm -f "$KEYFILE.new"; exit 1; }
sudo -n chmod 600 "$KEYFILE.new"
if [ "$KEYFILE" = /opt/docker/pk9-app/secrets/pwa-watch.key ]; then sudo -n chown 65532:65532 "$KEYFILE.new"; fi
sudo -n mv "$KEYFILE.new" "$KEYFILE"
docker exec -u www-data "$C" bin/command clear-cache >/dev/null
echo "key written to $KEYFILE (600, not printed); cache cleared"

# Prove it: reads every entity, can't create.
sudo -n python3 - "$KEYFILE" "$PORT" <<'PY'
import json, sys, urllib.error, urllib.request
key = open(sys.argv[1]).read().strip(); base = "http://127.0.0.1:%s/api/v1/" % sys.argv[2]
h = {"X-Api-Key": key, "Content-Type": "application/json"}
for e in ["Lead", "Stay", "Dog", "RequestDog", "Contact", "Meeting", "LessonPackage"]:
    sel = "id,name,shotRecordsIds,modifiedAt" if e in ("Dog", "RequestDog") else "id,modifiedAt"
    r = json.load(urllib.request.urlopen(urllib.request.Request(base + e + "?maxSize=1&select=" + sel, headers=h), timeout=30))
    print("  can read %-13s (%d records)" % (e, r["total"]))
for e in ("Lead", "Dog"):
    try:
        urllib.request.urlopen(urllib.request.Request(base + e, data=b'{"name":"should be refused"}', headers=h, method="POST"), timeout=30)
    except urllib.error.HTTPError as ex:
        print("  cannot create %-5s (refused: %d)" % (e, ex.code)); continue
    sys.exit("pwa-watch could CREATE a %s: the role is wrong. Disable the user now." % e)
PY
