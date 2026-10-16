<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Public booking page: https://office.example.com/?entryPoint=booking
// (go.example.com/book points here). One household section, then one
// section per dog with an "Add another dog" button. Submits to
// Api/BookingSubmit.php (POST /api/v1/PackLeader/booking, no login), which
// creates one Request (Lead) plus one RequestDog per dog.
// Installed to custom/Espo/Custom/EntryPoints/Booking.php by
// scripts/packleader-office-flow-install.py.
namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;

class Booking implements EntryPoint
{
    use NoAuth;

    public function run(Request $request, Response $response): void
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $done = $request->getQueryParam('done') === '1';
        $err = (string) $request->getQueryParam('err');
        $sent = $request->getQueryParam('sent') === '1';
        $logo = 'https://office.example.com/client/custom/img/packleader.jpg';
        $terms = '/?entryPoint=boardingTerms';
        $rates = '/?entryPoint=rates';

        $head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Book with Pack Leader K9</title><style>' . self::CSS . '</style></head><body>'
            . '<img class="logo" src="' . $logo . '" alt="Pack Leader K9">';

        if ($done) {
            $html = $head . '<h1>Thank you</h1>'
                . '<p class="lead">We got your request. Check your email for a confirmation. We\'ll look it over and get back to you, usually within a day.</p>'
                . '<p class="lead">Questions? Call or text <a href="tel:+17275550100">(727) 555-0100</a>.</p>'
                . '</body></html>';
            $this->send($response, $html);
            return;
        }

        $dog = self::dogTemplate();

        $services = ['' => 'Choose', 'Boarding' => 'Boarding', 'BoardAndTrain' => 'Board & train',
            'DayStayAndTrain' => 'Day training', 'PrivateLesson' => 'Private lesson', 'GroupClass' => 'Group class'];

        $html = $head
            . '<h1>Book with Pack Leader K9</h1>'
            . '<p class="lead">One form for your whole household. Add each dog below. See our <a href="' . $rates . '">rates</a>. '
            . 'We review every request and email you to confirm.</p>'
            . ($err !== '' ? '<p class="msg err" role="alert"><strong>Not sent yet.</strong> ' . $h(mb_substr($err, 0, 300)) . '</p>' : '')
            . self::returningBox($sent)
            . '<form method="post" action="api/v1/PackLeader/booking" enctype="multipart/form-data" id="book">'
            . '<input type="hidden" name="t" value="' . time() . '">'
            . '<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            . '<h2>About you</h2><p class="hint">US phone numbers only; no +1 needed.</p><div class="grid">'
            . self::f('First name', 'firstName', 'text', true) . self::f('Last name', 'lastName', 'text', true)
            . self::f('Email', 'emailAddress', 'email', true) . self::phoneField('Phone', 'phoneNumber', true)
            . self::f('Emergency contact name', 'emergencyName') . self::phoneField('Emergency phone', 'emergencyPhone')
            . '</div>'
            . '<h2>Your vet</h2><div class="grid">'
            . self::f('Vet clinic', 'vetClinic') . self::f('Preferred vet', 'vetName')
            . self::f('Clinic address', 'vetAddress') . self::phoneField('Clinic phone', 'vetPhone')
            . '</div>'
            . self::f('Preferred emergency vet (if you have none, we use VEG Clearwater)', 'emergencyVet')
            . '<h2>What you\'re booking</h2><div class="grid">'
            . self::sel('Service', 'service', $services, true)
            . self::f('Start date', 'arrive', 'date') . self::f('End date', 'depart', 'date')
            . '</div>'
            . self::handoffFields()
            . self::lessonLocationFields()
            . '<p class="hint">Boarding and board &amp; train: drop-off and pick-up dates. Day training: the same date for both. Lessons and classes: the date you\'d like, plus a time below.</p>'
            . self::f('Preferred time (lessons and classes)', 'preferredTime')
            . '<h2>Your dogs</h2><div id="dogs"></div>'
            . '<button type="button" id="add" class="ghost">+ Add another dog</button>'
            . '<template id="dogtpl">' . $dog . '</template>'
            . '<h2>Anything else</h2>' . self::ta('Anything else we should know', 'description')
            . '<div class="row col"><label class="chk"><input type="checkbox" name="photoConsent" value="1"> Photos and video of my dog may be used for updates or promotion (no names or personal details).</label>'
            . '<label class="chk"><input type="checkbox" name="agreementAccepted" value="1" required> I have read and agree to the <a href="' . $terms . '" target="_blank">boarding terms</a>.</label></div>'
            . '<button type="submit" id="go">Send request</button>'
            . '</form>'
            . '<p class="small">Questions? Call or text <a href="tel:+17275550100">(727) 555-0100</a>.</p>'
            . '<script>' . self::JS . self::HANDOFF_JS . self::LESSONLOC_JS . self::PHONE_JS . self::RESTORE_JS . '</script></body></html>';

