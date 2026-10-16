<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Private one-way iCalendar feed of the office book (stays, lessons, calls, tasks) for phones.
// Live: /opt/docker/espocrm/calendar.php, bind-mounted as public/calendar.php (docs/packleader-office.md,
// "Private calendar subscription"). Token: CALENDAR_TOKEN in the stack's .env. No rates, payment,
// medication or phone numbers in it. Reminders (VALARM) since 2026-09-28; iPhone keeps them only if
// the subscription's "Remove Alerts" is off.
declare(strict_types=1);

$token = getenv("CALENDAR_TOKEN") ?: "";
$given = isset($_GET["token"]) && is_string($_GET["token"]) ? $_GET["token"] : "";
if ($token === "" || $given === "" || !hash_equals($token, $given)) {
    http_response_code(404);
    header("Content-Type: text/plain; charset=utf-8");
    header("Cache-Control: no-store");
    echo "Not found\n";
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . getenv("ESPOCRM_DATABASE_HOST") . ";dbname=espocrm;charset=utf8mb4",
        getenv("ESPOCRM_DATABASE_USER") ?: "",
        getenv("ESPOCRM_DATABASE_PASSWORD") ?: "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $lines = build_calendar($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    header("Content-Type: text/plain; charset=utf-8");
    header("Cache-Control: no-store");
    echo "Calendar unavailable\n";
    exit;
}

header("Content-Type: text/calendar; charset=utf-8");
header("Content-Disposition: inline; filename=\"packleader.ics\"");
header("Cache-Control: private, no-store");
echo implode("\r\n", $lines) . "\r\n";

function build_calendar(PDO $pdo): array
{
    $lines = [
        "BEGIN:VCALENDAR",
        "VERSION:2.0",
        "PRODID:-//Pack Leader K9//Espo calendar//EN",
        "CALSCALE:GREGORIAN",
        "METHOD:PUBLISH",
        "X-WR-CALNAME:Pack Leader",
        "X-WR-TIMEZONE:America/New_York",
        "REFRESH-INTERVAL;VALUE=DURATION:PT15M",
        "X-PUBLISHED-TTL:PT15M",
    ];
    foreach (events($pdo) as $event) {
        foreach ($event as $line) {
            $lines[] = fold_ics($line);
        }
    }
    $lines[] = "END:VCALENDAR";
    return $lines;
}

