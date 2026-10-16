#!/usr/bin/env bash
# Live office book: add Lead.payRef (varchar 12, label "Invoice ID") so the robot can write the
# invoice ID PK9-XXXXX to each request. Used by the Zelle-as-third-option plan.
# Owner OK needed before the live book. Idempotent. Run on fTower:
#   cd ~/homelab && bash scripts/packleader-add-payref-field.sh
# For the dev book:
#   bash scripts/packleader-add-payref-field.sh pk9dev-espocrm
set -euo pipefail
C="${1:-espocrm}"
B="$HOME/espocrm-custom-backup-$(date +%Y%m%d-%H%M%S)-payref.tgz"

# 1. Back up custom/ first (same convention as packleader-add-custom-invoice-fields.sh).
docker exec "$C" tar czf - custom > "$B"
chmod 600 "$B"
ls -l "$B"

# 2. Edit the JSON metadata. Asserts a fields block before touching it.
docker exec -i -u www-data "$C" php -r '
$base = "/var/www/html/custom/Espo/Custom/Resources";
function editStrict($file, $fn) {
    $raw = file_get_contents($file);
    if ($raw === false) { fwrite(STDERR, "cannot read $file\n"); exit(1); }
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j["fields"]) || !is_array($j["fields"])) {
        fwrite(STDERR, "$file has no fields block\n"); exit(1);
    }
    $j = $fn($j);
    file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
editStrict("$base/metadata/entityDefs/Lead.json", function ($j) {
    $j["fields"]["payRef"] = ["type" => "varchar", "maxLength" => 12, "isCustom" => true];
    return $j;
});
editStrict("$base/i18n/en_US/Lead.json", function ($j) {
    $j["fields"]["payRef"] = "Invoice ID";
    return $j;
});
echo "payRef field added (metadata, label)\n";
'

docker exec -u www-data "$C" bin/command rebuild >/dev/null && echo rebuilt
docker exec -u www-data "$C" bin/command clear-cache >/dev/null && echo "cache cleared: payRef is live"
