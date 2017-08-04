<?php declare(strict_types=1);

namespace SimpleThings\EntityAudit;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use SimpleThings\EntityAudit\Exception\NotAuditedException;
use SimpleThings\EntityAudit\Metadata\MetadataFactory;

class RevisionRepository
{
    private $em;
    private $metadataFactory;

    public function __construct(EntityManager $em, MetadataFactory $metadataFactory)
    {
        $this->em = $em;
        $this->metadataFactory = $metadataFactory;
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
        $connection = $this->em->getConnection();
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

    private function createRevision(array $row): Revision
    {
        return new Revision(
            $row['id'],
            \DateTime::createFromFormat($this->platform->getDateTimeFormatString(), $row['timestamp']),
            $row['username']
        );
    }
}