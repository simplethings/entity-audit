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

namespace SimpleThings\EntityAudit;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\Query;
use SimpleThings\EntityAudit\Exception\DeletedException;
use SimpleThings\EntityAudit\Exception\InvalidRevisionException;
use SimpleThings\EntityAudit\Exception\NoRevisionFoundException;
use SimpleThings\EntityAudit\Exception\NotAuditedException;
use SimpleThings\EntityAudit\Metadata\MetadataFactory;

class AuditReader
{
    /**
     * Decides if audited ToMany collections are loaded
     */
    const LOAD_AUDITED_COLLECTIONS = 'loadAuditedCollections';

    /**
     * Decides if audited ToOne collections are loaded
     */
    const LOAD_AUDITED_ENTITIES = 'loadAuditedEntities';

    /**
     * Decides if native (not audited) ToMany collections are loaded
     */
    const LOAD_NATIVE_COLLECTIONS = 'loadNativeCollections';

    /**
     * Decides if native (not audited) ToOne collections are loaded
     */
    const LOAD_NATIVE_ENTITIES = 'loadNativeEntities';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var AuditConfiguration
     */
    private $config;

    /**
     * @var MetadataFactory
     */
    private $metadataFactory;

    /**
     * @var AbstractPlatform
     */
    private $platform;

    /**
     * @var QuoteStrategy
     */
    private $quoteStrategy;

    /**
     * @var EntityFactory
     */
    private $entityFactory;

    public function __construct(
        EntityManagerInterface $em,
        AuditConfiguration $config,
        MetadataFactory $factory,
        array $options = []
    ) {
        $this->em = $em;
        $this->config = $config;
        $this->metadataFactory = $factory;
        $this->platform = $this->em->getConnection()->getDatabasePlatform();
        $this->quoteStrategy = $this->em->getConfiguration()->getQuoteStrategy();

        $this->entityFactory = new EntityFactory($this, $em, $factory, $options);
    }

    public function getConnection(): Connection
    {
        return $this->em->getConnection();
    }

    public function getConfiguration(): AuditConfiguration
    {
        return $this->config;
    }

    /**
     * Clears entity cache. Call this if you are fetching subsequent revisions using same AuditManager.
     */
    public function clearEntityCache()
    {
        $this->entityFactory->clearEntityCache();
    }

