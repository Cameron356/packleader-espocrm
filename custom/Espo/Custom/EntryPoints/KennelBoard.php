<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Kennel board: https://office.example.com/?entryPoint=kennelBoard
// One screen for the dogs here now, arriving in the next day, and leaving
// today, with what's needed to care for them: feeding, medications, special
// care, temperament, shots, owner and vet contacts, drop-off/pick-up times.
// Needs an office-book login (it shows medications and phone numbers) and read
// access to Bookings. "Kennel board" tab in the top bar. Refreshes every 5 min.
//   Here now   status In house (flagged if past its pick-up date), or Confirmed
//              with a start date before today (flagged "not checked in")
//   Arriving   Confirmed, starting today (not in yet) or tomorrow
//   Leaving    here now and the end date is today
// Care details come from the booking (what the client said for this stay),
// falling back to the dog record.
// Installed to custom/Espo/Custom/EntryPoints/KennelBoard.php by
// scripts/packleader-office-flow-install.py.
namespace Espo\Custom\EntryPoints;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class KennelBoard implements EntryPoint
{
    private const TZ = 'America/New_York';
    private const SERVICE = ['Boarding' => 'Boarding', 'BoardAndTrain' => 'Board & train',
        'DayStayAndTrain' => 'Day training'];
    private const TIMES = ['Morning' => 'Morning 8–10', 'Midday' => 'Midday 12–2', 'Evening' => 'Evening 5–7'];
    private const GOOD = ['MaleDogs' => 'male dogs', 'FemaleDogs' => 'female dogs', 'LargeDogs' => 'large dogs',
        'SmallDogs' => 'small dogs', 'Puppies' => 'puppies', 'Cats' => 'cats', 'Children' => 'children',
        'NewPeople' => 'new people', 'Unknown' => 'not sure'];
    private const SHOTS = ['rabiesDue' => 'Rabies', 'dhppDue' => 'DAPP', 'bordetellaDue' => 'Bordetella'];

    public function __construct(private EntityManager $entityManager, private Acl $acl)
    {}

    public function run(Request $request, Response $response): void
    {
        if (!$this->acl->check('Stay', 'read') || !$this->acl->check('Dog', 'read')) {
            throw new Forbidden();
        }
        $tz = new \DateTimeZone(self::TZ);
        $now = new \DateTime('now', $tz);
        $today = $now->format('Y-m-d');
        $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');

        $here = [];
        $arriving = [];
        // Every checked-in dog (even past its pick-up date), plus confirmed stays that
        // overlap today or start tomorrow.
        foreach ($this->entityManager->getRDBRepository('Stay')
                     ->where(['OR' => [
                         ['status' => 'InHouse'],
                         ['status' => 'Confirmed', 'depart>=' => $today, 'arrive<=' => $tomorrow],
                     ]])
                     ->order('arrive', 'ASC')->limit(0, 200)->find() as $s) {
            if ($s->get('status') === 'InHouse' || (string) $s->get('arrive') < $today) {
                $here[] = $s;       // in, or should be in and not checked in
            } else {
                $arriving[] = $s;   // Confirmed, starting today or tomorrow
            }
        }
        $leaving = array_values(array_filter($here, fn ($s) => $s->get('depart') === $today));
        usort($here, fn ($a, $b) => strcmp((string) $a->get('dogName'), (string) $b->get('dogName')));

        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $count = fn ($n, $one) => $n . ' ' . $one . ($n === 1 ? '' : 's');

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<meta http-equiv="refresh" content="300"><title>Kennel board · Pack Leader K9</title>'
            . '<style>' . Booking::CSS . self::CSS . '</style></head><body class="board">'
            . '<header><h1>Kennel board</h1><p class="small">' . $h($now->format('l, M j · g:i A'))
            . ' · updates every 5 minutes · <a href="/#Stay">Bookings</a></p>'
            . '<p class="counts">' . $count(count($here), 'dog') . ' here · ' . count($arriving) . ' arriving · '
            . count($leaving) . ' leaving today</p></header>'
            . '<h2>Here now</h2>'
            . ($here ? '<div class="cards">' . implode('', array_map(fn ($s) => $this->card($s, $today, $tomorrow, $h, 'here'), $here)) . '</div>'
                : '<p class="small">No dogs in right now.</p>')
            . '<h2>Arriving (today and tomorrow)</h2>'
            . ($arriving ? '<div class="cards">' . implode('', array_map(fn ($s) => $this->card($s, $today, $tomorrow, $h, 'arriving'), $arriving)) . '</div>'
                : '<p class="small">Nobody arriving in the next day.</p>')
            . '</body></html>';

        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $response->writeBody($html);
    }