        $this->send($response, $html);
    }

    /** One dog's section, with __i__ where the dog's number goes. Also used by
     *  the returning-client page (Client.php) for "Add a new dog". */
    public static function dogTemplate(): string
    {
        $good = ['MaleDogs' => 'Male dogs', 'FemaleDogs' => 'Female dogs', 'LargeDogs' => 'Large dogs',
            'SmallDogs' => 'Small dogs', 'Puppies' => 'Puppies', 'Cats' => 'Cats', 'Children' => 'Children',
            'NewPeople' => 'New people', 'Unknown' => 'Not sure'];
        $goodBoxes = '';
        foreach ($good as $k => $v) {
            $goodBoxes .= '<label class="chk"><input type="checkbox" name="dogs[__i__][goodWith][]" value="' . $k . '"> ' . $v . '</label>';
        }

        return '<fieldset class="dog" data-i="__i__"><legend>Dog <span class="num"></span></legend>'
            . '<button type="button" class="remove" aria-label="Remove this dog">Remove</button>'
            . '<div class="grid">'
            . self::f('Name', 'dogs[__i__][name]', 'text', true)
            . self::f('Breed', 'dogs[__i__][breed]')
            . self::sel('Sex', 'dogs[__i__][sex]', ['' => 'Choose', 'Female' => 'Female', 'Male' => 'Male'])
            . self::f('Age', 'dogs[__i__][age]')
            . self::birthField('dogs[__i__][birthDate]')
            . '</div>'
            . '<div class="row"><label class="chk"><input type="checkbox" name="dogs[__i__][intact]" value="1"> Intact (not spayed/neutered)</label>'
            . '<label class="chk"><input type="checkbox" name="dogs[__i__][crateTrained]" value="1"> Crate trained</label></div>'
            . self::ta('Temperament: how does your dog act with new people and places?', 'dogs[__i__][temperament]')
            . '<p class="lbl">My dog is good with (check all that apply)</p><div class="row wrap">' . $goodBoxes . '</div>'
            . self::sel('Food', 'dogs[__i__][foodSource]', ['' => 'Choose', 'Owner' => 'I\'ll bring food', 'Facility' => 'Use your food'])
            . self::ta('Feeding: food, amount, and times of day', 'dogs[__i__][feeding]')
            . self::ta('Medications, with dose and instructions (if any)', 'dogs[__i__][medications]')
            . self::ta('Special care', 'dogs[__i__][specialCare]')
            . '<div class="grid">'
            . self::f('Rabies due (required)', 'dogs[__i__][rabiesDue]', 'date')
            . self::f('DAPP due (optional)', 'dogs[__i__][dhppDue]', 'date')
            . self::f('Bordetella due (optional)', 'dogs[__i__][bordetellaDue]', 'date')
            . '</div>'
            . '<div class="row"><label class="chk"><input type="checkbox" name="dogs[__i__][preventionCurrent]" value="1"> Flea/tick and heartworm prevention is current</label>'
            . '<label class="chk"><input type="checkbox" name="dogs[__i__][healthCertified]" value="1"> Healthy and free of contagious disease</label></div>'
            . '<label class="field">Vet records: shots and a recent weight (PDF or photos) <em>*</em>'
            . '<input type="file" name="dogs[__i__][records][]" multiple accept="' . self::ACCEPT . '" required></label>'
            . '</fieldset>';
    }

    private function send(Response $response, string $html): void
    {
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->writeBody($html);
    }

    /** "Booked with us before?": email me a sign-in link (Api/ClientLinkRequest.php). */
    public static function returningBox(bool $sent): string
    {
        if ($sent) {
            return '<p class="msg">If you\'re on file with us, a sign-in link is on its way to your email. '
                . 'It works for 24 hours. Nothing there after a few minutes? Check spam, or just fill in the form below.</p>';
        }
        return '<details class="card"><summary>Booked with us before? Skip the long form</summary>'
            . '<p class="small">Enter the email you booked with and we\'ll send you a link that fills everything in.</p>'
            . '<form method="post" action="api/v1/PackLeader/clientLink" class="inline">'
            . '<input type="hidden" name="t" value="' . time() . '">'
            . '<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            . '<input type="email" name="emailAddress" required placeholder="you@example.com" aria-label="Email">'
            . '<button type="submit" class="ghost">Email me a link</button></form></details>';
    }

    /** Birth date with the server's limits (BookingSubmit::birthDate: not in the future, under
     *  30 years), so a mistyped year is caught on the field instead of after sending. */
    public static function birthField(string $name, string $value = ''): string
    {
        return '<label class="field">Birth date (best guess is fine) <em>*</em><input type="date" name="' . $name
            . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '" min="' . date('Y-m-d', strtotime('-30 years'))
            . '" max="' . date('Y-m-d') . '" required class="birth"></label>';
    }

    public static function f(string $label, string $name, string $type = 'text', bool $req = false): string
    {
        return '<label class="field">' . $label . ($req ? ' <em>*</em>' : '')
            . '<input type="' . $type . '" name="' . $name . '"' . ($req ? ' required' : '') . '></label>';
    }

    public static function ta(string $label, string $name): string
    {
        return '<label class="field">' . $label . '<textarea name="' . $name . '" rows="3"></textarea></label>';
    }

    public static function sel(string $label, string $name, array $opts, bool $req = false): string
    {
        $o = '';
        foreach ($opts as $v => $t) {
            $o .= '<option value="' . $v . '">' . htmlspecialchars($t) . '</option>';
        }
        return '<label class="field">' . $label . ($req ? ' <em>*</em>' : '')
            . '<select name="' . $name . '"' . ($req ? ' required' : '') . '>' . $o . '</select></label>';
    }

    /** Every client is in the US: phone fields take 10 digits, no country code.
     *  PHONE_JS formats as they type; the server stores +1XXXXXXXXXX (the only form
     *  the office book's API accepts) and refuses anything that isn't 10 digits. */
    public static function phoneField(string $label, string $name, bool $req = false, ?string $value = null): string
    {
        return '<label class="field">' . $label . ($req ? ' <em>*</em>' : '')
            . '<input type="tel" name="' . $name . '" data-us-phone inputmode="tel" autocomplete="'
            . ($name === 'phoneNumber' ? 'tel-national' : 'off') . '" placeholder="(727) 555-1234" value="'
            . htmlspecialchars(self::usPhone((string) $value), ENT_QUOTES) . '"' . ($req ? ' required' : '') . '></label>';
    }

    /** +17275551234 / 7275551234 / 1-727-555-1234 -> (727) 555-1234 for display; anything else as is. */
    public static function usPhone(string $v): string
    {
        $d = preg_replace('/\D+/', '', $v);
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        return strlen($d) === 10 ? sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6)) : $v;
    }

    public const PHONE_JS = <<<'JS'