    /**
     * Find a class at the specific revision.
     *
     * This method does not require the revision to be exact but it also searches for an earlier revision
     * of this entity and always returns the latest revision below or equal the given revision. Commonly, it
     * returns last revision INCLUDING "DEL" revision. If you want to throw exception instead, set
     * $threatDeletionAsException to true.
     *
     * @param string $className
     * @param int|array $id
     * @param int $revision
     * @param array $options
     *
     * @return object
     *
     * @throws DeletedException
     * @throws NoRevisionFoundException
     * @throws NotAuditedException
     * @throws \Doctrine\DBAL\DBALException
     */
    public function find(string $className, $id, int $revision, array $options = [])
    {
        $options = array_merge(['threatDeletionsAsExceptions' => false], $options);

        if (!$this->metadataFactory->isAudited($className)) {
            throw new NotAuditedException($className);
        }

        $class = $this->em->getClassMetadata($className);
        $connection = $this->getConnection();

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->from($tableName = $this->config->getTableName($class), 'e');
        $queryBuilder->where(sprintf('e.%s <= ?', $this->config->getRevisionFieldName()));

        foreach ($class->identifier as $idField) {
            if (is_array($id) && count($id) > 0) {
                $idKeys = array_keys($id);
                $columnName = $idKeys[0];
            } elseif (isset($class->fieldMappings[$idField])) {
                $columnName = $class->fieldMappings[$idField]['columnName'];
            } elseif (isset($class->associationMappings[$idField])) {
                $columnName = $class->associationMappings[$idField]['joinColumns'][0]['name'];
            } else {
                throw new \RuntimeException('column name not found  for'.$idField);
            }

            $queryBuilder->andWhere(sprintf('e.%s = ?', $columnName));
        }

        if (!is_array($id)) {
            $id = [$class->identifier[0] => $id];
        }

        $queryBuilder->addSelect('e.'.$this->config->getRevisionTypeFieldName());

        $columnMap = $this->createColumnMap($class);
        $this->prepareSelects($queryBuilder, $class);

        foreach ($class->associationMappings as $assoc) {
            if (($assoc['type'] & ClassMetadata::TO_ONE) == 0 || !$assoc['isOwningSide']) {
                continue;
            }

            foreach ($assoc['joinColumnFieldNames'] as $sourceCol) {
                $tableAlias = $class->isInheritanceTypeJoined()
                && $class->isInheritedAssociation($assoc['fieldName'])
                && !$class->isIdentifier($assoc['fieldName'])
                    ? 're' // root entity
                    : 'e';

                $queryBuilder->addSelect($tableAlias.'.'.$sourceCol);
                $columnMap[$sourceCol] = $this->platform->getSQLResultCasing($sourceCol);
            }
        }

        if ($class->isInheritanceTypeJoined() && $class->name != $class->rootEntityName) {
            $rootClass = $this->em->getClassMetadata($class->rootEntityName);
            $rootTableName = $this->config->getTableName($rootClass);

            $condition = ["re.{$this->config->getRevisionFieldName()} = e.{$this->config->getRevisionFieldName()}"];
            foreach ($class->getIdentifierColumnNames() as $name) {
                $condition[] = "re.$name = e.$name";
            }

            $queryBuilder->innerJoin('e', $rootTableName, 're', implode(' AND ', $condition));
        }

        if (!$class->isInheritanceTypeNone()) {
            $queryBuilder->addSelect($class->discriminatorColumn['name']);

            if ($class->isInheritanceTypeSingleTable() && $class->discriminatorValue !== null) {
                // Support for single table inheritance sub-classes
                $allDiscrValues = array_flip($class->discriminatorMap);

                $queriedDiscrValues = [$connection->quote($class->discriminatorValue)];
                foreach ($class->subClasses as $subclassName) {
                    $queriedDiscrValues[] = $connection->quote($allDiscrValues[$subclassName]);
                }

                $queryBuilder->andWhere(
                    sprintf(
                        '%s IN (%s)',
                        $class->discriminatorColumn['name'],
                        implode(', ', $queriedDiscrValues)
                    )
                );
            }
        }

        $queryBuilder->setParameters(array_merge([$revision], array_values($id)));
        $queryBuilder->orderBy('e.'.$this->config->getRevisionFieldName(), 'DESC');

        $row = $queryBuilder->execute()->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            throw new NoRevisionFoundException($class->name, $id, $revision);
        }

        if ($options['threatDeletionsAsExceptions'] && $row[$this->config->getRevisionTypeFieldName()] === 'DEL') {
            throw new DeletedException($class->name, $id, $revision);
        }

        unset($row[$this->config->getRevisionTypeFieldName()]);

