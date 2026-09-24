<p align="center">
	<img src="docs/logo.png" alt="doctopus" width="320">
</p>

<p align="center"><strong>One set of Doctrine migrations, many databases.</strong></p>

doctopus is a small Symfony bundle for applications that let the person deploying them
choose the database: **MySQL**, **MariaDB** or **SQLite**. The deployer picks one with `DATABASE_URL`
and the same migrations run on all three.

It does not hold your migrations. It provides the tooling so that every Symfony/Doctrine
app writes them portably:

| | |
|---|---|
| `PortableMigration` | Base class: checks the platform, has platform helpers, `addPlatformSql()` and sensible transaction handling |
| `SupportedPlatform` | Enum `MYSQL` / `MARIADB` / `SQLITE` for explicit, exhaustive `match`es |
| `doctopus:migrations:lint` | Finds raw DDL and MySQL-/SQLite-only SQL in your migrations |
| `doctopus:migrations:generate` | Generates a blank migration that extends `PortableMigration` |
| `doctopus:database:check` | Checks the configured database against the platforms and versions you support |

## Why

Doctrine ORM mappings are portable. The Doctrine **Schema API** is largely portable.
**Generated migration SQL is not.** `doctrine:migrations:diff` turns the abstract schema
difference into SQL for whichever database you happen to be connected to:

```php
$this->addSql('ALTER TABLE customer ADD active TINYINT(1) DEFAULT 1 NOT NULL'); // MySQL only
```

Doctrine Migrations can also do this: it hands `up()` a target `Schema`. You modify it, and
Doctrine diffs it against the current database and renders the SQL **for the active platform at
runtime**:

```php
$schema->getTable('customer')->addColumn('active', Types::BOOLEAN, [ 'default' => true ]);
```

That is one migration for three databases. doctopus makes it the default and catches slips.

## Installation

```bash
composer require pmaerksch/doctopus
```

Without Symfony Flex, register the bundle in `config/bundles.php`:

```php
return [
	// ...
	Doctopus\DoctopusBundle::class => [ 'all' => true ],
];
```

Requirements: PHP 8.2+, Symfony 6.4 / 7.x / 8.x, Doctrine DBAL 3.8+ / 4.x, Doctrine Migrations 3.7+,
DoctrineBundle 2.11+ / 3.x, DoctrineMigrationsBundle 3.3+ / 4.x. (Symfony 8 and the 3.x / 4.x bundles need PHP 8.4.)

## Writing portable migrations

```bash
php bin/console doctopus:migrations:generate
```

```php
use Doctopus\Migration\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

final class Version20260924230000 extends PortableMigration
{
	public function up(Schema $schema): void
	{
		$customer = $schema->createTable('customer');
		$customer->addColumn('id', Types::INTEGER, [ 'autoincrement' => true ]);
		$customer->addColumn('email', Types::STRING, [ 'length' => 180 ]);
		$customer->setPrimaryKey([ 'id' ]);
		$customer->addUniqueIndex([ 'email' ]);
	}

	public function down(Schema $schema): void
	{
		$schema->dropTable('customer');
	}
}
```

Most everyday changes are one call each:
`$schema->createTable()`, `dropTable()`, `$table->addColumn()`, `modifyColumn()`, `dropColumn()`,
`addIndex()`, `addUniqueIndex()`, `addForeignKeyConstraint()`.

### Platform helpers

Sometimes the difference cannot be hidden. When it can't, make it explicit:

```php
if ( $this->isSqlite() )
{
	// unavoidable SQLite special handling
}

$this->isMysql();
$this->isMariaDb();
$this->isMysqlFamily(); // MySQL or MariaDB

match ( $this->databasePlatform() )
{
	SupportedPlatform::MYSQL   => ...,
	SupportedPlatform::MARIADB => ...,
	SupportedPlatform::SQLITE  => ...,
};
```

### `addPlatformSql()`

Use it for the rare raw SQL that differs per platform:

```php
$this->addPlatformSql(
	mysql:  "UPDATE customer SET label = CONCAT(name, ' <', email, '>')",
	sqlite: "UPDATE customer SET label = name || ' <' || email || '>'",
);
```

- `mariadb:` falls back to `mysql:` when omitted.
- `null` for the current platform means "not supported" and the migration **aborts**.
- `''` means "nothing to do on this platform".

The linter doesn't check SQL in `addPlatformSql()`, because it is platform-specific on purpose.

### Schema changes and data changes: separate migrations

Doctrine executes the `addSql()` statements of a migration **before** the SQL generated from
`$schema` changes. So data SQL cannot use a column that the same migration adds through
`$schema`. Put data changes in a separate migration that comes after the schema migration.

### Platform check and transactions

`PortableMigration::preUp()` / `preDown()` check the database against your configured
platforms and minimum versions. With `strict: true` (the default), a migration on an unsupported
database aborts before it changes anything. If you override `preUp()` / `preDown()`, call the parent.

`isTransactional()` returns `false` on MySQL/MariaDB and `true` on SQLite. MySQL and MariaDB
commit implicitly after each DDL statement, so a wrapping transaction only causes
*"There is no active transaction"* errors. SQLite has transactional DDL and keeps all-or-nothing
migrations.

## Linting migrations

```bash
php bin/console doctopus:migrations:lint            # configured migration directories
php bin/console doctopus:migrations:lint migrations/Version20260917091213.php
```

