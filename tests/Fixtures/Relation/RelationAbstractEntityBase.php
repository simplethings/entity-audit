<?php declare(strict_types=1);

namespace SimpleThings\EntityAudit\Tests\Fixtures\Relation;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\MappedSuperclass
 */
class RelationAbstractEntityBase
{
    /** @ORM\Id @ORM\Column(type="integer", name="id_column") @ORM\GeneratedValue(strategy="AUTO") */
    protected $id;

    public function getId()
    {
        return $this->id;
    }
}