    /** "4 months" / "3 years" from the birth date (it stays right as the dog grows);
     *  the typed Age when there is no birth date. */
    private static function ageText(Entity $dog): string
    {
        $b = (string) $dog->get('birthDate');
        if ($b === '') return (string) $dog->get('age');
        $m = (new \DateTime($b))->diff(new \DateTime('today'));
        $months = $m->y * 12 + $m->m;
        return $months < 24 ? $months . ' month' . ($months === 1 ? '' : 's') : $m->y . ' years';
    }

    private function card(Entity $s, string $today, string $tomorrow, callable $h, string $section): string
    {
        $dog = $s->get('dogId') ? $this->entityManager->getEntityById('Dog', $s->get('dogId')) : null;
        $owner = $dog && $dog->get('contactId') ? $this->entityManager->getEntityById('Contact', $dog->get('contactId')) : null;
        // this stay's answer first, then the dog record
        $care = fn ($f) => trim((string) ($s->get($f) ?: ($dog ? $dog->get($f) : '')));
        $day = fn ($d) => $d ? (new \DateTime($d))->format('D M j') : '';

        $flags = [];
        if ($section === 'here' && $s->get('status') !== 'InHouse') $flags[] = '<span class="flag">Not checked in</span>';
        if ($section === 'here' && (string) $s->get('depart') < $today) $flags[] = '<span class="flag warn">Past pick-up date</span>';
        if ($s->get('depart') === $today) $flags[] = '<span class="flag go">Leaving today'
            . ($s->get('pickUpTime') ? ' · ' . $h(self::TIMES[$s->get('pickUpTime')] ?? '') : '') . '</span>';
        if ($section === 'arriving') $flags[] = '<span class="flag in">' . ($s->get('arrive') === $today ? 'Today' : 'Tomorrow')
            . ($s->get('dropOffTime') ? ' · ' . $h(self::TIMES[$s->get('dropOffTime')] ?? '') : '') . '</span>';
        if ($s->get('paymentStatus') !== 'Paid') $flags[] = '<span class="flag warn">Unpaid</span>';

        $shots = [];
        $shotWarn = false;
        foreach (self::SHOTS as $k => $label) {
            $v = $dog ? (string) $dog->get($k) : '';
            // Only Rabies is required (owner, 2026-10-12); the others are information.
            $bad = $k === 'rabiesDue' && ($v === '' || $v < (string) $s->get('depart'));
            $shotWarn = $shotWarn || $bad;
            $shots[] = '<span class="' . ($bad ? 'bad' : '') . '">' . $label . ' ' . ($v !== '' ? $h($day($v)) : ($k === 'rabiesDue' ? 'missing' : 'not on file')) . '</span>';
        }

        $facts = array_filter([
            $dog ? $h($dog->get('breed')) : '',
            $h(Booking::SIZES[$care('dogSize')] ?? ['Over40' => 'over 40 lbs', 'Under40' => 'under 40 lbs'][$care('dogSize')] ?? ''),
            $dog ? $h(trim($dog->get('sex') . ' ' . (self::ageText($dog) !== '' ? '· ' . self::ageText($dog) : ''))) : '',
            $dog && $dog->get('intact') ? 'intact' : '',
            $dog && $dog->get('crateTrained') ? 'crate trained' : ($dog ? 'not crate trained' : ''),
        ]);
        $good = $dog ? array_map(fn ($g) => self::GOOD[$g] ?? $g, (array) ($dog->get('goodWith') ?? [])) : [];
        $food = ['Owner' => 'Owner\'s food', 'Facility' => 'Our food'][$care('foodSource')] ?? '';

        $row = fn ($label, $v, $cls = '') => $v !== '' ? '<dt>' . $label . '</dt><dd class="' . $cls . '">' . nl2br($h($v)) . '</dd>' : '';
        $phone = fn ($p) => $p ? '<a href="tel:' . $h(preg_replace('/[^\d+]/', '', (string) $p)) . '">' . $h($p) . '</a>' : '';

        return '<article class="card' . ($care('medications') !== '' ? ' meds' : '') . '">'
            . '<div class="top"><h3><a href="/#Stay/view/' . $h($s->getId()) . '">' . $h($dog ? $dog->get('name') : $s->get('name')) . '</a></h3>'
            . '<span class="svc">' . $h(self::SERVICE[$s->get('service')] ?? $s->get('service'))
            . ($s->get('run') ? ' · run ' . $h($s->get('run')) : '') . '</span></div>'
            . '<p class="small">' . implode(' · ', $facts) . '</p>'
            . '<p class="dates">' . $h($day($s->get('arrive'))) . ' → ' . $h($day($s->get('depart')))
            . ($s->get('dropOffTime') ? ' <span class="small">(in ' . $h(strtolower(self::TIMES[$s->get('dropOffTime')] ?? '')) . ')</span>' : '')
            . ($s->get('pickUpTime') ? ' <span class="small">(out ' . $h(strtolower(self::TIMES[$s->get('pickUpTime')] ?? '')) . ')</span>' : '')
            . '</p>'
            . ($flags ? '<p class="flags">' . implode(' ', $flags) . '</p>' : '')
            . '<dl>'
            . $row('Medications', $care('medications') ?: 'None', $care('medications') !== '' ? 'hot' : '')
            . $row('Feeding', trim($care('feeding') . ($food ? ' (' . $food . ')' : '')))
            . $row('Special care', $care('specialCare'))
            . $row('Temperament', $dog ? trim((string) $dog->get('temperament')) : '')
            . $row('Good with', implode(', ', $good))
            . '<dt>Shots</dt><dd class="shots' . ($shotWarn ? ' warnline' : '') . '">' . implode(' · ', $shots) . '</dd>'
            . ($owner ? '<dt>Owner</dt><dd>' . $h($owner->get('name')) . ' ' . $phone($owner->get('phoneNumber')) . '</dd>' : '')
            . (($s->get('emergencyName') ?: ($owner ? $owner->get('emergencyName') : ''))
                ? '<dt>Emergency</dt><dd>' . $h($s->get('emergencyName') ?: $owner->get('emergencyName')) . ' '
                  . $phone($s->get('emergencyPhone') ?: ($owner ? $owner->get('emergencyPhone') : '')) . '</dd>' : '')
            . ($dog && $dog->get('vetClinic') ? '<dt>Vet</dt><dd>' . $h($dog->get('vetClinic'))
                . ($dog->get('vetName') ? ' (' . $h($dog->get('vetName')) . ')' : '') . ' ' . $phone($dog->get('vetPhone')) . '</dd>' : '')
            . '<dt>ER vet</dt><dd>' . $h(($dog ? $dog->get('emergencyVet') : '') ?: 'VEG Clearwater') . '</dd>'
            . ($s->get('description') ? $row('Notes', trim((string) $s->get('description'))) : '')
            . '</dl></article>';
    }

