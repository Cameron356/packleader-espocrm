<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Shared "Powered by EspoCRM · Source code (AGPLv3)" footer for the client-facing pages.
// EspoCRM is AGPLv3 and its §7(b) term requires user-facing screens of a modified version to
// show a legal notice that keeps the word "EspoCRM". One helper, so every page shows the same
// thing. Small, muted, styled to match the pages (dark theme, brand green links).
// Installed to custom/Espo/Custom/PackLeader/Footer.php.
namespace Espo\Custom\PackLeader;

class Footer
{
    public static function html(): string
    {
        return '<p style="margin:2.5rem 0 0;padding-top:.8rem;border-top:1px solid #223027;'
            . 'text-align:center;color:#a9aea4;font-size:.8rem">'
            . 'Powered by <a style="color:#84c432" href="https://www.espocrm.com">EspoCRM</a>'
            . ' · <a style="color:#84c432" href="https://github.com/Cameron356/packleader-espocrm">Source code (AGPLv3)</a>'
            . '</p>';
    }
}
