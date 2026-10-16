<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Receives the public booking page (EntryPoints/Booking.php).
//   POST /api/v1/PackLeader/booking   multipart, no login (route noAuth)
// Creates one Lead ("Request", status New, source Web Site) for the household
// and one RequestDog per dog, with shot-record files attached to each dog.
// The office robot (scripts/packleader-office-flow.py) takes it from there.
// Bots: a hidden "website" field and a minimum fill time; either one trips a
// fake success and nothing is saved. Files: PDF and photos only, checked by
// content, 10 MB each, 6 per dog. At most 8 dogs. Every dog needs a birth date,
// and a new dog its vet records (2026-09-28: boarding is priced by weight and
// puppy age). The weight tier is NOT asked: the office sets it from the vet records.
// Installed to custom/Espo/Custom/Api/BookingSubmit.php, route in
// custom/Espo/Custom/Resources/routes.json, by scripts/packleader-office-flow-install.py.
namespace Espo\Custom\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Entities\Attachment;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class BookingSubmit implements Action
{
    protected const PAGE = '/?entryPoint=booking';
    protected const MIN_SECONDS = 4;
    protected const MAX_DOGS = 8;
    protected const MAX_FILE = 10 * 1024 * 1024;
    protected const MAX_FILES_PER_DOG = 6;
    protected const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'];
    protected const HANDOFF_SERVICES = ['Boarding', 'BoardAndTrain'];
    protected const TIMES = ['Morning', 'Midday', 'Evening'];
    /** Lesson locations; labels in Booking::LOCATIONS, the installer and the robot. */
    protected const LOCATIONS = ['ClientHome', 'HomeDepot', 'Park', 'Other'];
    protected const SERVICES = ['Boarding', 'BoardAndTrain', 'DayStayAndTrain', 'PrivateLesson', 'GroupClass'];
    protected const GOOD = ['MaleDogs', 'FemaleDogs', 'LargeDogs', 'SmallDogs', 'Puppies', 'Cats', 'Children',
        'NewPeople', 'Unknown'];

    public function __construct(protected EntityManager $entityManager)
    {}

    public function process(Request $request): Response
    {
        $p = $_POST;

        // Bots: fake success, save nothing.
        $t = (int) ($p['t'] ?? 0);
        if (trim((string) ($p['website'] ?? '')) !== '' || $t <= 0 || time() - $t < self::MIN_SECONDS) {
            return $this->go(self::PAGE . '&done=1');
        }

        $s = fn ($k, $max = 255) => mb_substr(trim((string) ($p[$k] ?? '')), 0, $max);
        $email = $s('emailAddress');
        $dogsIn = is_array($p['dogs'] ?? null) ? array_slice($p['dogs'], 0, self::MAX_DOGS, true) : [];
        $dogsIn = array_filter($dogsIn, fn ($d) => is_array($d) && trim((string) ($d['name'] ?? '')) !== '');

        $missing = [];
        if ($s('lastName') === '') $missing[] = 'last name';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $missing[] = 'a valid email';
        if ($s('phoneNumber') === '') $missing[] = 'phone';
        elseif (!self::isUsPhone($s('phoneNumber'))) $missing[] = 'a 10-digit US phone number, like (727) 555-1234';
        if (!in_array($s('service'), self::SERVICES, true)) $missing[] = 'the service';
        if (empty($p['agreementAccepted'])) $missing[] = 'agreeing to the boarding terms';
        if (!$dogsIn) $missing[] = 'at least one dog with a name';
        $missing = array_merge($missing, $this->handoffProblems($s), $this->lessonLocationProblems($s));
        foreach ($dogsIn as $i => $d) {
            $missing = array_merge($missing, $this->dogProblems($d, 'dogs', $i, true));
        }
        if ($missing) {
            return $this->go(self::PAGE . '&err=' . rawurlencode('Please add ' . implode(', ', $missing) . ', then send again.'));
        }

        $names = array_map(fn ($d) => mb_substr(trim((string) $d['name']), 0, 80), array_values($dogsIn));

        $lead = $this->newLead($p, $s, $email, $names, $dogsIn);

        $skipped = [];
        foreach ($dogsIn as $i => $d) {
            $dog = $this->newRequestDog($lead, $d);
            $skipped = array_merge($skipped, $this->files($dog, 'dogs', $i));
        }

        return $this->go(self::PAGE . '&done=1');
    }

