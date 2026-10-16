#!/usr/bin/env bash
# Add the Phase 5a office-book fields (lesson notes + homework, feeding/meds care checklist)
# to an EspoCRM office book. Modelled on packleader-add-noshow.sh.
# Idempotent. Defaults to the live container (espocrm); pass pk9dev-espocrm for the dev book.
#   cd ~/homelab && bash scripts/packleader-add-phase5a-fields.sh pk9dev-espocrm
set -euo pipefail

C="${1:-espocrm}"
B="$HOME/espocrm-custom-backup-$(date +%Y%m%d-%H%M%S)-phase5a-fields.tgz"

# 1. Back up custom/ first (same convention as packleader-add-noshow.sh).
docker exec "$C" tar czf - custom > "$B"
chmod 600 "$B"
ls -l "$B"

# 2. Edit the JSON metadata. Asserts every target file has a `fields` block (metadata + i18n);
#    the detail layout is checked for a rows block instead (it has no `fields` block).
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
function editLayout($file, $fn) {
    $raw = file_get_contents($file);
    if ($raw === false) { fwrite(STDERR, "cannot read $file\n"); exit(1); }
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j[0]["rows"]) || !is_array($j[0]["rows"])) {
        fwrite(STDERR, "$file has no rows block\n"); exit(1);
    }
    $j = $fn($j);
    file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
function hasName($rows, $name) {
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        foreach ($r as $c) {
            if (is_array($c) && (($c["name"] ?? "") === $name)) return true;
        }
    }
    return false;
}
editStrict("$base/metadata/entityDefs/Meeting.json", function ($j) {
    $j["fields"]["lessonNotes"] = ["type" => "text", "isCustom" => true];
    $j["fields"]["homework"] = ["type" => "text", "isCustom" => true];
    return $j;
});
editStrict("$base/i18n/en_US/Meeting.json", function ($j) {
    $j["fields"]["lessonNotes"] = "Lesson notes";
    $j["fields"]["homework"] = "Homework";
    return $j;
});
editStrict("$base/metadata/entityDefs/Stay.json", function ($j) {
    $j["fields"]["feedingAM"] = ["type" => "varchar", "maxLength" => 120, "isCustom" => true];
    $j["fields"]["feedingPM"] = ["type" => "varchar", "maxLength" => 120, "isCustom" => true];
    $j["fields"]["medsAM"] = ["type" => "varchar", "maxLength" => 120, "isCustom" => true];
    $j["fields"]["medsPM"] = ["type" => "varchar", "maxLength" => 120, "isCustom" => true];
    $j["fields"]["careNote"] = ["type" => "text", "isCustom" => true];
    return $j;
});
editStrict("$base/i18n/en_US/Stay.json", function ($j) {
    $j["fields"]["feedingAM"] = "Feeding AM (last tick)";
    $j["fields"]["feedingPM"] = "Feeding PM (last tick)";
    $j["fields"]["medsAM"] = "Meds AM (last tick)";
    $j["fields"]["medsPM"] = "Meds PM (last tick)";
    $j["fields"]["careNote"] = "Care note (today)";
    return $j;
});
editLayout("$base/layouts/Meeting/detail.json", function ($j) {
    $rows = $j[0]["rows"];
    $desc = null;
    foreach ($rows as $i => $r) {
        if (is_array($r)) foreach ($r as $c) {
            if (is_array($c) && (($c["name"] ?? "") === "description")) $desc = $i;
        }
    }
    $insertAt = ($desc === null) ? count($rows) : $desc + 1;
    foreach (["lessonNotes", "homework"] as $field) {
        if (hasName($rows, $field)) continue;
        $row = [["name" => $field, "fullWidth" => true]];
        array_splice($rows, $insertAt, 0, [$row]);
        $insertAt++;
    }
    $j[0]["rows"] = $rows;
    return $j;
});
echo "Phase 5a fields added (metadata, labels, Meeting detail layout)\n";
'

docker exec -u www-data "$C" bin/command rebuild >/dev/null && echo rebuilt
docker exec -u www-data "$C" bin/command clear-cache >/dev/null && echo "cache cleared: Phase 5a fields are live"