function events(PDO $pdo): array
{
    $out = [];
    $stays = $pdo->query(
        "SELECT s.id, s.name, s.status, s.service, s.run, s.arrive, s.depart, s.modified_at, d.name AS dog
         FROM stay s
         LEFT JOIN dog d ON d.id = s.dog_id AND d.deleted = 0
         WHERE s.deleted = 0
           AND s.arrive IS NOT NULL
           AND (s.depart IS NULL OR s.depart >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
           AND s.status NOT IN (\"Canceled\", \"Cancelled\", \"Declined\")"
    );
    foreach ($stays as $row) {
        $out[] = all_day(
            "stay-" . $row["id"],
            label($row["dog"], $row["name"], $row["service"] ?: "Boarding"),
            details($row["status"], $row["service"], $row["run"]),
            $row["arrive"],
            $row["depart"] ?: $row["arrive"],
            $row["modified_at"],
            "-PT6H"
        );
    }

    $meetings = $pdo->query(
        "SELECT m.id, m.name, m.status, m.service, m.class_type, m.is_all_day,
                m.date_start, m.date_end, m.date_start_date, m.date_end_date, m.modified_at, d.name AS dog
         FROM meeting m
         LEFT JOIN dog d ON d.id = m.dog_id AND d.deleted = 0
         WHERE m.deleted = 0
           AND m.status IN (\"Planned\", \"Held\")
           AND (
             (m.is_all_day = 1 AND m.date_start_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
             OR (m.is_all_day = 0 AND m.date_start >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY))
           )"
    );
    foreach ($meetings as $row) {
        $summary = label($row["dog"], $row["name"], $row["service"] ?: "Meeting");
        $detail = details($row["status"], $row["service"], $row["class_type"]);
        if ((int) $row["is_all_day"] === 1 && $row["date_start_date"]) {
            $out[] = all_day("meeting-" . $row["id"], $summary, $detail, $row["date_start_date"], $row["date_end_date"] ?: $row["date_start_date"], $row["modified_at"], "-PT6H");
        } elseif ($row["date_start"]) {
            $out[] = timed("meeting-" . $row["id"], $summary, $detail, $row["date_start"], $row["date_end"] ?: $row["date_start"], $row["modified_at"]);
        }
    }

    $calls = $pdo->query(
        "SELECT id, name, status, date_start, date_end, modified_at
         FROM `call`
         WHERE deleted = 0
           AND status IN (\"Planned\", \"Held\")
           AND date_start >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
    );
    foreach ($calls as $row) {
        if (!$row["date_start"]) {
            continue;
        }
        $out[] = timed("call-" . $row["id"], $row["name"] ?: "Call", details($row["status"], "Call", null), $row["date_start"], $row["date_end"] ?: $row["date_start"], $row["modified_at"]);
    }

    $tasks = $pdo->query(
        "SELECT id, name, status, date_start, date_end, date_start_date, date_end_date, modified_at
         FROM task
         WHERE deleted = 0
           AND status IN (\"Not Started\", \"Started\")
           AND (
             date_end >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
             OR date_start >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
             OR date_end_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           )"
    );
    foreach ($tasks as $row) {
        $summary = $row["name"] ?: "Task";
        $detail = details($row["status"], "Task", null);
        if ($row["date_start"]) {
            $out[] = timed("task-" . $row["id"], $summary, $detail, $row["date_start"], $row["date_end"] ?: $row["date_start"], $row["modified_at"]);
        } elseif ($row["date_end_date"] || $row["date_start_date"]) {
            $day = $row["date_end_date"] ?: $row["date_start_date"];
            $out[] = all_day("task-" . $row["id"], $summary, $detail, $day, $day, $row["modified_at"], "PT9H");
        }
    }
    return $out;
}

function label(?string $dog, ?string $name, string $fallback): string
{
    $dog = trim((string) $dog);
    $name = trim((string) $name);
    if ($dog !== "" && $name !== "" && stripos($name, $dog) === false) {
        return $dog . " — " . $name;
    }
    if ($name !== "") {
        return $name;
    }
    if ($dog !== "") {
        return $dog . " — " . $fallback;
    }
    return $fallback;
}

function details(?string $status, ?string $kind, ?string $extra): string
{
    $parts = [];
    foreach ([$kind, $extra, $status] as $part) {
        $part = trim((string) $part);
        if ($part !== "") {
            $parts[] = $part;
        }
    }
    return implode(" · ", $parts);
}

/** $alarm: VALARM trigger relative to the start. All-day starts at local midnight, so "-PT6H" is
 *  6 PM the evening before and "PT9H" is 9 AM that day. */
function all_day(string $uid, string $summary, string $detail, string $start, string $end, ?string $modified, string $alarm = "-PT6H"): array
{
    $endDate = new DateTimeImmutable($end);
    $until = $endDate->modify("+1 day")->format("Ymd");
    return event_lines($uid, $summary, $detail, "DTSTART;VALUE=DATE:" . ymd($start), "DTEND;VALUE=DATE:" . $until, $modified, $alarm);
}

/** Timed events (lessons, calls): a reminder an hour before. */
function timed(string $uid, string $summary, string $detail, string $start, string $end, ?string $modified): array
{
    return event_lines($uid, $summary, $detail, "DTSTART:" . stamp($start), "DTEND:" . stamp($end), $modified, "-PT1H");
}

function event_lines(string $uid, string $summary, string $detail, string $startLine, string $endLine, ?string $modified, ?string $alarm = null): array
{
    $lines = [
        "BEGIN:VEVENT",
        "UID:" . $uid . "@example.com",
        "DTSTAMP:" . gmdate("Ymd\\THis\\Z"),
        $startLine,
        $endLine,
        "SUMMARY:" . ics_text($summary),
    ];
    if ($detail !== "") {
        $lines[] = "DESCRIPTION:" . ics_text($detail);
    }
    if ($modified) {
        $lines[] = "LAST-MODIFIED:" . stamp($modified);
    }
    if ($alarm !== null) {
        array_push($lines, "BEGIN:VALARM", "ACTION:DISPLAY", "DESCRIPTION:" . ics_text($summary),
                   "TRIGGER:" . $alarm, "END:VALARM");
    }
    $lines[] = "END:VEVENT";
    return $lines;
}

function ymd(string $date): string
{
    return (new DateTimeImmutable(substr($date, 0, 10)))->format("Ymd");
}

function stamp(string $utc): string
{
    $dt = new DateTimeImmutable($utc, new DateTimeZone("UTC"));
    return $dt->format("Ymd\\THis\\Z");
}

function ics_text(string $value): string
{
    return str_replace(
        ["\\", "\r\n", "\n", "\r", ",", ";"],
        ["\\\\", "\\n", "\\n", "\\n", "\\,", "\\;"],
        $value
    );
}

function fold_ics(string $line): string
{
    if (strlen($line) <= 73) {
        return $line;
    }
    $out = substr($line, 0, 73);
    $rest = substr($line, 73);
    while ($rest !== "") {
        $out .= "\r\n " . substr($rest, 0, 72);
        $rest = substr($rest, 72);
    }
    return $out;
}
