<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// "Booked with us before?" on the booking page (EntryPoints/Booking.php::returningBox).
//   POST /api/v1/PackLeader/clientLink   form: emailAddress, no login (route noAuth)
// If a Contact has that email, emails them a 24-hour sign-in link to the
// returning-client page (EntryPoints/Client.php). The answer is always the same
// page ("if you're on file, a link is on its way"), so it never reveals who is
// a client. At most 3 links per contact per hour. Bots: the hidden "website"
// field and a minimum fill time, as on the booking form.
// Sends through EspoCRM's outgoing email (Administration -> Outbound Emails),
// which the installer points at the office@ Proton account.
// Installed to custom/Espo/Custom/Api/ClientLinkRequest.php, route in
// custom/Espo/Custom/Resources/routes.json, by scripts/packleader-office-flow-install.py.
namespace Espo\Custom\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Utils\Log;
use Espo\Custom\PackLeader\Client as Link;
use Espo\Entities\Email;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\EmailAddress as EmailAddressRepository;

class ClientLinkRequest implements Action
{
    private const PAGE = '/?entryPoint=booking&sent=1';
    private const MIN_SECONDS = 2;
    private const PER_HOUR = 3;

    public function __construct(
        private EntityManager $entityManager,
        private EmailSender $emailSender,
        private Log $log
    ) {}

    public function process(Request $request): Response
    {
        $p = $_POST;
        $email = mb_substr(trim((string) ($p['emailAddress'] ?? '')), 0, 255);
        $t = (int) ($p['t'] ?? 0);
        $human = trim((string) ($p['website'] ?? '')) === '' && $t > 0 && time() - $t >= self::MIN_SECONDS;

        if ($human && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $c = $this->contactFor($email);
            if ($c && $this->allowed($c)) {
                $this->sendLink($c, (string) ($c->get('emailAddress') ?: $email));   // the address on file
            }
        }

        $r = ResponseComposer::empty();
        $r->setStatus(303);
        $r->setHeader('Location', self::PAGE);
        return $r;
    }

    private function contactFor(string $email): ?Entity
    {
        /** @var EmailAddressRepository $repo */
        $repo = $this->entityManager->getRDBRepository('EmailAddress');
        $ea = $repo->getByAddress($email);
        if (!$ea || $ea->get('invalid') || $ea->get('optOut')) {
            return null;
        }
        $list = $repo->getEntityListByAddressId($ea->getId(), null, 'Contact');
        return $list[0] ?? null;
    }

    /** Rate limit per contact: PER_HOUR links in a rolling hour window. */
    private function allowed(Entity $c): bool
    {
        $w = (string) $c->get('signinWindow');
        $window = $w !== '' ? strtotime($w . ' UTC') : false;   // strtotime(' UTC') would be "now"
        if (!$window || $window < time() - 3600) {
            $c->set('signinWindow', gmdate('Y-m-d H:i:s'));
            $c->set('signinCount', 0);
        }
        if ((int) $c->get('signinCount') >= self::PER_HOUR) {
            $this->log->info('PackLeader clientLink: rate limited for contact ' . $c->getId());
            return false;
        }
        $c->set('signinCount', (int) $c->get('signinCount') + 1);
        return true;
    }

    private function sendLink(Entity $c, string $to): void
    {
        $token = Link::token();
        $c->set('signinToken', $token);
        $c->set('signinExpires', gmdate('Y-m-d H:i:s', time() + Link::SIGNIN_HOURS * 3600));
        $this->entityManager->saveEntity($c, ['silent' => true]);

        $name = $c->get('firstName') ?: 'there';
        $body = "Hi $name,\n\n"
            . "Here's your link to book with Pack Leader K9. It fills in your details and your dogs, "
            . "and shows what's coming up:\n\n"
            . Link::url($c->getId(), $token) . "\n\n"
            . "It works for 24 hours. If you didn't ask for this, you can ignore this email.\n\n"
            . "Pack Leader K9\n(727) 555-0100 · https://example.com\n";

        /** @var Email $mail */
        $mail = $this->entityManager->getRDBRepositoryByClass(Email::class)->getNew();
        $mail->setSubject('Your Pack Leader K9 booking link');
        $mail->setBody($body);
        $mail->setIsPlain(true);
        $mail->addToAddress($to);
        try {
            $this->emailSender->send($mail);
        } catch (\Throwable $e) {
            // The page still says "on its way"; the log says why it didn't go.
            $this->log->error('PackLeader clientLink: send failed for contact ' . $c->getId() . ': ' . $e->getMessage());
        }
    }
}