    /** One RequestDog on the request from a dog's form fields, saved. */
    protected function newRequestDog(Entity $lead, array $d): Entity
    {
        $g = fn ($k, $max = 255) => mb_substr(trim((string) ($d[$k] ?? '')), 0, $max);
        $dog = $this->entityManager->getNewEntity('RequestDog');
        $dog->set('name', $g('name', 80));
        $dog->set('requestId', $lead->getId());
        foreach (['breed' => 80, 'age' => 40] as $k => $max) {
            if ($g($k, $max) !== '') $dog->set($k, $g($k, $max));
        }
        if (in_array($g('sex'), ['Female', 'Male'], true)) $dog->set('sex', $g('sex'));
        if ($this->birthDate($g('birthDate')) !== null) $dog->set('birthDate', $this->birthDate($g('birthDate')));
        if (in_array($g('foodSource'), ['Owner', 'Facility'], true)) $dog->set('foodSource', $g('foodSource'));
        foreach (['temperament', 'feeding', 'medications', 'specialCare'] as $k) {
            if ($g($k, 5000) !== '') $dog->set($k, $g($k, 5000));
        }
        foreach (['rabiesDue', 'dhppDue', 'bordetellaDue'] as $k) {
            if ($this->date($g($k)) !== null) $dog->set($k, $this->date($g($k)));
        }
        foreach (['intact', 'crateTrained', 'preventionCurrent', 'healthCertified'] as $k) {
            $dog->set($k, !empty($d[$k]));
        }
        $good = is_array($d['goodWith'] ?? null) ? array_values(array_intersect(self::GOOD, $d['goodWith'])) : [];
        $dog->set('goodWith', $good);
        $this->entityManager->saveEntity($dog);
        return $dog;
    }

    /** The household Request (Lead), saved. Shared with BookAgainSubmit. */
    protected function newLead(array $p, callable $s, string $email, array $names, array $dogsIn): Entity
    {
        $lead = $this->entityManager->getNewEntity('Lead');
        foreach (['firstName' => 100, 'lastName' => 100, 'emergencyName' => 120, 'emergencyPhone' => 40,
                     'vetClinic' => 120, 'vetName' => 120, 'vetAddress' => 255, 'vetPhone' => 40,
                     'emergencyVet' => 160, 'preferredTime' => 255] as $k => $max) {
            $v = in_array($k, ['emergencyPhone', 'vetPhone'], true) ? self::phone($s($k, $max)) : $s($k, $max);
            if ($v !== '') $lead->set($k, $v);
        }
        $lead->set('emailAddress', $email);
        $lead->set('phoneNumber', self::phone($s('phoneNumber', 40)));
        $lead->set('service', $s('service'));
        if (in_array($s('service'), self::HANDOFF_SERVICES, true)) {
            foreach (['dropOffTime', 'pickUpTime'] as $k) {
                if (in_array($s($k), self::TIMES, true)) $lead->set($k, $s($k));
            }
        }
        foreach (['arrive', 'depart'] as $k) {
            if ($this->date($s($k)) !== null) $lead->set($k, $this->date($s($k)));
        }
        if ($s('service') === 'PrivateLesson' && in_array($s('lessonLocation'), self::LOCATIONS, true)) {
            $lead->set('lessonLocation', $s('lessonLocation'));
            if ($s('lessonAddress', 255) !== '') $lead->set('lessonAddress', $s('lessonAddress', 255));
        }
        if ($s('description', 5000) !== '') $lead->set('description', $s('description', 5000));
        $lead->set('photoConsent', !empty($p['photoConsent']));
        $lead->set('agreementAccepted', true);
        $lead->set('status', 'New');
        $lead->set('source', 'Web Site');
        $lead->set('dogName', mb_substr(count($names) === 1 ? $names[0]
            : implode(', ', array_slice($names, 0, -1)) . ' & ' . end($names), 0, 80));
        $this->entityManager->saveEntity($lead);
        return $lead;
    }