(function(){
  function fmt(v){var d=v.replace(/\D/g,'');if(d.length>10&&d.charAt(0)==='1')d=d.slice(1);d=d.slice(0,10);
    if(d.length>6)return '('+d.slice(0,3)+') '+d.slice(3,6)+'-'+d.slice(6);
    if(d.length>3)return '('+d.slice(0,3)+') '+d.slice(3);return d;}
  function check(i){var n=i.value.replace(/\D/g,'').length;
    i.setCustomValidity(i.value===''||n===10?'':'Please enter a 10-digit US phone number, like (727) 555-1234.');}
  document.querySelectorAll('[data-us-phone]').forEach(function(i){
    i.addEventListener('input',function(){var at=i.value.length===i.selectionStart;i.value=fmt(i.value);if(at)i.selectionStart=i.selectionEnd=i.value.length;check(i);});
    i.addEventListener('blur',function(){check(i);});if(i.value){i.value=fmt(i.value);check(i);}});
})();
JS;

    /** Dog size = weight tier (2026-09-28). Set by the office from the vet records, never asked
     *  of the client; shown on the kennel board. Same keys as the installer and
     *  scripts/packleader_pricing.py (BOARDING_NIGHT); change them together. */
    /** File fields: MIME types AND extensions. A picker filters by this (drag and drop doesn't):
     *  macOS Chrome greyed out iPhone HEIC photos under "image/*" alone, so Browse "didn't work"
     *  while dropping the same file did (2026-09-28). Same types as BookingSubmit::TYPES. */
    public const ACCEPT = 'application/pdf,image/*,.pdf,.jpg,.jpeg,.png,.webp,.heic,.heif';

    public const SIZES = ['Under25' => 'Under 25 lbs', 'From25to50' => '25–50 lbs', 'From50to75' => '50–75 lbs',
        'From75to100' => '75–100 lbs', 'Over100' => '100+ lbs'];

    /** Drop-off / pick-up blocks for Boarding and Board & train (any block, any day;
     *  the Sunday evening-only rule was dropped 2026-09-28).
     *  Same values in BookingSubmit (checks), the installer (fields) and the robot (emails). */
    public const TIMES = ['' => 'Choose', 'Morning' => 'Morning (8–10 AM)', 'Midday' => 'Midday (12–2 PM)',
        'Evening' => 'Evening (5–7 PM)'];

    /** Drop-off and pick-up time pickers; HANDOFF_JS shows them for boarding services only. */
    public static function handoffFields(): string
    {
        return '<div class="grid handoff">'
            . self::sel('Drop-off time', 'dropOffTime', self::TIMES)
            . self::sel('Pick-up time', 'pickUpTime', self::TIMES)
            . '</div>';
    }

    /** Where private lessons happen (never at the facility). Same values in BookingSubmit
     *  (checks), the installer (Lead/Meeting lessonLocation) and the robot (emails). */
    public const LOCATIONS = ['' => 'Choose', 'ClientHome' => 'At my home', 'HomeDepot' => 'Home Depot',
        'Park' => 'A park', 'Other' => 'Somewhere else'];

    /** Lesson location picker; LESSONLOC_JS shows it for private lessons only. */
    public static function lessonLocationFields(): string
    {
        return '<div class="grid lessonloc">'
            . self::sel('Where would you like lessons?', 'lessonLocation', self::LOCATIONS)
            . self::f('Address or place (your street address, which Home Depot, which park)', 'lessonAddress')
            . '</div>';
    }

    public const LESSONLOC_JS = <<<'JS'
