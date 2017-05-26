# EntityAudit Extension for Doctrine2

| Master | 1.0 Branch |
|:----------------:|:----------:|
| [![Build Status](https://travis-ci.org/simplethings/EntityAuditBundle.svg?branch=master)](https://travis-ci.org/simplethings/EntityAuditBundle) | [![Build Status](https://travis-ci.org/simplethings/EntityAuditBundle.svg?branch=1.0)](https://travis-ci.org/simplethings/EntityAuditBundle) |
|[documentation](https://github.com/simplethings/EntityAuditBundle/blob/master/README.md)|[documentation](https://github.com/simplethings/EntityAudit/blob/1.0/README.md)

**WARNING: Master isn't stable yet and it might not be working! Please use version `^1.0` and this documentation: https://github.com/simplethings/EntityAudit/blob/1.0/README.md**

This extension for Doctrine 2 is inspired by [Hibernate Envers](http://www.jboss.org/envers) and
allows full versioning of entities and their associations.

## How does it work?

There are a bunch of different approaches to auditing or versioning of database tables. This extension
creates a mirroring table for each audited entitys table that is suffixed with "_audit". Besides all the columns
of the audited entity there are two additional fields:

* rev - Contains the global revision number generated from a "revisions" table.
* revtype - Contains one of 'INS', 'UPD' or 'DEL' as an information to which type of database operation caused this revision log entry.

The global revision table contains an id, timestamp, username and change comment field.

With this approach it is possible to version an application with its changes to associations at the particular
points in time.

This extension hooks into the SchemaTool generation process so that it will automatically
create the necessary DDL statements for your audited entities.

## Installation

###Installing the lib/bundle

Simply run assuming you have installed composer.phar or composer binary:

``` bash
$ composer require simplethings/entity-audit
```

For standalone usage you have to pass the EntityManager.

```php
use Doctrine\ORM\EntityManager;
use SimpleThings\EntityAudit\AuditManager;

$config = new \Doctrine\ORM\Configuration();
// $config ...
$conn = array();
$em = EntityManager::create($conn, $config, $evm);

$auditManager = AuditManager::create($em);
```

## Usage

### Define auditable entities
 
You need add `Auditable` annotation for the entities which you want to auditable.
  
```php
use Doctrine\ORM\Mapping as ORM;
use SimpleThings\EntityAudit\Mapping\Annotation as Audit;

/**
 * @ORM\Entity()
 * @Audit\Auditable()
 */
class Page {
 //...
}
```

You can also ignore fields in an specific entity.
 
```php
class Page {

    /**
     * @ORM\Column(type="string")
     * @Audit\Ignore()
     */
    private $ignoreMe;

}
``` 

### Use AuditReader

Querying the auditing information is done using a `SimpleThings\EntityAudit\AuditReader` instance.

You can create the audit reader from the audit manager:

```php
$auditReader = $auditManager->createAuditReader();
```

### Find entity state at a particular revision

This command also returns the state of the entity at the given revision, even if the last change
to that entity was made in a revision before the given one:

```php
$articleAudit = $auditReader->find(
    SimpleThings\EntityAudit\Tests\ArticleAudit::class,
    $id = 1,
    $rev = 10
);
```

Instances created through `AuditReader#find()` are *NOT* injected into the EntityManagers UnitOfWork,
they need to be merged into the EntityManager if it should be reattached to the persistence context
in that old version.

### Find Revision History of an audited entity

```php
$revisions = $auditReader->findRevisions(
    SimpleThings\EntityAudit\Tests\ArticleAudit::class,
    $id = 1
);
```

A revision has the following API:

```php
class Revision
{
    public function getRev();
    public function getTimestamp();
    public function getUsername();
}
```

### Find Changed Entities at a specific revision

```php
$changedEntities = $auditReader->findEntitiesChangedAtRevision(10);
```

A changed entity has the API:

```php
class ChangedEntity
{
    public function getClassName();
    public function getId();
    public function getRevisionType();
    public function getEntity();
}
```

### Find Current Revision of an audited Entity

```php
$revision = $auditReader->getCurrentRevision(
    'SimpleThings\EntityAudit\Tests\ArticleAudit',
    $id = 3
);
```

## Setting the Current Username

Each revision automatically saves the username that changes it. For this to work, the username must be resolved.

You can username callable to a specific value using the `AuditConfiguration`.

```php
$auditConfig = new \SimpleThings\EntityAudit\AuditConfiguration();
$auditConfig->setUsernameCallable(function () {
	$username = //your customer logic
    return username;
});
```

## Supported DB

* MySQL / MariaDB
* PostgesSQL
* SQLite

*We can only really support the databases if we can test them via Travis.*

## Contributing

Please before commiting, run this command `./vendor/bin/php-cs-fixer fix --verbose` to normalize the coding style.

If you already have the fixer locally you can run `php-cs-fixer fix .`.