        return $this->entityFactory->createEntity($class->name, $columnMap, $row, $revision, $options);
    }

    /**
     * Return a list of all revisions.
     *
     * @param int $limit
     * @param int $offset
     *
     * @return Revision[]
     */
    public function findRevisionHistory(int $limit = 20, int $offset = 0): array
    {
        $revisionsData = $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($this->config->getRevisionTableName())
            ->orderBy('id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->execute()
            ->fetchAll();

        $revisions = [];
        foreach ($revisionsData as $row) {
            $revisions[] = $this->createRevision($row);
        }

        return $revisions;
    }

    /**
     * Return a list of ChangedEntity instances created at the given revision.
     *
     * @param int $revision
     *
     * @return ChangedEntity[]
     */
    public function findEntitiesChangedAtRevision(int $revision): array
    {
        $auditedEntities = $this->metadataFactory->getAllClassNames();
        $connection = $this->getConnection();

        $changedEntities = [];
        foreach ($auditedEntities as $className) {
            $class = $this->em->getClassMetadata($className);

            if ($class->isInheritanceTypeSingleTable() && count($class->subClasses) > 0) {
                continue;
            }

            $queryBuilder = $connection->createQueryBuilder()
                ->select('e.'.$this->config->getRevisionTypeFieldName())
                ->from($this->config->getTableName($class), 'e');

            $queryBuilder->where(
                sprintf(
                    'e.%s = %s',
                    $this->config->getRevisionFieldName(),
                    $queryBuilder->createPositionalParameter($revision)
                )
            );

            $columnMap = $this->createColumnMap($class);
            $this->prepareSelects($queryBuilder, $class);

            foreach ($class->associationMappings as $assoc) {
                if (($assoc['type'] & ClassMetadata::TO_ONE) > 0 && $assoc['isOwningSide']) {
                    foreach ($assoc['targetToSourceKeyColumns'] as $sourceCol) {
                        $queryBuilder->addSelect($sourceCol);
                        $columnMap[$sourceCol] = $this->platform->getSQLResultCasing($sourceCol);
                    }
                }
            }

            if ($class->isInheritanceTypeSingleTable()) {
                $queryBuilder->addSelect('e.'.$class->discriminatorColumn['name']);
                $queryBuilder->andWhere(
                    sprintf(
                        'e.%s = %s',
                        $class->discriminatorColumn['fieldName'],
                        $queryBuilder->createPositionalParameter($class->discriminatorValue)
                    )
                );
            } elseif ($class->isInheritanceTypeJoined() && $class->name !== $class->rootEntityName) {
                $rootClass = $this->em->getClassMetadata($class->rootEntityName);
                $rootTableName = $this->config->getTableName($rootClass);

                $condition = ["re.{$this->config->getRevisionFieldName()} = e.{$this->config->getRevisionFieldName()}"];
                foreach ($class->getIdentifierColumnNames() as $name) {
                    $condition[] = "re.$name = e.$name";
                }

                $queryBuilder->addSelect('re.'.$class->discriminatorColumn['name']);
                $queryBuilder->innerJoin('e', $rootTableName, 're', implode(' AND ', $condition));
            }

            $revisionsData = $queryBuilder->execute()->fetchAll();

            foreach ($revisionsData as $row) {
                $id = [];
                foreach ($class->identifier as $idField) {
                    $id[$idField] = $row[$idField];
                }

                $entity = $this->entityFactory->createEntity($className, $columnMap, $row, $revision);
                $changedEntities[] = new ChangedEntity(
                    $className,
                    $id,
                    $row[$this->config->getRevisionTypeFieldName()],
                    $entity
                );
            }
        }

        return $changedEntities;
    }

    /**
     * Return the revision object for a particular revision.
     *
     * @param  int $rev
     *
     * @return Revision
     *
     * @throws InvalidRevisionException
     */
    public function findRevision(int $rev): Revision
    {
        $revisionsData = $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($this->config->getRevisionTableName(), 'r')
            ->where('r.id = :id')
            ->setParameter('id', $rev)
            ->execute()
            ->fetchAll();

        if (count($revisionsData) === 1) {
            return $this->createRevision($revisionsData[0]);
        }

        throw new InvalidRevisionException($rev);
    }

    /**
     * Find all revisions that were made of entity class with given id.
     *
     * @param string $className
     * @param int|array $id
     *
     * @throws NotAuditedException
     * @return Revision[]
     */
    public function findRevisions(string $className, $id): array
    {
        if (!$this->metadataFactory->isAudited($className)) {
            throw new NotAuditedException($className);
        }

        $class = $this->em->getClassMetadata($className);

        $connection = $this->getConnection();
        $queryBuilder = $connection->createQueryBuilder()
            ->select('r.*')
            ->from($this->config->getRevisionTableName(), 'r')
            ->innerJoin(
                'r',
                $this->config->getTableName($class),
                'e',
                'r.id = e.'.$this->config->getRevisionFieldName()
            )
            ->orderBy('r.id', 'DESC');

        $this->prepareWhereStatement($id, $queryBuilder, $class);

        $revisionsData = $queryBuilder->execute()->fetchAll();

        $revisions = [];
        foreach ($revisionsData as $row) {
            $revisions[] = $this->createRevision($row);
        }

        return $revisions;
    }

    /**
     * Gets the current revision of the entity with given ID.
     *
     * @param string $className
     * @param int|array $id
     *
     * @throws NotAuditedException
     * @return int
     */
    public function getCurrentRevision(string $className, $id): int
    {
        if (!$this->metadataFactory->isAudited($className)) {
            throw new NotAuditedException($className);
        }

        $class = $this->em->getClassMetadata($className);

        $queryBuilder = $this->getConnection()->createQueryBuilder()
            ->select('e.'.$this->config->getRevisionFieldName())
            ->from($this->config->getTableName($class), 'e')
            ->orderBy('e.'.$this->config->getRevisionFieldName(), 'DESC');

        $this->prepareWhereStatement($id, $queryBuilder, $class);

        return (int)$queryBuilder->execute()->fetchColumn();
    }

    /**
     * Get an array with the differences of between two specific revisions of
     * an object with a given id.
     *
     * @param string $className
     * @param int|array $id
     * @param int $oldRevision
     * @param int $newRevision
     *
     * @return array
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \SimpleThings\EntityAudit\Exception\NotAuditedException
     * @throws \SimpleThings\EntityAudit\Exception\NoRevisionFoundException
     * @throws \SimpleThings\EntityAudit\Exception\DeletedException
     */
    public function diff(string $className, $id, int $oldRevision, int $newRevision): array
    {
        $oldObject = $this->find($className, $id, $oldRevision);
        $newObject = $this->find($className, $id, $newRevision);

        $metadata = $this->metadataFactory->getMetadataFor($className);

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

    /**
     * @param string $className
     * @param int|array $id
     *
     * @return array
     *
     * @throws NotAuditedException
     */
    public function getEntityHistory(string $className, $id): array
    {
        if (!$this->metadataFactory->isAudited($className)) {
            throw new NotAuditedException($className);
        }

        $class = $this->em->getClassMetadata($className);

        $revisionFieldName = $this->config->getRevisionFieldName();
        $queryBuilder = $this->getConnection()->createQueryBuilder()
            ->select($revisionFieldName)
            ->from($this->config->getTableName($class), 'e')
            ->orderBy('e.'.$this->config->getRevisionFieldName(), 'DESC');

        $this->prepareWhereStatement($id, $queryBuilder, $class);

        $columnMap = [];

        foreach ($class->fieldNames as $columnName => $field) {
            $queryBuilder->addSelect(
                sprintf(
                    '%s AS %s',
                    $this->quoteStrategy->getColumnName($field, $class, $this->platform),
                    $this->platform->quoteSingleIdentifier($field)
                )
            );
            $columnMap[$field] = $this->platform->getSQLResultCasing($columnName);
        }

        foreach ($class->associationMappings as $assoc) {
            if (($assoc['type'] & ClassMetadata::TO_ONE) == 0 || !$assoc['isOwningSide']) {
                continue;
            }

            foreach ($assoc['targetToSourceKeyColumns'] as $sourceCol) {
                $queryBuilder->addSelect($sourceCol);
                $columnMap[$sourceCol] = $this->platform->getSQLResultCasing($sourceCol);
            }
        }

        $stmt = $queryBuilder->execute();

        $result = [];
        while ($row = $stmt->fetch(Query::HYDRATE_ARRAY)) {
            $rev = $row[$revisionFieldName];
            unset($row[$revisionFieldName]);

            $result[] = $this->entityFactory->createEntity($class->name, $columnMap, $row, $rev);
        }

        return $result;
    }

    private function prepareWhereStatement($id, QueryBuilder $queryBuilder, ClassMetadata $class)
    {
        if (!is_array($id)) {
            $id = [$class->identifier[0] => $id];
        }

        $queryBuilder->setParameters(array_values($id));

        foreach ($class->identifier as $idField) {
            if (isset($class->fieldMappings[$idField])) {
                $queryBuilder->andWhere(sprintf('e.%s = ?', $class->fieldMappings[$idField]['columnName']));

                continue;
            }

            if (isset($class->associationMappings[$idField])) {
                $queryBuilder->andWhere(
                    sprintf(
                        'e.%s = ?',
                        $class->associationMappings[$idField]['joinColumns'][0]['name']
                    )
                );
            }
        }
    }

    private function prepareSelects(QueryBuilder $queryBuilder, ClassMetadata $class)
    {
        foreach ($class->fieldNames as $columnName => $field) {
            $tableAlias = $class->isInheritanceTypeJoined()
            && $class->isInheritedField($field)
            && !$class->isIdentifier($field)
                ? 're' // root entity
                : 'e';

            $type = Type::getType($class->fieldMappings[$field]['type']);

            $queryBuilder->addSelect(
                sprintf(
                    '%s AS %s',
                    $type->convertToPHPValueSQL(
                        $tableAlias.'.'.$this->quoteStrategy->getColumnName($field, $class, $this->platform),
                        $this->platform
                    ),
                    $this->platform->quoteSingleIdentifier($field)
                )
            );
        }
    }

    private function createColumnMap(ClassMetadata $class): array
    {
        $columnMap = [];

        foreach ($class->fieldNames as $columnName => $field) {
            $columnMap[$field] = $this->platform->getSQLResultCasing($columnName);
        }

        return $columnMap;
    }

    private function createRevision(array $row): Revision
    {
        return new Revision(
            $row['id'],
            \DateTime::createFromFormat($this->platform->getDateTimeFormatString(), $row['timestamp']),
            $row['username']
        );
    }
}
