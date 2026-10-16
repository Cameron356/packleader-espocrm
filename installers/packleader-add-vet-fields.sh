#!/usr/bin/env bash
# Add the vet-record-reader office-book fields to a Dog (pk9-app docs/specs/vet-record-reader.md):
# weight (float lbs), weightDate, rabiesGiven, dhppGiven, bordetellaGiven, leptospirosisDue,
# influenzaDue, microchip, spayNeuterDate, vetRecordDate. The reader never writes dogSize.
# Modelled on packleader-add-phase5a-fields.sh. Idempotent.
# Defaults to the live container (espocrm); pass pk9dev-espocrm for the dev book.
#   cd ~/homelab && bash scripts/packleader-add-vet-fields.sh pk9dev-espocrm
set -euo pipefail

C="${1:-espocrm}"
B="$HOME/espocrm-custom-backup-$(date +%Y%m%d-%H%M%S)-vet-fields.tgz"

# 1. Back up custom/ first (same convention as the other field scripts).
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
$dates = ["type" => "date", "isCustom" => true];
editStrict("$base/metadata/entityDefs/Dog.json", function ($j) use ($dates) {
    $j["fields"]["weight"] = ["type" => "float", "isCustom" => true];
    $j["fields"]["weightDate"] = $dates;
    $j["fields"]["rabiesGiven"] = $dates;
    $j["fields"]["dhppGiven"] = $dates;
    $j["fields"]["bordetellaGiven"] = $dates;
    $j["fields"]["leptospirosisDue"] = $dates;
    $j["fields"]["influenzaDue"] = $dates;
    $j["fields"]["microchip"] = ["type" => "varchar", "maxLength" => 64, "isCustom" => true];
    $j["fields"]["spayNeuterDate"] = $dates;
    $j["fields"]["vetRecordDate"] = $dates;
    return $j;
});
editStrict("$base/i18n/en_US/Dog.json", function ($j) {
    $j["fields"]["weight"] = "Weight (lbs)";
    $j["fields"]["weightDate"] = "Weight date";
    $j["fields"]["rabiesGiven"] = "Rabies given";
    $j["fields"]["dhppGiven"] = "DAPP given";
    $j["fields"]["bordetellaGiven"] = "Bordetella given";
    $j["fields"]["leptospirosisDue"] = "Leptospirosis due";
    $j["fields"]["influenzaDue"] = "Influenza due";
    $j["fields"]["microchip"] = "Microchip";
    $j["fields"]["spayNeuterDate"] = "Spay/neuter date";
    $j["fields"]["vetRecordDate"] = "Vet record date";
    return $j;
});
editLayout("$base/layouts/Dog/detail.json", function ($j) {
    $rows = $j[0]["rows"];
    $add = [
        [["name" => "vetRecordDate"], ["name" => "weight"], ["name" => "weightDate"]],
        [["name" => "rabiesGiven"], ["name" => "dhppGiven"], ["name" => "bordetellaGiven"]],
        [["name" => "leptospirosisDue"], ["name" => "influenzaDue"], ["name" => "microchip"]],
        [["name" => "spayNeuterDate"], false, false],
    ];
    foreach ($add as $row) {
        if (!hasName($rows, $row[0]["name"])) $rows[] = $row;
    }
    $j[0]["rows"] = $rows;
    return $j;
});
echo "Vet-record fields added (metadata, labels, Dog detail layout)\n";
'

docker exec -u www-data "$C" bin/command rebuild >/dev/null && echo rebuilt
docker exec -u www-data "$C" bin/command clear-cache >/dev/null && echo "cache cleared: vet-record fields are live"
