<?php declare(strict_types=1);

namespace SimpleThings\EntityAudit\Mapping\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * @Annotation
 * @Target("PROPERTY")
 */
final class Ignore extends Annotation
{
}
