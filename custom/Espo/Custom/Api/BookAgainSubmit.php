<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Receives the returning-client "Book again" form (EntryPoints/Client.php).
//   POST /api/v1/PackLeader/bookAgain/:id?t=<token>   multipart, no login (route noAuth)
// The contact's link is the credential (PackLeader/Client.php). Makes the same
// Request (Lead) + one RequestDog per dog as the public form (BookingSubmit),
// plus:
//   returningClient = true, returningContactId = the contact
//   RequestDog.dogRecordId = the dog on file, for each ticked dog
//   returningChanges = what differs from the records ("Phone changed · Max: medications")
// The office robot then uses that contact and those dogs, and on approval
// copies the client's edits onto them (scripts/packleader-office-flow.py).
// Shot rule (Rabies only, owner 2026-10-12): a rabies vaccine due before the booking
// ends needs a new date on or after the end, and an upload for that dog. DAPP and
// Bordetella dates are saved when given but never block. Otherwise the form comes back with a message.
// Every dog needs a birth date; a new dog also its vet records.
// Installed to custom/Espo/Custom/Api/BookAgainSubmit.php, route in
// custom/Espo/Custom/Resources/routes.json, by scripts/packleader-office-flow-install.py.
namespace Espo\Custom\Api;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\NotFound;
use Espo\Custom\PackLeader\Client as Link;
use Espo\ORM\Entity;

class BookAgainSubmit extends BookingSubmit
{
    private const SHOTS = ['rabiesDue' => 'rabies', 'dhppDue' => 'DAPP', 'bordetellaDue' => 'Bordetella'];
    private const REQUIRED = ['rabiesDue' => 'rabies'];
    // No dogSize: the office sets the weight tier from the vet records; the client never sees it.
    private const CARE = ['breed' => 'breed', 'age' => 'age', 'birthDate' => 'birth date', 'sex' => 'sex',
        'temperament' => 'temperament', 'foodSource' => 'food', 'feeding' => 'feeding',
        'medications' => 'medications', 'specialCare' => 'special care'];
    private const FLAGS = ['intact' => 'intact', 'crateTrained' => 'crate trained', 'preventionCurrent' => 'prevention'];

