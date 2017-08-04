<?php declare(strict_types=1);

namespace SimpleThings\EntityAudit;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use SimpleThings\EntityAudit\Metadata\MetadataFactory;

class EntityComparator
{
    private $metadataFactory;
    private $em;

    public function __construct(MetadataFactory $metadataFactory, EntityManagerInterface $em)
    {
        $this->metadataFactory = $metadataFactory;
        $this->em = $em;
    }

    /**
     * Get an array with the differences of between two objects.
     *
     * @param object $oldObject
     * @param object $newObject
     *
     * @return array
     */
    public function compare($oldObject, $newObject): array
    {
        $metadata = $this->metadataFactory->getMetadataFor(ClassUtils::getClass($newObject));

        $oldValues = $metadata->getEntityValues($oldObject);
        $newValues = $metadata->getEntityValues($newObject);

        $keys = array_keys(array_merge($oldValues, $newValues));
        $diff = [];

        foreach ($keys as $field) {
            $old = $oldValues[$field] ?? null;
            $new = $newValues[$field] ?? null;

            if ($this->getValueToCompare($old) === $this->getValueToCompare($new)) {
                $row = ['old' => '', 'new' => '', 'same' => $old];
            } else {
                $row = ['old' => $old, 'new' => $new, 'same' => ''];
            }

            $diff[$field] = $row;
        }

        return $diff;
    }

    private function getValueToCompare($value)
    {
        $metadataFactory = $this->em->getMetadataFactory();

        // If the value is an associated entity, we have to compare the identifiers.
        if (is_object($value) && $metadataFactory->hasMetadataFor(ClassUtils::getClass($value))) {
            return $metadataFactory->getMetadataFor(ClassUtils::getClass($value))
                ->getIdentifierValues($value);
        }

        return $value;
    }
}