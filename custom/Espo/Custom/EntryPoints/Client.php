<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Returning-client page: https://office.example.com/?entryPoint=client&c=<contact>&t=<token>
// The link is the sign-in (PackLeader/Client.php): the permanent Client link
// from the office emails, or a 24-hour link from "Booked with us before?".
// Shows what's coming up, lesson packages (lessons left), what's still to pay, requests waiting for approval,
// and a "Book again" form pre-filled from the contact and their dogs. The form
// posts to Api/BookAgainSubmit.php (POST /api/v1/PackLeader/bookAgain/:id?t=).
// A dog whose shots run out before the booking ends must get new dates and an
// upload; the page says so as soon as dates are picked, and the server checks.
// Installed to custom/Espo/Custom/EntryPoints/Client.php by
// scripts/packleader-office-flow-install.py.
namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Custom\PackLeader\Client as Link;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Client implements EntryPoint
{
    use NoAuth;

    private const TZ = 'America/New_York';
    private const SHOTS = ['rabiesDue' => 'Rabies', 'dhppDue' => 'DAPP', 'bordetellaDue' => 'Bordetella'];
    private const SERVICE = ['Boarding' => 'Boarding', 'BoardAndTrain' => 'Board & train',
        'DayStayAndTrain' => 'Day training', 'PrivateLesson' => 'Private lesson', 'GroupClass' => 'Group class'];
    /** Display names only (never the booking dropdown): clients see their custom invoices, but can't choose that service. */
    private const LABEL = self::SERVICE + ['Custom' => 'Custom invoice'];

    public function __construct(private EntityManager $entityManager)
    {}

    public function run(Request $request, Response $response): void
    {
        $id = (string) $request->getQueryParam('c');
        $token = (string) $request->getQueryParam('t');
        $c = Link::find($this->entityManager, $id, $token);

        if (!$c) {
            $this->send($response, $this->head('Pack Leader K9')
                . '<h1>This link has run out</h1>'
                . '<p class="lead">Sign-in links from "Booked with us before?" work for 24 hours. Enter your email for a fresh one.</p>'
                . \Espo\Custom\EntryPoints\Booking::returningBox(false)
                . '<p class="lead"><a href="/?entryPoint=booking">Or go to the booking form</a></p></body></html>');
            return;
        }

        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dogs = Link::dogs($this->entityManager, $c);
        $done = $request->getQueryParam('done') === '1';
        $err = (string) $request->getQueryParam('err');
        $action = 'api/v1/PackLeader/bookAgain/' . $id . '?t=' . $token;

        $html = $this->head('Your Pack Leader K9 page')
            . '<h1>Welcome back, ' . $h($c->get('firstName') ?: $c->get('name')) . '</h1>'
            . '<p class="lead small">This page is private to you. Please don\'t share the link.</p>'
            . ($done ? '<p class="msg">Thanks, we got your request. You\'ll get an email to confirm, and we\'ll get back to you, usually within a day.</p>' : '')
            . ($err !== '' ? '<p class="msg err" role="alert"><strong>Not sent yet.</strong> ' . $h(mb_substr($err, 0, 400)) . '</p>' : '')
            . $this->summary($c, $dogs, $h)
            . '<h2>Book again</h2>'
            . '<form method="post" action="' . $h($action) . '" enctype="multipart/form-data" id="book">'
            . '<input type="hidden" name="t" value="' . time() . '">'
            . '<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            . $this->bookingFields()
            . '<h2>Who\'s coming?</h2>'
            . ($dogs ? '' : '<p class="small">No dogs on file yet. Add one below.</p>')
            . implode('', array_map(fn ($d) => $this->knownDog($d, $h), $dogs))
            . '<div id="dogs"></div>'
            . '<button type="button" id="add" class="ghost">+ Add a new dog</button>'
            . '<template id="dogtpl">' . Booking::dogTemplate() . '</template>'
            . $this->aboutYou($c, $dogs, $h)
            . '<h2>Anything else</h2>' . Booking::ta('Anything else we should know', 'description')
            . '<div class="row col"><label class="chk"><input type="checkbox" name="photoConsent" value="1"> Photos and video of my dog may be used for updates or promotion (no names or personal details).</label>'
            . '<label class="chk"><input type="checkbox" name="agreementAccepted" value="1" required> I have read and agree to the <a href="/?entryPoint=boardingTerms" target="_blank">boarding terms</a>.</label></div>'
            . '<button type="submit" id="go">Send request</button>'
            . '</form>'
            . '<p class="small">Questions? Call or text <a href="tel:+17275550100">(727) 555-0100</a>.</p>'
            . '<script>' . self::JS . Booking::HANDOFF_JS . Booking::LESSONLOC_JS . Booking::PHONE_JS . Booking::RESTORE_JS . '</script></body></html>';

        $this->send($response, $html);
    }

    private function head(string $title): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . $title . '</title><style>' . Booking::CSS . self::CSS . '</style></head><body>'
            . '<img class="logo" src="https://office.example.com/client/custom/img/packleader.jpg" alt="Pack Leader K9">';
    }

    /** Coming up, to pay, waiting for approval. Sections with nothing in them are left out. */
    private function summary(Entity $c, array $dogs, callable $h): string
    {
        $tz = new \DateTimeZone(self::TZ);
        $today = (new \DateTime('now', $tz))->format('Y-m-d');
        $day = fn ($d) => $d ? (new \DateTime($d, $tz))->format('D M j') : '';
        $at = fn ($utc) => $utc ? (new \DateTime($utc, new \DateTimeZone('UTC')))->setTimezone($tz)->format('D M j, g:i A') : '';
        $out = '';

        $rows = [];
        $dogIds = array_map(fn ($d) => $d->getId(), $dogs);
        if ($dogIds) {
            foreach ($this->entityManager->getRDBRepository('Stay')
                         ->where(['dogId' => $dogIds, 'depart>=' => $today, 'status' => ['Requested', 'Confirmed', 'InHouse']])
                         ->order('arrive', 'ASC')->limit(0, 20)->find() as $s) {
                $rows[] = [$s->get('arrive'), $h(self::LABEL[$s->get('service')] ?? 'Stay') . ' · ' . $h($s->get('dogName'))
                    . '<br><span class="small">' . $day($s->get('arrive')) . ($s->get('depart') !== $s->get('arrive') ? ' to ' . $day($s->get('depart')) : '')
                    . ($s->get('status') === 'Requested' ? ' · waiting for approval' : '') . '</span>'];
            }
        }
        foreach ($this->entityManager->getRDBRepository('Contact')->getRelation($c, 'meetings')
                     ->where(['dateStart>=' => gmdate('Y-m-d H:i:s'), 'status!=' => 'Not Held'])
                     ->order('dateStart', 'ASC')->limit(0, 20)->find() as $m) {
            $rows[] = [substr((string) $m->get('dateStart'), 0, 10), $h($m->get('name'))
                . '<br><span class="small">' . $at($m->get('dateStart')) . '</span>'];
        }
        if ($rows) {
            usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
            $out .= '<h2>Coming up</h2><table class="list">'
                . implode('', array_map(fn ($r) => '<tr><td>' . $r[1] . '</td></tr>', $rows)) . '</table>';
        }

        // Lesson packages still in use. The counts are kept by the office book
        // (lesson-package/…/Hooks/LessonPackage/Counts.php): a booked lesson counts,
        // a cancelled (Not Held) one gives the session back.
        $packs = [];
        foreach ($this->entityManager->getRDBRepository('LessonPackage')
                     ->where(['contactId' => $c->getId(), 'status!=' => 'Finished'])
                     ->order('createdAt', 'ASC')->limit(0, 10)->find() as $p) {
            // To the client, "left" is every lesson not had yet: booked ones included.
            $bought = (int) $p->get('sessions');
            $remaining = max(0, $bought - (int) $p->get('sessionsHeld'));
            $toSchedule = max(0, (int) $p->get('sessionsLeft'));
            $parts = [];
            if ($p->get('sessionsBooked')) {
                $parts[] = (int) $p->get('sessionsBooked') . ' booked';
            }
            $parts[] = $toSchedule === 0 ? 'all scheduled' : $toSchedule . ' to schedule';
            $packs[] = '<tr><td><strong>' . $remaining . ' of ' . $bought . ' lesson' . ($bought === 1 ? '' : 's') . ' left</strong>'
                . ' · ' . $h($p->get('dogName') ?: $p->get('name'))
                . '<br><span class="small">' . implode(' · ', $parts) . '</span></td></tr>';
        }
        if ($packs) {
            $out .= '<h2>Your lesson packages</h2><table class="list">' . implode('', $packs) . '</table>'
                . '<p class="small">To schedule your next lesson, call or text <a href="tel:+17275550100">(727) 555-0100</a>.</p>';
        }

        $pay = [];
        foreach ($this->entityManager->getRDBRepository('Lead')
                     ->where(['createdContactId' => $c->getId(), 'paymentStatus' => 'Invoiced', 'paidAt' => null])
                     ->order('paymentDue', 'ASC')->limit(0, 20)->find() as $l) {
            $amount = '$' . number_format((float) $l->get('invoiceTotal'), 2);
            $what = $l->get('service') === 'Custom' ? 'Custom invoice'
                : $h($l->get('dogName')) . ' · ' . $h(self::LABEL[$l->get('service')] ?? '');
            $due = $l->get('paymentDue') ? ' · due ' . $at($l->get('paymentDue')) : '';
            if ($l->get('bankInvoiceUrl') && $l->get('invoiceUrl')) {   // since 2026-10-12: bank (no fee) or card
                $amount = '$' . number_format((float) $l->get('invoiceTotal'), 2) . ' by card';
                $link = '<br><a href="' . $h($l->get('bankInvoiceUrl')) . '">Pay by bank (no fee)</a>'
                    . ' · <a href="' . $h($l->get('invoiceUrl')) . '">Pay by card (3% fee)</a>';
            } else {
                $link = $l->get('invoiceUrl')
                    ? ' <a href="' . $h($l->get('invoiceUrl')) . '">Pay now</a>'
                    : '<br><span class="small">How to pay is in your confirmation email.</span>';
            }
            $pay[] = '<tr><td>' . $what . '<br><span class="small">' . $amount . $due . '</span>' . $link . '</td></tr>';
        }
        if ($pay) {
            $out .= '<h2>To pay</h2><table class="list">' . implode('', $pay) . '</table>';
        }

        $wait = [];
        foreach ($this->entityManager->getRDBRepository('Lead')
                     ->where(['returningContactId' => $c->getId(), 'status' => ['New', 'Assigned', 'In Process']])
                     ->order('createdAt', 'DESC')->limit(0, 10)->find() as $l) {
            $wait[] = '<tr><td>' . $h($l->get('dogName')) . ' · ' . $h(self::LABEL[$l->get('service')] ?? '')
                . ($l->get('arrive') ? '<br><span class="small">' . $day($l->get('arrive'))
                    . ($l->get('depart') && $l->get('depart') !== $l->get('arrive') ? ' to ' . $day($l->get('depart')) : '') . '</span>' : '')
                . '</td></tr>';
        }
        if ($wait) {
            $out .= '<h2>Waiting for approval</h2><table class="list">' . implode('', $wait) . '</table>';
        }

        return $out;
    }

    private function bookingFields(): string
    {
        return '<div class="grid">'
            . Booking::sel('Service', 'service', ['' => 'Choose'] + self::SERVICE, true)
            . Booking::f('Start date', 'arrive', 'date') . Booking::f('End date', 'depart', 'date')
            . '</div>'
            . Booking::handoffFields()
            . Booking::lessonLocationFields()
            . '<p class="hint">Boarding and board &amp; train: drop-off and pick-up dates. Day training: the same date for both. Lessons and classes: the date you\'d like, plus a time below.</p>'
            . Booking::f('Preferred time (lessons and classes)', 'preferredTime');
    }

    /** A dog on file: tick to bring it; its care details and shots, pre-filled. */
    private function knownDog(Entity $d, callable $h): string
    {
        $id = $d->getId();
        $n = 'known[' . $id . ']';
        $shots = [];
        foreach (array_keys(self::SHOTS) as $k) {
            $shots[$k] = (string) $d->get($k);
        }
        $good = (array) ($d->get('goodWith') ?? []);
        $goodLabels = ['MaleDogs' => 'Male dogs', 'FemaleDogs' => 'Female dogs', 'LargeDogs' => 'Large dogs',
            'SmallDogs' => 'Small dogs', 'Puppies' => 'Puppies', 'Cats' => 'Cats', 'Children' => 'Children',
            'NewPeople' => 'New people', 'Unknown' => 'Not sure'];
        $goodBoxes = '';
        foreach ($goodLabels as $k => $v) {
            $goodBoxes .= '<label class="chk"><input type="checkbox" name="' . $n . '[goodWith][]" value="' . $k . '"'
                . (in_array($k, $good, true) ? ' checked' : '') . '> ' . $v . '</label>';
        }
        $chk = fn ($f, $label) => '<label class="chk"><input type="checkbox" name="' . $n . '[' . $f . ']" value="1"'
            . ($d->get($f) ? ' checked' : '') . '> ' . $label . '</label>';

        $shotInputs = '';
        foreach (self::SHOTS as $k => $label) {
            $shotInputs .= $this->input($label . ' due' . ($k === 'rabiesDue' ? ' (required)' : ' (optional)'), $n . '[' . $k . ']', $shots[$k], 'date', ' data-shot="' . $k . '"');
        }

        return '<fieldset class="dog known" data-shots="' . $h(json_encode($shots)) . '">'
            . '<label class="chk pick"><input type="checkbox" name="' . $n . '[coming]" value="1" class="coming"> '
            . '<strong>' . $h($d->get('name')) . '</strong>' . ($d->get('breed') ? ' <span class="small">' . $h($d->get('breed')) . '</span>' : '')
            . '</label>'
            . '<div class="body" hidden>'
            // Outside the folded details, so its "required" (set by JS once ticked) is visible.
            . Booking::birthField($n . '[birthDate]', (string) $d->get('birthDate'))
            . '<p class="shots small"></p>'
            . '<details class="shotbox"><summary>Shots</summary><div class="grid">' . $shotInputs . '</div>'
            . '<label class="field">Updated shot records (PDF or photos)'
            . '<input type="file" name="' . $n . '[records][]" multiple accept="' . Booking::ACCEPT . '" class="records"></label></details>'
            . '<details><summary>Still right? ' . $h($d->get('name')) . '\'s care details</summary>'
            . '<div class="grid">'
            . $this->input('Breed', $n . '[breed]', $d->get('breed'))
            . $this->input('Age', $n . '[age]', $d->get('age'))
            . $this->select('Sex', $n . '[sex]', ['' => 'Choose', 'Female' => 'Female', 'Male' => 'Male'], $d->get('sex'))
            . '</div>'
            . '<div class="row">' . $chk('intact', 'Intact (not spayed/neutered)') . $chk('crateTrained', 'Crate trained') . '</div>'
            . $this->area('Temperament', $n . '[temperament]', $d->get('temperament'))
            . '<p class="lbl">Good with</p><div class="row wrap">' . $goodBoxes . '</div>'
            . $this->select('Food', $n . '[foodSource]', ['' => 'Choose', 'Owner' => 'I\'ll bring food', 'Facility' => 'Use your food'], $d->get('foodSource'))
            . $this->area('Feeding: food, amount, and times of day', $n . '[feeding]', $d->get('feeding'))
            . $this->area('Medications, with dose and instructions (if any)', $n . '[medications]', $d->get('medications'))
            . $this->area('Special care', $n . '[specialCare]', $d->get('specialCare'))
            . '<div class="row">' . $chk('preventionCurrent', 'Flea/tick and heartworm prevention is current') . '</div>'
            . '</details>'
            . '<div class="row"><label class="chk"><input type="checkbox" name="' . $n . '[healthCertified]" value="1"> '
            . $h($d->get('name')) . ' is healthy and free of contagious disease</label></div>'
            . '</div></fieldset>';
    }

    /** Contact details and vet, pre-filled, folded away. Not marked required: a required
     *  field inside a closed <details> blocks the form silently. The server checks them. */
    private function aboutYou(Entity $c, array $dogs, callable $h): string
    {
        $vetDog = null;
        foreach ($dogs as $d) {
            if ($d->get('vetClinic') && (!$vetDog || $d->get('modifiedAt') > $vetDog->get('modifiedAt'))) $vetDog = $d;
        }
        $v = fn ($f) => $vetDog ? $vetDog->get($f) : null;
        $line = $h(trim($c->get('firstName') . ' ' . $c->get('lastName'))) . ' · ' . $h($c->get('emailAddress'))
            . ' · ' . $h(Booking::usPhone((string) $c->get('phoneNumber')));
        return '<h2>Your details</h2><p class="small">' . $line . ($v('vetClinic') ? '<br>Vet: ' . $h($v('vetClinic')) : '') . '</p>'
            . '<details><summary>Changed? Edit your details</summary><div class="grid">'
            . $this->input('First name', 'firstName', $c->get('firstName'), 'text')
            . $this->input('Last name', 'lastName', $c->get('lastName'), 'text')
            . $this->input('Email', 'emailAddress', $c->get('emailAddress'), 'email')
            . Booking::phoneField('Phone', 'phoneNumber', false, $c->get('phoneNumber'))
            . $this->input('Emergency contact name', 'emergencyName', $c->get('emergencyName'))
            . Booking::phoneField('Emergency phone', 'emergencyPhone', false, $c->get('emergencyPhone'))
            . $this->input('Vet clinic', 'vetClinic', $v('vetClinic'))
            . $this->input('Preferred vet', 'vetName', $v('vetName'))
            . $this->input('Clinic address', 'vetAddress', $v('vetAddress'))
            . Booking::phoneField('Clinic phone', 'vetPhone', false, $v('vetPhone'))
            . '</div>'
            . $this->input('Preferred emergency vet (if you have none, we use VEG Clearwater)', 'emergencyVet', $v('emergencyVet'))
            . '</details>';
    }

    private function input(string $label, string $name, $value, string $type = 'text', string $extra = ''): string
    {
        return '<label class="field">' . $label . '<input type="' . $type . '" name="' . $name . '" value="'
            . htmlspecialchars((string) $value, ENT_QUOTES) . '"' . $extra . '></label>';
    }

    private function area(string $label, string $name, $value): string
    {
        return '<label class="field">' . $label . '<textarea name="' . $name . '" rows="3">'
            . htmlspecialchars((string) $value, ENT_QUOTES) . '</textarea></label>';
    }

    private function select(string $label, string $name, array $opts, $value): string
    {
        $o = '';
        foreach ($opts as $k => $t) {
            $o .= '<option value="' . $k . '"' . ((string) $value === (string) $k ? ' selected' : '') . '>' . htmlspecialchars($t) . '</option>';
        }
        return '<label class="field">' . $label . '<select name="' . $name . '">' . $o . '</select></label>';
    }

    private function send(Response $response, string $html): void
    {
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $response->writeBody($html);
    }

    private const CSS = <<<'CSS'
