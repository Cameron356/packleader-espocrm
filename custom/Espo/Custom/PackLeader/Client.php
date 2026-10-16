<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Returning-client sign-in, shared by the client page and its two routes.
//   EntryPoints/Client.php        ?entryPoint=client&c=<contact>&t=<token>
//   Api/BookAgainSubmit.php       POST /api/v1/PackLeader/bookAgain/:id?t=<token>
//   Api/ClientLinkRequest.php     POST /api/v1/PackLeader/clientLink
// A contact has two kinds of link, both 32 random hex characters:
//   Client link (Contact.clientLink)  permanent; in every office email. She
//        clears the field to kill it; the next email or request makes a new one.
//   Sign-in link (signinToken)       24 hours; sent by "Booked with us before?".
// Installed to custom/Espo/Custom/PackLeader/Client.php by
// scripts/packleader-office-flow-install.py.
namespace Espo\Custom\PackLeader;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

final class Client
{
    public const SITE = 'https://office.example.com/';
    public const SIGNIN_HOURS = 24;

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function url(string $contactId, string $token): string
    {
        return self::SITE . '?entryPoint=client&c=' . $contactId . '&t=' . $token;
    }

    /** The contact this link belongs to, or null (bad, expired or reset link). */
    public static function find(EntityManager $em, string $id, string $token): ?Entity
    {
        if (!preg_match('/^[a-f0-9]{17,24}$/', $id) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $c = $em->getEntityById('Contact', $id);
        if (!$c) {
            return null;
        }
        parse_str((string) parse_url((string) $c->get('clientLink'), PHP_URL_QUERY), $q);
        if (($q['c'] ?? '') === $id && is_string($q['t'] ?? null) && hash_equals($q['t'], $token)) {
            return $c;
        }
        $signin = (string) $c->get('signinToken');
        $e = (string) $c->get('signinExpires');
        $until = $e !== '' ? strtotime($e . ' UTC') : false;
        if ($signin !== '' && hash_equals($signin, $token) && $until && $until > time()) {
            return $c;
        }
        return null;
    }

    /** The contact's permanent link, made on first use. */
    public static function ensureLink(EntityManager $em, Entity $c): string
    {
        $link = (string) $c->get('clientLink');
        if ($link === '') {
            $link = self::url($c->getId(), self::token());
            $c->set('clientLink', $link);
            $em->saveEntity($c, ['silent' => true]);
        }
        return $link;
    }

    /** The contact's dogs, oldest first. */
    public static function dogs(EntityManager $em, Entity $c): array
    {
        return iterator_to_array($em->getRDBRepository('Dog')
            ->where(['contactId' => $c->getId()])->order('createdAt', 'ASC')->find());
    }
}
