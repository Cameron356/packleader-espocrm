<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Lesson schedule for one client: https://office.example.com/?entryPoint=lessonSchedule&id=<contact>
// Every lesson and class the Contact is on, oldest first: date, time, length,
// location and address, dog, status, package. Made to send to a client who asks:
// Print / save as PDF, Copy as text, Download CSV (&format=csv), or
// Add to calendar (&format=ics: one .ics file with every lesson, times and locations).
// &upcoming=1 lists only lessons from today on.
// Needs an office-book login with read access to the Contact and to Meetings.
// Opened from the Contact's menu (Lesson schedule). Installed to
// custom/Espo/Custom/EntryPoints/LessonSchedule.php by scripts/packleader-office-flow-install.py.
namespace Espo\Custom\EntryPoints;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Custom\PackLeader\Footer;
use Espo\ORM\EntityManager;

class LessonSchedule implements EntryPoint
{
    private const TZ = 'America/New_York';
    private const WHERE = ['ClientHome' => "Client's home", 'HomeDepot' => 'Home Depot', 'Park' => 'Park',
        'Other' => 'Other'];
    private const STATUS = ['Planned' => 'Scheduled', 'Held' => 'Done', 'Not Held' => 'Cancelled'];

    public function __construct(private EntityManager $entityManager, private Acl $acl)
    {}

    public function run(Request $request, Response $response): void
    {
        $id = (string) $request->getQueryParam('id');
        if ($id === '') {
            throw new BadRequest();
        }
        $contact = $this->entityManager->getEntityById('Contact', $id);
        if (!$contact) {
            throw new NotFound();
        }
        if (!$this->acl->check($contact, 'read') || !$this->acl->check('Meeting', 'read')) {
            throw new Forbidden();
        }
        $tz = new \DateTimeZone(self::TZ);
        $upcoming = (bool) $request->getQueryParam('upcoming');

        $rows = [];
        $meetings = $this->entityManager->getRDBRepository('Contact')->getRelation($contact, 'meetings')
            ->order('dateStart', 'ASC')->limit(0, 500)->find();
        foreach ($meetings as $m) {
            if (!$this->acl->check($m, 'read') || !$m->get('dateStart')) {
                continue;
            }
            $start = (new \DateTime($m->get('dateStart'), new \DateTimeZone('UTC')))->setTimezone($tz);
            $end = $m->get('dateEnd') ? (new \DateTime($m->get('dateEnd'), new \DateTimeZone('UTC')))->setTimezone($tz) : null;
            if ($upcoming && $start->format('Y-m-d') < (new \DateTime('now', $tz))->format('Y-m-d')) {
                continue;
            }
            $loc = self::WHERE[$m->get('lessonLocation')] ?? '';
            $addr = trim((string) $m->get('lessonAddress'));
            $rows[] = [
                'date' => $start->format('D M j, Y'),
                'time' => $start->format('g:i A') . ($end ? ' – ' . $end->format('g:i A') : ''),
                'minutes' => $end ? (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60) : '',
                'where' => trim($loc . ($loc && $addr ? ', ' : '') . $addr),
                'dog' => (string) $m->get('dogName'),
                'what' => (string) $m->get('name'),
                'status' => self::STATUS[$m->get('status')] ?? (string) $m->get('status'),
                'package' => (string) $m->get('lessonPackageName'),
                'id' => $m->getId(),
                'startUtc' => (clone $start)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                'endUtc' => $end ? (clone $end)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z')
                    : (clone $start)->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                'held' => $m->get('status') === 'Not Held',
            ];
        }

        $name = trim((string) $contact->get('name'));
        if ($request->getQueryParam('format') === 'ics') {
            $this->ics($response, $name, $rows);
            return;
        }
        if ($request->getQueryParam('format') === 'csv') {
            $this->csv($response, $name, $rows);
            return;
        }
        $this->html($response, $name, $id, $rows, $upcoming);
    }