    /** Save this dog's uploaded files; returns reasons for any skipped. */
    protected function files(Entity $dog, string $group, $i): array
    {
        $f = $_FILES[$group] ?? null;
        $names = $f['name'][$i]['records'] ?? null;
        if (!is_array($names)) return [];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $saved = 0;
        $skipped = [];
        foreach ($names as $k => $rawName) {
            if ($rawName === '' || $rawName === null) continue;
            if ($saved >= self::MAX_FILES_PER_DOG) { $skipped[] = 'too many files'; break; }
            $tmp = $f['tmp_name'][$i]['records'][$k] ?? '';
            if (($f['error'][$i]['records'][$k] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $skipped[] = 'upload failed'; continue; }
            $size = filesize($tmp);
            if (!$size || $size > self::MAX_FILE) { $skipped[] = 'over 10 MB'; continue; }
            $mime = $finfo->file($tmp);
            if (!isset(self::TYPES[$mime])) { $skipped[] = 'not a PDF or photo'; continue; }
            $name = mb_substr(preg_replace('/[^\w.\- ]+/u', '_', basename((string) $rawName)) ?: 'shot-record', 0, 100);
            if (!preg_match('/\.[A-Za-z0-9]{2,5}$/', $name)) $name .= '.' . self::TYPES[$mime];
            $a = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getNew();
            $a->setName($name);
            $a->setType($mime);
            $a->setRole(Attachment::ROLE_ATTACHMENT);
            $a->setTargetField('shotRecords');
            $a->setParent($dog);
            $a->setContents((string) file_get_contents($tmp));
            $this->entityManager->saveEntity($a);
            $saved++;
        }
        return $skipped;
    }

    /** What a dog on the form still needs, as "Rex's birth date". Every dog needs a birth date;
     *  a new dog (not one already on file) also needs its vet records. */
    protected function dogProblems(array $d, string $group, $i, bool $newDog): array
    {
        $name = mb_substr(trim((string) ($d['name'] ?? '')), 0, 80) ?: 'your dog';
        $out = [];
        $raw = mb_substr(trim((string) ($d['birthDate'] ?? '')), 0, 20);
        if ($raw === '') $out[] = $name . '\'s birth date';
        elseif ($this->birthDate($raw) === null) $out[] = $name . '\'s birth date ("' . $raw . '" doesn\'t look right: check the year)';
        if ($newDog) {
            $p = $this->recordsProblem($group, $i, $name);
            if ($p !== null) $out[] = $p;
        }
        return $out;
    }

    /** A birth date: a real date, not in the future, and less than 30 years ago. */
    protected function birthDate(string $v): ?string
    {
        $v = $this->date($v);
        return $v !== null && $v <= date('Y-m-d') && $v >= date('Y-m-d', strtotime('-30 years')) ? $v : null;
    }

    /** Null if at least one of this dog's files is a usable PDF or photo, else what to ask for.
     *  Names the files that can't be used, instead of dropping them silently. */
    protected function recordsProblem(string $group, $i, string $name): ?string
    {
        $f = $_FILES[$group] ?? null;
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $bad = [];
        foreach ((array) ($f['name'][$i]['records'] ?? []) as $k => $raw) {
            if ($raw === '' || $raw === null) continue;
            $tmp = $f['tmp_name'][$i]['records'][$k] ?? '';
            $ok = ($f['error'][$i]['records'][$k] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($tmp);
            $size = $ok ? filesize($tmp) : 0;
            if ($ok && $size && $size <= self::MAX_FILE && isset(self::TYPES[$finfo->file($tmp)])) return null;
            $bad[] = '"' . mb_substr(basename((string) $raw), 0, 60) . '"'
                . ($ok && $size > self::MAX_FILE ? ' is over 10 MB' : ' isn\'t a PDF or photo');
        }
        return $bad ? $name . '\'s vet records as a PDF or photo (' . implode(', ', $bad) . ')'
                    : $name . '\'s vet records (shots and weight)';
    }

    protected function hasFile(string $group, $i): bool
    {
        foreach ((array) ($_FILES[$group]['name'][$i]['records'] ?? []) as $k => $name) {
            if ($name !== '' && ($_FILES[$group]['error'][$i]['records'][$k] ?? 1) === UPLOAD_ERR_OK) return true;
        }
        return false;
    }

    /** Private lessons need a location and its address or place. */
    protected function lessonLocationProblems(callable $s): array
    {
        if ($s('service') !== 'PrivateLesson') return [];
        $out = [];
        if (!in_array($s('lessonLocation'), self::LOCATIONS, true)) {
            $out[] = 'where you\'d like lessons';
        }
        if ($s('lessonAddress') === '') $out[] = 'the lesson address or place';
        return $out;
    }

    /** Boarding and board & train need a drop-off and a pick-up time (any block, any day). */
    protected function handoffProblems(callable $s): array
    {
        if (!in_array($s('service'), self::HANDOFF_SERVICES, true)) return [];
        $out = [];
        foreach (['dropOffTime' => 'drop-off', 'pickUpTime' => 'pick-up'] as $k => $label) {
            if (!in_array($s($k), self::TIMES, true)) {
                $out[] = 'a ' . $label . ' time';
            }
        }
        return $out;
    }

    /** US numbers (every client is in the US) as +17275551234, the only form the
     *  office book's API accepts on a Contact. "(727) 555-1234", "727.555.1234" and
     *  "+1 727 555 1234" all work. Anything else is kept as typed. */
    protected static function phone(string $v): string
    {
        return self::isUsPhone($v) ? '+1' . substr(preg_replace('/\D+/', '', $v), -10) : $v;
    }

    protected static function isUsPhone(string $v): bool
    {
        $d = preg_replace('/\D+/', '', $v);
        return strlen($d) === 10 || (strlen($d) === 11 && $d[0] === '1');
    }

    protected function date(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
    }

    protected function go(string $to): Response
    {
        $r = ResponseComposer::empty();
        $r->setStatus(303);
        $r->setHeader('Location', $to);
        return $r;
    }
}
