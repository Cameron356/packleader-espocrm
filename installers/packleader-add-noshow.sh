#!/usr/bin/env bash
# Live office book: add Meeting.noShow ("No-show", bool, default false) + a row under Status on the lesson page.
# Owner OK 2026-10-13 (phone app Phase 3: a no-show is Held + noShow, so it still uses the session).
# Same definition as MEETING_FIELDS in packleader-office-flow-install.py. Idempotent. Run on fTower:
#   cd ~/homelab && bash scripts/packleader-add-noshow.sh
set -euo pipefail
C=espocrm
B=~/espocrm-custom-backup-$(date +%Y%m%d-%H%M%S)-noshow.tgz
docker exec "$C" tar czf - custom > "$B"; chmod 600 "$B"; ls -l "$B"
docker exec -i -u www-data "$C" php -r '
$base = "/var/www/html/custom/Espo/Custom/Resources";
function edit($file, $fn) {
    $raw = file_get_contents($file);
    if ($raw === false) { fwrite(STDERR, "cannot read $file\n"); exit(1); }
    $j = json_decode($raw, true);
    if (!is_array($j)) { fwrite(STDERR, "$file is not JSON\n"); exit(1); }
    $j = $fn($j);
    file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
edit("$base/metadata/entityDefs/Meeting.json", function ($j) {
    if (!isset($j["fields"]) || !is_array($j["fields"])) { fwrite(STDERR, "no fields block\n"); exit(1); }
    $j["fields"]["noShow"] = ["type" => "bool", "default" => false, "isCustom" => true];
    return $j;
});
edit("$base/i18n/en_US/Meeting.json", function ($j) {
    if (!isset($j["fields"]) || !is_array($j["fields"])) { fwrite(STDERR, "no i18n fields block\n"); exit(1); }
    $j["fields"]["noShow"] = "No-show";
    return $j;
});
edit("$base/layouts/Meeting/detail.json", function ($j) {
    if (strpos(json_encode($j), "noShow") !== false) return $j;
    $rows = $j[0]["rows"]; $at = null;
    foreach ($rows as $i => $r) foreach ($r as $c) if (is_array($c) && ($c["name"] ?? "") === "status") $at = $i + 1;
    if ($at === null) { fwrite(STDERR, "no status row\n"); exit(1); }
    array_splice($rows, $at, 0, [[["name" => "noShow"], false]]);
    $j[0]["rows"] = $rows;
    return $j;
});
echo "noShow added (metadata, label, layout)\n";
'
docker exec -u www-data "$C" bin/command rebuild >/dev/null && echo rebuilt
docker exec -u www-data "$C" bin/command clear-cache >/dev/null && echo "cache cleared: Meeting.noShow is live"
