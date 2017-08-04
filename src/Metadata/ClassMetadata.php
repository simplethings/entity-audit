<?php declare(strict_types=1);
/*
 * (c) 2011 SimpleThings GmbH
 *
 * @package SimpleThings\EntityAudit
 * @author Benjamin Eberlei <eberlei@simplethings.de>
 * @link http://www.simplethings.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */

namespace SimpleThings\EntityAudit\Metadata;

use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * @author David Badura <d.a.badura@gmail.com>
 */
class ClassMetadata
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var ClassMetadataInfo
     */
    public $entity;

    /**
     * @var string[]
     */
    public $ignoredFields = [];

    /**
     * @param ClassMetadataInfo $classMetadataInfo
     */
    public function __construct(ClassMetadataInfo $classMetadataInfo)
    {
        $this->entity = $classMetadataInfo;
        $this->name = $classMetadataInfo->name;
    }

    /**
     * Get the values for a specific entity as an associative array
     *
     * @param object $entity
     *
     * @return array
     */
    public function getEntityValues($entity): array
    {
        $metadata = $this->entity;

        $values = [];

        // Fetch simple fields values
        foreach ($metadata->getFieldNames() as $fieldName) {
            $values[$fieldName] = $metadata->getFieldValue($entity, $fieldName);
        }

        // Fetch associations identifiers values
        foreach ($metadata->getAssociationNames() as $associationName) {
            // Do not get OneToMany or ManyToMany collections because not relevant to the revision.
            if ($metadata->getAssociationMapping($associationName)['isOwningSide']) {
                $values[$associationName] = $metadata->getFieldValue($entity, $associationName);
            }
        }

        return $values;
    }
}