    /** One calendar file, every (not cancelled) lesson; UIDs are the lesson ids, so
     *  importing a newer file updates the same events instead of doubling them.
     *  @param array<int, array<string, mixed>> $rows */
    private function ics(Response $response, string $name, array $rows): void
    {
        $esc = fn ($t) => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], (string) $t);
        $fold = function (string $line): string {
            $out = [];
            while (strlen($line) > 75) {
                $chunk = mb_strcut($line, 0, 75, 'UTF-8');
                $out[] = $chunk;
                $line = ' ' . substr($line, strlen($chunk));
            }
            $out[] = $line;
            return implode("\r\n", $out);
        };
        $stamp = gmdate('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Pack Leader K9//Office//EN', 'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH', 'X-WR-CALNAME:' . $esc('Pack Leader K9 lessons')];
        foreach ($rows as $r) {
            if ($r['held']) {
                continue;
            }
            $summary = ($r['what'] ?: 'Lesson') . ' (Pack Leader K9)';
            $lines = array_merge($lines, ['BEGIN:VEVENT', 'UID:' . $r['id'] . '@example.com', 'DTSTAMP:' . $stamp,
                'DTSTART:' . $r['startUtc'], 'DTEND:' . $r['endUtc'], 'SUMMARY:' . $esc($summary),
                'DESCRIPTION:' . $esc('Pack Leader K9 · (727) 555-0100 · https://example.com')],
                $r['where'] ? ['LOCATION:' . $esc($r['where'])] : [],
                ['BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc($summary), 'TRIGGER:-P1D', 'END:VALARM',
                    'END:VEVENT']);
        }
        $lines[] = 'END:VCALENDAR';
        $file = preg_replace('/[^A-Za-z0-9]+/', '-', $name ?: 'client') . '-lessons.ics';
        $response->setHeader('Content-Type', 'text/calendar; charset=utf-8; method=PUBLISH');
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $file . '"');
        $response->setHeader('Cache-Control', 'no-store');
        $response->writeBody(implode("\r\n", array_map($fold, $lines)) . "\r\n");
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function csv(Response $response, string $name, array $rows): void
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Time', 'Minutes', 'Location', 'Dog', 'Lesson', 'Status', 'Package']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['date'], $r['time'], $r['minutes'], $r['where'], $r['dog'], $r['what'],
                $r['status'], $r['package']]);
        }
        rewind($out);
        $file = preg_replace('/[^A-Za-z0-9]+/', '-', $name ?: 'client') . '-lessons.csv';
        $response->setHeader('Content-Type', 'text/csv; charset=utf-8');
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $file . '"');
        $response->setHeader('Cache-Control', 'no-store');
        $response->writeBody(stream_get_contents($out));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function html(Response $response, string $name, string $id, array $rows, bool $upcoming): void
    {
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = "Lessons for " . $name . "\n\n";
        $trs = '';
        foreach ($rows as $r) {
            $text .= $r['date'] . ', ' . $r['time'] . ($r['dog'] ? ' · ' . $r['dog'] : '')
                . ($r['where'] ? "\n  Where: " . $r['where'] : '') . "\n  " . $r['status']
                . ($r['package'] ? ' · ' . $r['package'] : '') . "\n\n";
            $trs .= '<tr><td>' . $h($r['date']) . '</td><td>' . $h($r['time']) . '</td><td>' . $h($r['where'] ?: '—')
                . '</td><td>' . $h($r['dog']) . '</td><td>' . $h($r['status']) . '</td><td>' . $h($r['package']) . '</td></tr>';
        }
        if (!$rows) {
            $text .= "No lessons " . ($upcoming ? "coming up.\n" : "on file.\n");
        }
        $base = '?entryPoint=lessonSchedule&id=' . rawurlencode($id);
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>Lessons · ' . $h($name) . '</title>'
            . '<style>' . Booking::CSS . self::CSS . '</style></head><body class="sched">'
            . '<h1>Lessons for ' . $h($name) . '</h1>'
            . '<p class="bar noprint">'
            . ($upcoming ? '<a href="' . $h($base) . '">Show all</a>' : '<a href="' . $h($base . '&upcoming=1') . '">Upcoming only</a>')
            . ' <button type="button" onclick="window.print()">Print / PDF</button>'
            . ' <button type="button" id="copy">Copy as text</button>'
            . ' <a class="btn" href="' . $h($base . ($upcoming ? '&upcoming=1' : '') . '&format=csv') . '">Download CSV</a>'
            . ' <a class="btn" href="' . $h($base . ($upcoming ? '&upcoming=1' : '') . '&format=ics') . '">Add to calendar (.ics)</a></p>'
            . ($rows ? '<table><thead><tr><th>Date</th><th>Time</th><th>Location</th><th>Dog</th><th>Status</th><th>Package</th></tr></thead><tbody>'
                . $trs . '</tbody></table>'
                : '<p>No lessons ' . ($upcoming ? 'coming up.' : 'on file.') . '</p>')
            . '<textarea id="txt" hidden>' . $h(rtrim($text)) . '</textarea>'
            . '<p class="small">Pack Leader K9 · (727) 555-0100 · example.com</p>'
            . '<script>document.getElementById("copy").onclick=function(){var t=document.getElementById("txt").value,b=this;'
            . 'navigator.clipboard.writeText(t).then(function(){b.textContent="Copied";setTimeout(function(){b.textContent="Copy as text"},2000);});};</script>'
            . Footer::html()
            . '</body></html>';
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $response->writeBody($html);
    }

    private const CSS = <<<'CSS'
body.sched{max-width:60rem}
.bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:center}
.bar button,.bar a.btn{width:auto;margin:0;padding:.45rem .9rem;font-size:.95rem}
.bar a.btn{background:var(--green);color:var(--black);border-radius:.4rem;text-decoration:none;font-family:Arial,sans-serif;font-weight:700}
table{width:100%;border-collapse:collapse;font-family:Arial,sans-serif;font-size:.95rem;margin-top:1rem}
th,td{text-align:left;padding:.45rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--dim);font-weight:400;font-size:.85rem}
@media (max-width:40rem){thead{display:none}tr{display:block;border-bottom:1px solid var(--line);padding:.4rem 0}
  td{display:block;border:0;padding:.1rem 0}td:first-child{font-weight:700}}
@media print{body{background:#fff;color:#000}.noprint{display:none}th,td{border-color:#999}a{color:#000}}
CSS;
}
