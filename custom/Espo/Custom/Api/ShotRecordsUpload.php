<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Receives the shot-records upload form (see EntryPoints/ShotRecords.php).
//   POST /api/v1/PackLeader/shotRecords/:id?t=<token>   multipart, field files[]
// No login (route noAuth); the per-request random token is the credential.
// Accepts PDF and photos only (checked by content, not by name), 10 MB each,
// 6 per upload, 20 per request. Answers with a 303 back to the upload page.
// Installed to custom/Espo/Custom/Api/ShotRecordsUpload.php, route in
// custom/Espo/Custom/Resources/routes.json, by scripts/packleader-office-flow-install.py.
namespace Espo\Custom\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\Attachment;
use Espo\Entities\Note;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ShotRecordsUpload implements Action
{
    private const MAX_FILE = 10 * 1024 * 1024;
    private const MAX_PER_UPLOAD = 6;
    private const MAX_TOTAL = 20;
    private const LINK_DAYS = 90;
    private const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public function __construct(private EntityManager $entityManager)
    {}

    public function process(Request $request): Response
    {
        $id = (string) $request->getRouteParam('id');
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
            throw new NotFound();
        }

        // Files go on the chosen dog of this request; a request with no dog
        // records (made by the old form) keeps them on the request itself.
        $parent = $lead;
        $dogs = iterator_to_array($this->entityManager->getRDBRepository('RequestDog')
            ->where(['requestId' => $lead->getId()])->find());
        $want = (string) ($_POST['dog'] ?? '');
        foreach ($dogs as $d) {
            if ($want !== '' && $d->getId() === $want) $parent = $d;
        }
        if ($parent === $lead && count($dogs) === 1) $parent = reset($dogs);
        if ($parent === $lead && count($dogs) > 1) throw new NotFound();

        [$saved, $error] = $this->receive($parent);

        if ($saved) {
            $note = $this->entityManager->getRDBRepositoryByClass(Note::class)->getNew();
            $note->setType(Note::TYPE_POST);
            $note->setParent($lead);
            $note->setPost('Client uploaded ' . $saved . ' shot record file' . ($saved === 1 ? '' : 's')
                . ($parent !== $lead ? ' for ' . $parent->get('name') : '') . '.');
            $this->entityManager->saveEntity($note);
        }

        $back = '/?entryPoint=shotRecords&id=' . $id . '&t=' . $token . '&ok=' . $saved
            . ($error ? '&err=' . rawurlencode($error) : '');

        $response = ResponseComposer::empty();
        $response->setStatus(303);
        $response->setHeader('Location', $back);

        return $response;
    }

    /** @return array{int, ?string} */
    private function receive(Entity $parent): array
    {
        $files = $_FILES['files'] ?? null;

        if (!$files || !is_array($files['name'] ?? null)) {
            return [0, 'No file was chosen, or the files were too large.'];
        }

        $have = $this->entityManager
            ->getRDBRepositoryByClass(Attachment::class)
            ->where(['parentType' => $parent->getEntityType(), 'parentId' => $parent->getId(), 'field' => 'shotRecords'])
            ->count();

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $saved = 0;
        $skipped = [];

        foreach ($files['name'] as $i => $rawName) {
            if ($saved >= self::MAX_PER_UPLOAD || $have + $saved >= self::MAX_TOTAL) {
                $skipped[] = 'too many files';
                break;
            }

            $tmp = $files['tmp_name'][$i] ?? '';

            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                $skipped[] = 'a file did not upload';
                continue;
            }

            $size = filesize($tmp);

            if ($size === false || $size === 0 || $size > self::MAX_FILE) {
                $skipped[] = 'a file is over 10 MB';
                continue;
            }

            $mime = $finfo->file($tmp);

            if (!isset(self::TYPES[$mime])) {
                $skipped[] = 'only PDF and photos are accepted';
                continue;
            }

            $name = preg_replace('/[^\w.\- ]+/u', '_', basename((string) $rawName)) ?: 'shot-record';
            $name = mb_substr($name, 0, 100);

            if (!preg_match('/\.[A-Za-z0-9]{2,5}$/', $name)) {
                $name .= '.' . self::TYPES[$mime];
            }

            $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getNew();
            $attachment->setName($name);
            $attachment->setType($mime);
            $attachment->setRole(Attachment::ROLE_ATTACHMENT);
            $attachment->setTargetField('shotRecords');
            $attachment->setParent($parent);
            $attachment->setContents((string) file_get_contents($tmp));
            $this->entityManager->saveEntity($attachment);

            $saved++;
        }

        $error = $skipped ? 'Some files were not saved: ' . implode(', ', array_unique($skipped)) . '.' : null;

        return [$saved, $error];
    }
}
