<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Espo\Custom\Hooks\LessonPackage;

use Espo\Core\Hook\Hook\AfterRelate;
use Espo\Core\Hook\Hook\AfterUnrelate;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RelateOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\UnrelateOptions;

/**
 * Keeps a lesson package's counts right. Every save recounts its lessons:
 *   Done   = lessons marked Held
 *   Booked = lessons still Planned
 *   Left   = bought - booked - done   (Not Held gives the session back)
 * A lesson that changes saves its package (Hooks/Meeting/LessonPackageCount.php);
 * linking or unlinking a lesson from the package's panel lands here too.
 */
class Counts implements BeforeSave, AfterRelate, AfterUnrelate
{
    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $held = $booked = 0;

        if (!$entity->isNew() && $entity->hasId()) {
            $held = $this->count($entity->getId(), 'Held');
            $booked = $this->count($entity->getId(), 'Planned');
        }

        $bought = (int) $entity->get('sessions');
        $left = $bought - $held - $booked;

        $status = 'Active';

        if ($left < 0) {
            $status = 'Overbooked';
        } else if ($held >= $bought) {
            $status = 'Finished';
        } else if ($left === 0) {
            $status = 'AllBooked';
        }

        $entity->set([
            'sessionsHeld' => $held,
            'sessionsBooked' => $booked,
            'sessionsLeft' => $left,
            'status' => $status,
        ]);
    }

    public function afterRelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        array $columnData,
        RelateOptions $options
    ): void {
        if ($relationName === 'lessons') {
            $this->entityManager->saveEntity($entity);
        }
    }

    public function afterUnrelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        UnrelateOptions $options
    ): void {
        if ($relationName === 'lessons') {
            $this->entityManager->saveEntity($entity);
        }
    }

    private function count(string $id, string $status): int
    {
        return $this->entityManager
            ->getRDBRepository('Meeting')
            ->where(['lessonPackageId' => $id, 'status' => $status])
            ->count();
    }
}
