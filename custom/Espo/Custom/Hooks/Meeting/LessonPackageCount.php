<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Espo\Custom\Hooks\Meeting;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * A lesson was added, changed status, moved package or was deleted: save its
 * lesson package (and the one it left) so the package recounts. The counting
 * is in Hooks/LessonPackage/Counts.php.
 */
class LessonPackageCount implements AfterSave, AfterRemove
{
    public function __construct(private EntityManager $entityManager) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('lessonPackageId') &&
            !$entity->isAttributeChanged('status')
        ) {
            return;
        }

        $this->recount([$entity->get('lessonPackageId'), $entity->getFetched('lessonPackageId')]);
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->recount([$entity->get('lessonPackageId')]);
    }

    /** @param array<?string> $ids */
    private function recount(array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            $package = $this->entityManager->getEntityById('LessonPackage', $id);

            if ($package) {
                $this->entityManager->saveEntity($package);
            }
        }
    }
}