    public function process(Request $request): Response
    {
        $id = (string) $request->getRouteParam('id');
        $token = (string) $request->getQueryParam('t');
        $c = Link::find($this->entityManager, $id, $token);
        if (!$c) {
            throw new NotFound();
        }
        $page = '/?entryPoint=client&c=' . $id . '&t=' . $token;
        $p = $_POST;

        $t = (int) ($p['t'] ?? 0);
        if (trim((string) ($p['website'] ?? '')) !== '' || $t <= 0 || time() - $t < self::MIN_SECONDS) {
            return $this->go($page . '&done=1');
        }

        $s = fn ($k, $max = 255) => mb_substr(trim((string) ($p[$k] ?? '')), 0, $max);
        $email = $s('emailAddress');

        // Dogs on file that are coming; only this contact's dogs count.
        $mine = [];
        foreach (Link::dogs($this->entityManager, $c) as $d) {
            $mine[$d->getId()] = $d;
        }
        $known = [];
        foreach ((is_array($p['known'] ?? null) ? $p['known'] : []) as $dogId => $d) {
            if (is_array($d) && !empty($d['coming']) && isset($mine[$dogId])) {
                $known[$dogId] = array_merge($d, ['name' => $mine[$dogId]->get('name')]);   // names can't be changed here
            }
        }
        $newIn = is_array($p['dogs'] ?? null) ? array_slice($p['dogs'], 0, self::MAX_DOGS, true) : [];
        $newIn = array_filter($newIn, fn ($d) => is_array($d) && trim((string) ($d['name'] ?? '')) !== '');

        $missing = [];
        if ($s('lastName') === '') $missing[] = 'last name';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $missing[] = 'a valid email';
        if ($s('phoneNumber') === '') $missing[] = 'phone';
        elseif (!self::isUsPhone($s('phoneNumber'))) $missing[] = 'a 10-digit US phone number, like (727) 555-1234';
        if (!in_array($s('service'), self::SERVICES, true)) $missing[] = 'the service';
        if (empty($p['agreementAccepted'])) $missing[] = 'agreeing to the boarding terms';
        if (!$known && !$newIn) $missing[] = 'who\'s coming (tick a dog or add one)';
        $missing = array_merge($missing, $this->handoffProblems($s), $this->lessonLocationProblems($s));
        foreach ($known as $dogId => $d) {      // on file: records and weight are already there
            $missing = array_merge($missing, $this->dogProblems($d, 'known', $dogId, false));
        }
        foreach ($newIn as $i => $d) {
            $missing = array_merge($missing, $this->dogProblems($d, 'dogs', $i, true));
        }
        if ($missing) {
            return $this->go($page . '&err=' . rawurlencode('Please add ' . implode(', ', $missing) . ', then send again.'));
        }
        if (count($known) + count($newIn) > self::MAX_DOGS) {
            return $this->go($page . '&err=' . rawurlencode('At most ' . self::MAX_DOGS . ' dogs per request.'));
        }

        // Shot rule, per dog on file.
        $end = $this->date($s('depart')) ?? $this->date($s('arrive')) ?? gmdate('Y-m-d');
        $problems = [];
        foreach ($known as $dogId => $d) {
            $late = [];
            foreach (self::REQUIRED as $k => $label) {
                $old = (string) $mine[$dogId]->get($k);
                if ($old !== '' && $old >= $end) continue;
                $new = $this->date(trim((string) ($d[$k] ?? '')));
                if ($new === null || $new < $end) $late[] = $label;
            }
            $needFile = false;
            foreach (self::REQUIRED as $k => $label) {
                $old = (string) $mine[$dogId]->get($k);
                if ($old === '' || $old < $end) $needFile = true;
            }
            if ($late) {
                $problems[] = $mine[$dogId]->get('name') . ': ' . implode(', ', $late) . ' runs out before '
                    . $end . '. Enter the new date';
            } elseif ($needFile && !$this->hasFile('known', $dogId)) {
                $problems[] = $mine[$dogId]->get('name') . ': upload the updated rabies record';
            }
        }
        if ($problems) {
            return $this->go($page . '&err=' . rawurlencode(implode('. ', $problems) . '. Nothing was sent yet.'));
        }

        $all = array_merge(array_values($known), array_values($newIn));
        $names = array_map(fn ($d) => mb_substr(trim((string) $d['name']), 0, 80), $all);
        $lead = $this->newLead($p, $s, $email, $names, $all);

        $changes = $this->contactChanges($c, $s);
        $vetDog = null;
        foreach ($mine as $d) {
            if ($d->get('vetClinic') && (!$vetDog || $d->get('modifiedAt') > $vetDog->get('modifiedAt'))) $vetDog = $d;
        }
        foreach (['vetClinic', 'vetName', 'vetAddress', 'vetPhone', 'emergencyVet'] as $k) {
            if ($vetDog && $s($k) !== (string) $vetDog->get($k)) {
                $changes[] = 'Vet details changed';
                break;
            }
        }

        foreach ($known as $dogId => $d) {
            $rd = $this->newRequestDog($lead, $d);
            $rd->set('dogRecordId', $dogId);
            $this->entityManager->saveEntity($rd);
            $this->files($rd, 'known', $dogId);
            $dogChanges = $this->dogChanges($mine[$dogId], $rd);
            if ($dogChanges) $changes[] = $mine[$dogId]->get('name') . ': ' . implode(', ', $dogChanges);
        }
        foreach ($newIn as $i => $d) {
            $rd = $this->newRequestDog($lead, $d);
            $this->files($rd, 'dogs', $i);
            $changes[] = 'New dog: ' . mb_substr(trim((string) $d['name']), 0, 80);
        }

        $lead->set('returningClient', true);
        $lead->set('returningContactId', $c->getId());
        $lead->set('returningChanges', $changes ? implode(' · ', $changes) : 'Nothing changed');
        $this->entityManager->saveEntity($lead);
        Link::ensureLink($this->entityManager, $c);

        return $this->go($page . '&done=1');
    }

    private function contactChanges(Entity $c, callable $s): array
    {
        $out = [];
        foreach (['firstName' => 'Name', 'lastName' => 'Name', 'emailAddress' => 'Email', 'phoneNumber' => 'Phone',
                     'emergencyName' => 'Emergency contact', 'emergencyPhone' => 'Emergency contact'] as $k => $label) {
            $phone = in_array($k, ['phoneNumber', 'emergencyPhone'], true);
            $mine = $phone ? substr(preg_replace('/\D+/', '', $s($k)), -10) : $s($k);
            $theirs = $phone ? substr(preg_replace('/\D+/', '', (string) $c->get($k)), -10) : trim((string) $c->get($k));
            if (strcasecmp($mine, $theirs) !== 0 && !in_array($label . ' changed', $out, true)) {
                $out[] = $label . ' changed';
            }
        }
        return $out;
    }

    private function dogChanges(Entity $dog, Entity $rd): array
    {
        $out = [];
        foreach (self::CARE as $k => $label) {
            if (trim((string) $rd->get($k)) !== trim((string) $dog->get($k))) $out[] = $label;
        }
        foreach (self::FLAGS as $k => $label) {
            if ((bool) $rd->get($k) !== (bool) $dog->get($k)) $out[] = $label;
        }
        $a = (array) ($rd->get('goodWith') ?? []);
        $b = (array) ($dog->get('goodWith') ?? []);
        sort($a);
        sort($b);
        if ($a !== $b) $out[] = 'good with';
        foreach (self::SHOTS as $k => $label) {
            if ($rd->get($k) && $rd->get($k) !== $dog->get($k)) $out[] = $label . ' date updated';
        }
        return $out;
    }

}
