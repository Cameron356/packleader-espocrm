<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Public boarding-terms page: https://office.example.com/?entryPoint=boardingTerms
// Renders the "Boarding agreement terms" knowledge base article (what every booking's
// approval email links to). Installed to custom/Espo/Custom/EntryPoints/BoardingTerms.php.
namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\NotFound;
use Espo\Custom\PackLeader\Footer;
use Espo\ORM\EntityManager;

class BoardingTerms implements EntryPoint
{
    use NoAuth;

    public function __construct(private EntityManager $entityManager)
    {}

    public function run(Request $request, Response $response): void
    {
        $article = $this->entityManager
            ->getRDBRepository('KnowledgeBaseArticle')
            ->where([
                'name' => 'Boarding agreement terms',
                'status' => 'Published',
            ])
            ->findOne();

        if (!$article) {
            throw new NotFound();
        }

        $name = htmlspecialchars((string) $article->get('name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = (string) $article->get('body');
        $back = 'https://go.example.com/book';
        $logo = 'https://office.example.com/client/custom/img/packleader.jpg';

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $name . '</title><style>'
            . 'body{font-family:Georgia,serif;max-width:40rem;margin:0 auto;padding:2rem 1rem 3rem;line-height:1.5;color:#e0e1db;background:#04090c}'
            . 'img.logo{display:block;width:min(100%,320px);height:auto;margin:0 auto 1.5rem}'
            . 'h1{color:#84c432;font-family:Arial,sans-serif;letter-spacing:.04em;text-align:center}'
            . 'a{color:#84c432}'
            . '</style></head><body>'
            . '<img class="logo" src="' . $logo . '" alt="Pack Leader K9">'
            . '<h1>' . $name . '</h1>'
            . $body
            . '<p><a href="' . $back . '">Back to the form</a></p>'
            . Footer::html()
            . '</body></html>';

        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->writeBody($html);
    }
}
