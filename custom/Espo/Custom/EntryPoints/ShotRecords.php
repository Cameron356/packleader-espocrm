<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Public shot-records upload page:
//   https://office.example.com/?entryPoint=shotRecords&id=<Lead id>&t=<token>
// The link is in the "we got your request" email (scripts/packleader-office-flow.py).
// The token is a random value stored on the request (Lead.uploadToken), so a link
// only works for its own request. This page (GET) shows the form; the files are
// received by ShotRecordsUpload.php (POST /api/v1/PackLeader/shotRecords/:id,
// no login) because EspoCRM entry points only accept GET. Files land in
// Lead.shotRecords; the office robot copies them to the dog.
// Installed to custom/Espo/Custom/EntryPoints/ShotRecords.php by
// scripts/packleader-office-flow-install.py.
namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\NotFound;
use Espo\Custom\PackLeader\Footer;
use Espo\ORM\EntityManager;

class ShotRecords implements EntryPoint
{
    use NoAuth;

    private const LINK_DAYS = 90;

    public function __construct(private EntityManager $entityManager)
    {}

    public function run(Request $request, Response $response): void
    {
        $id = (string) $request->getQueryParam('id');
        $token = (string) $request->getQueryParam('t');

        if (!preg_match('/^[a-f0-9]{17,24}$/', $id) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new NotFound();
        }

        $lead = $this->entityManager->getEntityById('Lead', $id);
        $stored = $lead ? (string) $lead->get('uploadToken') : '';

        if (!$lead || $stored === '' || !hash_equals($stored, $token)) {
            throw new NotFound();
        }

        $created = strtotime((string) $lead->get('createdAt') . ' UTC');

        if ($created && $created < time() - self::LINK_DAYS * 86400) {
            $this->page($response, $lead, 'This upload link has expired. Reply to your email from us and attach the records instead.', null);
            return;
        }

        $self = '?entryPoint=shotRecords&id=' . $id . '&t=' . $token;
        $action = 'api/v1/PackLeader/shotRecords/' . $id . '?t=' . $token;

        $msg = null;
        $ok = (int) $request->getQueryParam('ok');
        $err = (string) $request->getQueryParam('err');

        if ($ok > 0) {
            $msg = 'Thank you. We got ' . $ok . ' file' . ($ok === 1 ? '' : 's') . '.';
        }

        if ($err !== '') {
            $msg = trim(($msg ?? '') . ' ' . mb_substr($err, 0, 200));
        }

        $this->page($response, $lead, $msg, $action);
    }

    private function page(Response $response, $lead, ?string $msg, ?string $action): void
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dog = $h($lead->get('dogName') ?: 'your dog');
        $logo = 'https://office.example.com/client/custom/img/packleader.jpg';

        // Several dogs on the request: ask which dog the files are for.
        $pick = '';
        $dogs = iterator_to_array($this->entityManager->getRDBRepository('RequestDog')
            ->where(['requestId' => $lead->getId()])->order('createdAt')->find());
        if (count($dogs) > 1) {
            $opts = '';
            foreach ($dogs as $d) {
                $opts .= '<option value="' . $h($d->getId()) . '">' . $h($d->get('name')) . '</option>';
            }
            $pick = '<label class="pick-dog">Which dog are these for?<select name="dog" required>' . $opts . '</select></label>';
        } elseif (count($dogs) === 1) {
            $pick = '<input type="hidden" name="dog" value="' . $h(reset($dogs)->getId()) . '">';
        }

        $form = $action === null ? '' :
            '<form method="post" enctype="multipart/form-data" action="' . $h($action) . '">'
            . $pick
            . '<label class="pick">Choose files or take a photo'
            . '<input type="file" name="files[]" multiple accept="' . Booking::ACCEPT . '" required></label>'
            . '<p class="small">PDF or photos, up to 10 MB each, up to 6 at a time.</p>'
            . '<button type="submit">Upload</button></form>';

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>Shot records</title><style>'
            . 'body{font-family:Georgia,serif;max-width:32rem;margin:0 auto;padding:2rem 1rem 3rem;line-height:1.5;color:#e0e1db;background:#04090c}'
            . 'img.logo{display:block;width:min(100%,240px);height:auto;margin:0 auto 1rem}'
            . 'h1{color:#84c432;font-family:Arial,sans-serif;letter-spacing:.04em;text-align:center;font-size:1.6rem}'
            . '.msg{border:1px solid #84c432;border-radius:.4rem;padding:.75rem 1rem}'
            . '.pick{display:block;border:2px dashed #3a4a3a;border-radius:.5rem;padding:1.25rem;text-align:center;cursor:pointer}'
            . '.pick input{display:block;margin:.75rem auto 0;color:#e0e1db;max-width:100%}'
            . '.pick-dog{display:block;margin:0 0 1rem}.pick-dog select{display:block;width:100%;margin-top:.3rem;padding:.6rem;border-radius:.35rem;background:#0c1512;color:#e0e1db;border:1px solid #3a4a3a;font:inherit}'
            . '.small{font-size:.9rem;color:#a9aea4}'
            . 'button{width:100%;padding:.9rem;border:0;border-radius:.4rem;background:#84c432;color:#04090c;font:700 1.1rem Arial,sans-serif;letter-spacing:.04em;text-transform:uppercase;cursor:pointer}'
            . 'button:active{background:#6ea028}a{color:#84c432}'
            . '</style></head><body>'
            . '<img class="logo" src="' . $logo . '" alt="Pack Leader K9">'
            . '<h1>Shot records for ' . $dog . '</h1>'
            . ($msg ? '<p class="msg">' . $h($msg) . '</p>' : '')
            . '<p>Please upload proof of a current rabies vaccine. If you have DAPP (distemper/parvo) or Bordetella records, add those too. A photo of the vet paperwork is fine.</p>'
            . $form
            . '<p class="small">Questions? Call or text (727) 555-0100.</p>'
            . Footer::html()
            . '</body></html>';

        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->writeBody($html);
    }
}
