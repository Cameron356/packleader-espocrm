#!/usr/bin/env bash
# Add the custom-invoice office-book fields (spec: pk9-app docs/specs/custom-invoices.md §1):
#   Lead.service enum value "Custom" (label "Custom invoice")
#   Lead.invoiceLines (text, label "Invoice lines"): JSON lines written by the app, read by the robot.
# Modelled on packleader-add-phase5a-fields.sh. Idempotent. Defaults to the live container (espocrm,
# which needs the owner's OK first); pass pk9dev-espocrm for the dev book. Run on fTower:
#   cd ~/homelab && bash scripts/packleader-add-custom-invoice-fields.sh pk9dev-espocrm
set -euo pipefail

C="${1:-espocrm}"
B="$HOME/espocrm-custom-backup-$(date +%Y%m%d-%H%M%S)-custom-invoice-fields.tgz"

# 1. Back up custom/ first (same convention as packleader-add-noshow.sh).
docker exec "$C" tar czf - custom > "$B"
chmod 600 "$B"
ls -l "$B"

# 2. Edit the JSON metadata. Asserts the structure before touching it: a fields block, the
#    service enum with its options (metadata) and the service option labels (i18n).
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
    $s = $j["fields"]["service"] ?? null;
    if (!is_array($s) || ($s["type"] ?? "") !== "enum" || !isset($s["options"]) || !is_array($s["options"])
        || !in_array("Boarding", $s["options"], true)) {
        fwrite(STDERR, "Lead.service is not the expected enum\n"); exit(1);
    }
    if (!in_array("Custom", $s["options"], true)) $j["fields"]["service"]["options"][] = "Custom";
    $j["fields"]["invoiceLines"] = ["type" => "text", "isCustom" => true];
    return $j;
});
editStrict("$base/i18n/en_US/Lead.json", function ($j) {
    if (!isset($j["options"]["service"]) || !is_array($j["options"]["service"])
        || !isset($j["options"]["service"]["Boarding"])) {
        fwrite(STDERR, "Lead i18n has no service option labels\n"); exit(1);
    }
    $j["options"]["service"]["Custom"] = "Custom invoice";
    $j["fields"]["invoiceLines"] = "Invoice lines";
    return $j;
});
echo "custom-invoice fields added (Lead.service Custom, Lead.invoiceLines, labels)\n";
'

docker exec -u www-data "$C" bin/command rebuild >/dev/null && echo rebuilt
docker exec -u www-data "$C" bin/command clear-cache >/dev/null && echo "cache cleared: custom-invoice fields are live"
