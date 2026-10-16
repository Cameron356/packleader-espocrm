<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Espo\Custom\Hooks\Stay;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * A booking made by hand in the office book: when it gets a dog, copy that
 * dog's care details (food, feeding, medications, special care) and its
 * owner's emergency contact (on the Contact) into any of those fields the
 * booking leaves empty. Anything typed on the booking wins.
 * The edit form does the same as soon as the dog is picked
 * (client/custom/src/handlers/stay-dog-care.js); this covers every other way
 * a booking is saved. The robot fills these itself, so it is unaffected.
 */
class DogCare implements BeforeSave
{
    private const FIELDS = ['foodSource', 'feeding', 'medications', 'specialCare'];
    private const OWNER_FIELDS = ['emergencyName', 'emergencyPhone'];

    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $dogId = $entity->get('dogId');

        if (!$dogId || (!$entity->isNew() && !$entity->isAttributeChanged('dogId'))) {
            return;
        }

        $dog = $this->entityManager->getEntityById('Dog', $dogId);

        if (!$dog) {
            return;
        }

        $this->fill($entity, $dog, self::FIELDS);

        $owner = $dog->get('contactId') ? $this->entityManager->getEntityById('Contact', $dog->get('contactId')) : null;

        if ($owner) {
            $this->fill($entity, $owner, self::OWNER_FIELDS);
        }
    }

    /** @param string[] $fields */
    private function fill(Entity $entity, Entity $from, array $fields): void
    {
        foreach ($fields as $field) {
            $current = $entity->get($field);
            $value = $from->get($field);

            if (($current === null || $current === '') && $value !== null && $value !== '') {
                $entity->set($field, $value);
            }
        }
    }
}