.known .pick{font-size:1.1rem}.known .body{margin-top:.5rem}
.known details{margin:.5rem 0}
CSS;

    // Shot rule, same as the server's (Rabies only): a rabies vaccine due before the
    // booking ends needs a new date on or after the end, and an upload.
    private const JS = <<<'JS'
(function(){
  var names={rabiesDue:'Rabies',dhppDue:'DAPP',bordetellaDue:'Bordetella'};
  var arrive=document.querySelector('[name=arrive]'),depart=document.querySelector('[name=depart]');
  function end(){return depart.value||arrive.value||'';}
  function check(f){
    var body=f.querySelector('.body'),on=f.querySelector('.coming').checked;body.hidden=!on;
    f.querySelector('.birth').required=on;
    var old=JSON.parse(f.getAttribute('data-shots')),e=end(),bad=[],msg=f.querySelector('.shots'),
        files=f.querySelector('.records'),box=f.querySelector('.shotbox');
    f.querySelectorAll('[data-shot]').forEach(function(i){
      var k=i.getAttribute('data-shot'),need=on&&e&&k==='rabiesDue'&&(!old[k]||old[k]<e);
      i.required=!!need;i.min=need?e:'';if(need)bad.push(names[k]);});
    files.required=bad.length>0;
    if(!on){return;}
    if(!e){msg.textContent='Pick your dates and we’ll check the shots on file.';msg.className='shots small';return;}
    if(bad.length){msg.textContent=bad.join(', ')+' runs out before this booking ends. Enter the new date and upload the updated record.';
      msg.className='shots warn';box.open=true;}
    else{msg.textContent='Shots on file ✓ good through '+e+'.';msg.className='shots small ok';}
  }
  var known=document.querySelectorAll('.known');
  function all(){known.forEach(check);}
  known.forEach(function(f){f.querySelector('.coming').addEventListener('change',function(){check(f);});});
  arrive.addEventListener('change',all);depart.addEventListener('change',all);all();
  var box=document.getElementById('dogs'),tpl=document.getElementById('dogtpl').innerHTML,n=0;
  function renumber(){var d=box.querySelectorAll('.dog');for(var i=0;i<d.length;i++){d[i].querySelector('.num').textContent='(new)';}}
  document.getElementById('add').addEventListener('click',function(){var w=document.createElement('div');
    w.innerHTML=tpl.replace(/__i__/g,String(n++));var f=w.firstChild;
    f.querySelector('.remove').addEventListener('click',function(){f.remove();renumber();});box.appendChild(f);renumber();f.querySelector('input').focus();});
  window.pk9AddDog=function(){document.getElementById('add').click();};
  if(!known.length){document.getElementById('add').click();}
  document.getElementById('book').addEventListener('submit',function(ev){
    var any=box.querySelector('.dog')||document.querySelector('.coming:checked');
    if(!any){ev.preventDefault();alert('Tick who’s coming, or add a new dog.');return;}
    var b=document.getElementById('go');b.disabled=true;b.textContent='Sending…';});
})();
JS;
}
