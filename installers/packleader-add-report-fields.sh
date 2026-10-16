#!/usr/bin/env bash
# Office book: add the StayLog and TrainingGoal entities for report cards + board & train progress
# (pk9-app docs/specs/phase5b-report-cards.md). Idempotent. Owner OK needed before the live book.
#   bash scripts/packleader-add-report-fields.sh                       # live book, grant to "rebecca"
#   bash scripts/packleader-add-report-fields.sh pk9dev-espocrm owner-dev   # dev book
set -euo pipefail
cd "$(dirname "$0")/.."
C="${1:-espocrm}"
STAFF="${2:-rebecca}"   # the (non-super-admin) staff user the app signs in as, e.g. rebecca / owner-dev
B="$HOME/espocrm-custom-backup-$(date +%Y%m%d-%H%M%S)-report.tgz"

# 1. Back up custom/ first.
docker exec "$C" tar czf - custom > "$B"
chmod 600 "$B"
ls -l "$B"

# 2. Install the two entities' files (mirrors of configs/espocrm/{stay-log,training-goal}/custom).
for dir in stay-log training-goal; do
  tar -C "configs/espocrm/$dir" -cf - custom | docker exec -i -u www-data "$C" tar -C /var/www/html -xf -
done
echo "entity files installed"

# 3. Rebuild the metadata (creates the tables) and clear the cache.
docker exec -u www-data "$C" bin/command rebuild >/dev/null && echo rebuilt

# 4. Grant the office-flow robot read + edit on StayLog (it reads the cards and stamps sentAt).
docker exec -i -u www-data "$C" php -r '
require "bootstrap.php";
$app = new \Espo\Core\Application();
$app->setupSystemUser();
$em = $app->getContainer()->getByClass(\Espo\ORM\EntityManager::class);
$role = $em->getRDBRepository("Role")->where(["name" => "Office flow"])->findOne();
if (!$role) { fwrite(STDERR, "no Office flow role\n"); exit(1); }
$data = (array) $role->get("data");
$data["StayLog"] = ["create" => "no", "read" => "all", "edit" => "all", "delete" => "no", "stream" => "no"];
$role->set("data", (object) $data);
$em->saveEntity($role);
fwrite(STDERR, "Office flow role: StayLog read/edit granted\n");
'

# 5. Give the staff user (the one the app signs in as) full access to both entities. The super-admin
#    owner has it already; a regular admin user needs this role.
docker exec -i -u www-data -e STAFF="$STAFF" "$C" php -r '
require "bootstrap.php";
$app = new \Espo\Core\Application();
$app->setupSystemUser();
$em = $app->getContainer()->getByClass(\Espo\ORM\EntityManager::class);
$role = $em->getRDBRepository("Role")->where(["name" => "Report cards"])->findOne() ?? $em->getNewEntity("Role");
$data = (array) $role->get("data");
foreach (["StayLog", "TrainingGoal"] as $e) {
    $data[$e] = ["create" => "all", "read" => "all", "edit" => "all", "delete" => "all", "stream" => "yes"];
}
$role->set(["name" => "Report cards", "data" => (object) $data, "fieldData" => (object) [],
            "assignmentPermission" => "all", "userPermission" => "no"]);
$em->saveEntity($role);
$user = $em->getRDBRepository("User")->where(["userName" => getenv("STAFF")])->findOne();
if ($user) {
    $rel = $em->getRDBRepository("User")->getRelation($user, "roles");
    if (!$rel->isRelated($role)) { $rel->relate($role); }
    fwrite(STDERR, "Report cards role: granted to " . getenv("STAFF") . "\n");
} else {
    fwrite(STDERR, "no user named " . getenv("STAFF") . "; the Report cards role exists, assign it by hand\n");
}
'

docker exec -u www-data "$C" bin/command clear-cache >/dev/null && echo "cache cleared: StayLog + TrainingGoal are live"