(function(){
  var svc=document.querySelector('[name=service]'),loc=document.querySelector('[name=lessonLocation]'),
      addr=document.querySelector('[name=lessonAddress]'),boxes=document.querySelectorAll('.lessonloc');
  if(!svc||!loc)return;
  function update(){var on=svc.value==='PrivateLesson';boxes.forEach(function(b){b.hidden=!on;});
    loc.required=addr.required=on;}
  svc.addEventListener('change',update);update();
})();
JS;

    public const HANDOFF_JS = <<<'JS'
(function(){
  var svc=document.querySelector('[name=service]'),a=document.querySelector('[name=arrive]'),d=document.querySelector('[name=depart]'),
      drop=document.querySelector('[name=dropOffTime]'),pick=document.querySelector('[name=pickUpTime]'),boxes=document.querySelectorAll('.handoff');
  function update(){var on=svc.value==='Boarding'||svc.value==='BoardAndTrain';
    boxes.forEach(function(b){b.hidden=!on;});drop.required=pick.required=on;}
  [svc,a,d].forEach(function(e){e.addEventListener('change',update);});update();
})();
JS;

    public const CSS = <<<'CSS'
:root{--black:#04090c;--green:#84c432;--green-press:#6ea028;--paper:#e0e1db;--line:#223027;--dim:#a9aea4}
*{box-sizing:border-box}
body{font-family:Georgia,serif;max-width:44rem;margin:0 auto;padding:1.5rem 1rem 3rem;line-height:1.5;color:var(--paper);background:var(--black)}
img.logo{display:block;width:min(100%,260px);height:auto;margin:0 auto 1rem}
h1,h2,legend,button,.lbl{font-family:Arial,sans-serif;letter-spacing:.04em}
h1{color:var(--green);text-align:center;font-size:1.7rem;margin:.5rem 0}
h2{color:var(--green);font-size:1.15rem;text-transform:uppercase;margin:2rem 0 .75rem;border-top:1px solid var(--line);padding-top:1.25rem}
a{color:var(--green)}
.lead{text-align:center}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:0 1rem}
.field{display:block;margin:.5rem 0;font-size:.95rem}
.field em{color:var(--green);font-style:normal}
input[type=text],input[type=email],input[type=tel],input[type=date],select,textarea{display:block;width:100%;margin-top:.25rem;padding:.6rem;border:1px solid #3a4a3a;border-radius:.35rem;background:#0c1512;color:var(--paper);font:inherit}
input[type=file]{display:block;margin-top:.35rem;color:var(--paper);max-width:100%}
.row{display:flex;gap:.5rem 1.25rem;flex-wrap:wrap;margin:.5rem 0}.row.col{flex-direction:column}
.chk{display:flex;gap:.45rem;align-items:flex-start}.chk input{margin-top:.3rem;accent-color:var(--green)}
.lbl{font-size:.9rem;margin:.75rem 0 .25rem}
.hint,.small{color:var(--dim);font-size:.9rem}
fieldset.dog{border:1px solid var(--line);border-radius:.5rem;padding:1rem;margin:0 0 1rem;position:relative}
legend{color:var(--green);padding:0 .4rem;font-weight:700}
.remove{position:absolute;top:.4rem;right:.6rem;background:none;border:0;color:var(--dim);cursor:pointer;text-decoration:underline}
.dog:only-of-type .remove{display:none}
button[type=submit],.ghost{display:block;width:100%;padding:.9rem;border-radius:.4rem;font-weight:700;text-transform:uppercase;cursor:pointer;font-size:1rem}
button[type=submit]{background:var(--green);color:var(--black);border:0;margin-top:1.5rem}
button[type=submit]:active{background:var(--green-press)}
.ghost{background:none;border:2px solid var(--green);color:var(--green)}
.msg{border:1px solid var(--green);border-radius:.4rem;padding:.75rem 1rem}
.msg.err{border:2px solid #e8a33d;background:#2a1c08;color:#fff}.msg.err strong{color:#e8a33d}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.card{border:1px solid var(--line);border-radius:.5rem;padding:.75rem 1rem;margin:1rem 0}
summary{cursor:pointer;color:var(--green);font-family:Arial,sans-serif;font-weight:700}
form.inline{display:flex;gap:.5rem;flex-wrap:wrap}form.inline input[type=email]{flex:1 1 14rem;margin:0}
form.inline .ghost{width:auto;padding:.6rem 1rem;font-size:.9rem}
.ok{color:var(--green)}.warn{border-left:3px solid var(--green);padding-left:.75rem}
table.list{width:100%;border-collapse:collapse;font-size:.95rem}table.list td{padding:.35rem .25rem;border-top:1px solid var(--line);vertical-align:top}
CSS;

    /** If the server sends the form back (err=), put the client's answers back instead of a blank
     *  form (2026-09-28: a client's request was refused and looked "sent" once the page reloaded empty).
     *  Answers are kept in this tab only (sessionStorage), never files: browsers can't refill those.
     *  Shared with the Book again page (Client.php); each page sets window.pk9AddDog. */
    public const RESTORE_JS = <<<'JS'
(function(){
  var form=document.getElementById('book'),q=location.search,KEY='pk9-form:'+form.getAttribute('action');
  function store(){try{var d=[];new FormData(form).forEach(function(v,k){
    if(typeof v==='string'&&k!=='t'&&k!=='website')d.push([k,v]);});
    sessionStorage.setItem(KEY,JSON.stringify(d));}catch(e){}}
  form.addEventListener('submit',store);
  // A required field inside a closed <details> (Book again's "Shots") makes Send do nothing, silently:
  // the browser can't point at it. Open its section so the browser can show what's missing.
  form.addEventListener('invalid',function(e){var d=e.target.closest&&e.target.closest('details');if(d)d.open=true;},true);
  if(/[?&]done=1/.test(q)){try{sessionStorage.removeItem(KEY);}catch(e){}return;}
  if(!/[?&]err=/.test(q))return;
  var d=null;try{d=JSON.parse(sessionStorage.getItem(KEY)||'null');}catch(e){}
  if(!d||!d.length)return;
  var idx=[];d.forEach(function(p){var m=/^dogs\[(\d+)\]/.exec(p[0]);if(m&&idx.indexOf(m[1])<0)idx.push(m[1]);});
  var box=document.getElementById('dogs');
  while(box.querySelectorAll('.dog').length<idx.length)window.pk9AddDog();
  var map={};idx.forEach(function(o,i){map[o]=String(i);});
  d.forEach(function(p){
    var k=p[0].replace(/^dogs\[(\d+)\]/,function(_,o){return 'dogs['+map[o]+']';});
    form.querySelectorAll('[name]').forEach(function(el){
      if(el.name!==k||el.type==='file')return;
      if(el.type==='checkbox'||el.type==='radio'){if(el.value===p[1])el.checked=true;}else{el.value=p[1];}});});
  // re-run what the page does on a change: service boxes, shot checks, ticked dogs
  form.querySelectorAll('select,input[type=checkbox],input[type=date]').forEach(function(el){el.dispatchEvent(new Event('change'));});
  var m=document.querySelector('.msg.err');
  if(m){var n=document.createElement('span');n.textContent=' Your answers are still here. Please attach the vet records again (browsers can\'t keep files).';m.appendChild(n);}
})();
JS;

    private const JS = <<<'JS'
(function(){
  var box=document.getElementById('dogs'),tpl=document.getElementById('dogtpl').innerHTML,n=0;
  function renumber(){var d=box.querySelectorAll('.dog');for(var i=0;i<d.length;i++){d[i].querySelector('.num').textContent=d.length>1?(i+1):'';}}
  function add(){var w=document.createElement('div');w.innerHTML=tpl.replace(/__i__/g,String(n++));var f=w.firstChild;
    f.querySelector('.remove').addEventListener('click',function(){f.remove();renumber();});box.appendChild(f);renumber();return f;}
  document.getElementById('add').addEventListener('click',function(){var f=add();f.querySelector('input').focus();});
  add();
  window.pk9AddDog=add;
  document.getElementById('book').addEventListener('submit',function(){var b=document.getElementById('go');b.disabled=true;b.textContent='Sending…';});
})();
JS;
}