```text
 ✓ Version20260901080000
 ✗ Version20260910152200
     error line 21: Migration extends AbstractMigration instead of Doctopus\Migration\PortableMigration.
     error line 25: addSql(): raw DDL detected; modify $schema instead or use addPlatformSql().
       ALTER TABLE customer ADD active TINYINT(1) DEFAULT 1 NOT NULL
 ✗ Version20260917091213
     error line 24: addSql(): platform-specific SQL detected: JSON_EXTRACT()

 [ERROR] 3 portability error(s), 0 warning(s) in 3 migration(s).
```

The linter looks at `addSql()`, `$this->connection->executeStatement()` and `executeQuery()`.
It flags:

- migrations that extend `AbstractMigration` instead of `PortableMigration`
- raw DDL (`CREATE` / `ALTER` / `DROP` / `RENAME TABLE`, indexes, views, triggers, `TRUNCATE`)
- dialect-specific SQL: `AUTO_INCREMENT`, `ENGINE=`, `COLLATE`, `CHARACTER SET`, `UNSIGNED`, `ENUM(`,
  `ON DUPLICATE KEY`, `INSERT IGNORE`, `INSERT OR REPLACE`, `REPLACE INTO`, `MATCH … AGAINST`,
  `JSON_EXTRACT`, `FIND_IN_SET`, `GROUP_CONCAT`, `DATE_FORMAT`, `STR_TO_DATE`, `DATE_ADD/SUB`,
  `INTERVAL`, `NOW()`, `CURDATE()`, `UNIX_TIMESTAMP`, `LAST_INSERT_ID`, `IF()`, `strftime()`,
  `datetime()`, `PRAGMA`, …
- SQL built from variables, which it cannot check (a warning)

It is a heuristic, not a SQL parser. Its job is to catch accidental coupling. It exits non-zero
on errors, so you can run it in CI or a pre-commit hook. Add `--fail-on-warning` to make warnings
fail too.

Put `// @doctopus-ignore` on the offending line or the line above it to allow a deliberate exception.

### Workflow with `doctrine:migrations:diff`

`diff` is still useful as a reference:

```text
entity change
    → doctrine:migrations:diff          (shows what changed, in your current DB's SQL)
    → rewrite the DDL with the Schema API
    → doctopus:migrations:lint
    → run the tests against SQLite, MySQL and MariaDB
```

To make `diff` and `generate` produce `PortableMigration` classes directly, use the doctopus
template. The lint step then flags the generated `addSql()` lines for you to convert:

```yaml
# config/packages/doctrine_migrations.yaml
doctrine_migrations:
    custom_template: '%kernel.project_dir%/vendor/pmaerksch/doctopus/templates/migration.php.tpl'
```

## Checking the database

```bash
php bin/console doctopus:database:check
```

```text
Database compatibility
======================

 ---------------- ------------------------------------------------
  Platform         MariaDB
  Version          11.4.5-MariaDB-ubu2404
  DBAL platform    Doctrine\DBAL\Platforms\MariaDB110700Platform
  Supported        MySQL >= 8.0, MariaDB >= 10.6, SQLite >= 3.35
 ---------------- ------------------------------------------------

 [OK] Application database configuration is compatible.
```

It also warns when the server is MariaDB but the `serverVersion` in `DATABASE_URL` makes DBAL
generate MySQL SQL, or the other way round. Run it in an installer, a health check or a deployment script.

## Configuration

Everything is optional. These are the defaults:

```yaml
# config/packages/doctopus.yaml
doctopus:
    # The databases this application supports.
    platforms:
        mysql:   { min_version: '8.0' }
        mariadb: { min_version: '10.6' }
        sqlite:  { min_version: '3.35' }

    # Abort migrations on unsupported databases (true) or only warn (false).
    strict: true

    # Template for doctopus:migrations:generate (same placeholders as doctrine_migrations.custom_template).
    migration_template: ~

    lint:
        # Only lint migrations from this version on. Handy when adopting doctopus in an existing project.
        since: ~              # e.g. 'Version20260101000000'
        # Migration class names to skip.
        exclude: []
```

Shorter forms work too:

```yaml
doctopus:
    platforms: [ mariadb, sqlite ]          # default minimum versions
```

```yaml
doctopus:
    platforms:
        mariadb: '10.11'
        sqlite: ~                           # default minimum version
```

## Testing against all databases

"We used Doctrine, so it should work" doesn't count as support. Support means every migration
and the whole application has actually run on every database you support. Run your test suite once
per database, for example in GitLab CI:

```yaml
test:sqlite:
    variables:
        DATABASE_URL: "sqlite:///%kernel.project_dir%/var/test.db"

test:mysql:
    services: [ mysql:8.4 ]
    variables:
        DATABASE_URL: "mysql://root:root@mysql:3306/app?serverVersion=8.4"

test:mariadb:
    services: [ mariadb:11.4 ]
    variables:
        DATABASE_URL: "mysql://root:root@mariadb:3306/app?serverVersion=11.4.0-MariaDB"
```

This package's own [`.gitlab-ci.yml`](.gitlab-ci.yml) does exactly that.

## Tips for portable applications

- Keep **entities, repositories and migrations in the application**. doctopus only supplies the tooling.
- Prefer **UUIDs** (`symfony/uid`, `Uuid::v7()`) so ID generation doesn't depend on auto-increment behaviour.
- Use **PHP backed enums** with `enumType:` instead of `ENUM(...)` columns. They are stored as plain strings.
- Stick to DQL / QueryBuilder. When you need native SQL, keep it to the common subset.

## Development

```bash
composer install
vendor/bin/phpunit                                                             # SQLite in memory
DATABASE_URL="mysql://root:root@127.0.0.1:3306/doctopus?serverVersion=8.4" vendor/bin/phpunit
```

## License

MIT