    private const CSS = <<<'CSS'
body.board{max-width:none;padding:1rem}
header{text-align:center}header h1{margin:0}.counts{font-family:Arial,sans-serif;font-weight:700;color:var(--green);margin:.25rem 0}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(20rem,1fr));gap:1rem}
.card{border:1px solid var(--line);border-radius:.6rem;padding:.9rem 1rem;background:#0a1210}
.card.meds{border-color:var(--green)}
.card .top{display:flex;justify-content:space-between;align-items:baseline;gap:.5rem}
.card h3{margin:0;font-family:Arial,sans-serif;font-size:1.35rem}.card h3 a{text-decoration:none}
.svc{font-family:Arial,sans-serif;font-size:.85rem;color:var(--dim)}
.card p{margin:.2rem 0}.dates{font-family:Arial,sans-serif}
.flags{display:flex;flex-wrap:wrap;gap:.35rem}
.flag{font-family:Arial,sans-serif;font-size:.8rem;padding:.1rem .45rem;border-radius:1rem;border:1px solid var(--dim)}
.flag.go,.flag.in{border-color:var(--green);color:var(--green)}.flag.warn{border-color:#d9a441;color:#d9a441}
dl{display:grid;grid-template-columns:7.5rem 1fr;gap:.3rem .6rem;margin:.6rem 0 0;font-size:.95rem}
dt{color:var(--dim);font-family:Arial,sans-serif;font-size:.85rem}dd{margin:0}
dd.hot{color:#fff;font-weight:700}
.shots .bad{color:#e07b67;font-weight:700}
@media print{body{background:#fff;color:#000}.card{break-inside:avoid;background:#fff;border-color:#999}a{color:#000}}
CSS;
}
