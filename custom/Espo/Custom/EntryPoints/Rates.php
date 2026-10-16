<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Public rates page: https://office.example.com/?entryPoint=rates
// Shows the published "Rates" knowledge base article. That article is written by
// scripts/packleader-rates-page.py from scripts/packleader_pricing.py, the same
// numbers the invoices use. Installed to custom/Espo/Custom/EntryPoints/Rates.php.
namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;

class Rates implements EntryPoint
{
    use NoAuth;

    public function __construct(private EntityManager $entityManager)
    {}

    public function run(Request $request, Response $response): void
    {
        $article = $this->entityManager
            ->getRDBRepository('KnowledgeBaseArticle')
            ->where([
                'name' => 'Rates',
                'status' => 'Published',
            ])
            ->findOne();

        if (!$article) {
            throw new NotFound();
        }

        $body = (string) $article->get('body');
        $back = 'https://go.example.com/book';
        $logo = 'https://office.example.com/client/custom/img/packleader.jpg';

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Pack Leader K9 rates</title><style>'
            . 'body{font-family:Georgia,serif;max-width:40rem;margin:0 auto;padding:2rem 1rem 3rem;line-height:1.5;color:#e0e1db;background:#04090c}'
            . 'img.logo{display:block;width:min(100%,320px);height:auto;margin:0 auto 1.5rem}'
            . 'h1,h2{color:#84c432;font-family:Arial,sans-serif;letter-spacing:.04em}'
            . 'h1{text-align:center}h2{font-size:1.15rem;margin:2rem 0 .25rem}'
            . '.tag{text-align:center;font-style:italic}'
            . 'table{width:100%;border-collapse:collapse}'
            . 'td{padding:.45rem 0;border-bottom:1px solid #1d2a20;vertical-align:top}'
            . 'td:last-child{text-align:right;white-space:nowrap;color:#84c432;font-family:Arial,sans-serif;padding-left:1rem}'
            . 'a{color:#84c432}'
            . '</style></head><body>'
            . '<img class="logo" src="' . $logo . '" alt="Pack Leader K9">'
            . '<h1>Rates</h1>'
            . $body
            . '<p><a href="' . $back . '">Book now</a></p></body></html>';

        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->writeBody($html);
    }
}
