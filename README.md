# GCWorld ORM

![Packagist](https://img.shields.io/packagist/dm/gcworld/orm.svg)
![Packagist](https://img.shields.io/packagist/dt/gcworld/orm.svg)

![Packagist PHP](https://img.shields.io/packagist/php-v/gcworld/orm.svg)
![Packagist](https://img.shields.io/packagist/v/gcworld/orm.svg)
![GitHub](https://img.shields.io/github/tag/konghack/orm.svg)

GCWorld ORM generates database-backed PHP model classes from a live schema.
Generated models provide field metadata, getters and setters, persistence,
factory methods for unique keys, optional Redis caching, validation helpers,
and configurable change auditing.

The package supplies generation and runtime infrastructure. The consuming
application remains responsible for database connections, its Common and user
implementations, schema migrations, configuration, and when generation runs.

### Version
6.4.34

## Requirements

- PHP 8.4 or newer
- Composer 2
- The JSON and PDO PHP extensions
- A `GCWorld\Interfaces\CommonInterface` implementation that provides the
  configured database and optional cache connections
- A database whose schema can be inspected during model generation

## Installation

Install the package with Composer:

```console
composer require gcworld/orm
```

The Composer installer creates `config/GCWorld_ORM.yml` in the consuming
project when that file does not already exist. Start with the generated example
and replace its Common and user class names before loading or generating models.

## Configuration

The main configuration controls global generation behavior and per-table or
per-field overrides:

```yaml
version: 5
general:
    common: '\App\Common'
    user: '\App\CurrentUser'
    audit: true
    trust_cache: false
    database_name: default
    sub_namespace: ''
options:
    get_set_funcs: true
    var_visibility: protected
    json_serialize: true
    use_defaults: true
    defaults_override_null: true
    type_hinting: true
    cache_after_purge: false
tables:
    Member:
        constructor: public
        cache_ttl: 60
        fields:
            member_uuid:
                uuid_field: true
                visibility: protected
                type_hint: string
                required: true
```

Table definitions may also be split into individual YAML files by setting
`table_dir` to a directory relative to the main config. See
[the complete configuration reference](docs/Config.md) for all global, table,
field, description, auditing, and caching options.

## Generating models

Construct `Core` with the consuming application's Common implementation, then
generate each table that should have a model:

```php
use GCWorld\ORM\Core;

$orm = new Core(__NAMESPACE__, $common);

$query = $common->getDatabase()->query('SHOW TABLES');
while ($table = $query->fetchColumn()) {
    $orm->generate($table);
}
```

Generated abstract classes are written beneath `src/Generated/` in this package
and use the `GCWorld\ORM\Generated` namespace. If `general.sub_namespace` is
set, it is added beneath both the directory and namespace. The generated tree
is ignored by Git and should be rebuilt from the schema and configuration
during the application's setup or deployment process.

The database user used for generation must be able to inspect columns and
indexes. Generation does not replace schema migrations and should run only
against a schema controlled by the application.

## Using generated models

Application models extend their generated base and expose the methods enabled
by the configuration. For a `Member` table, a minimal model can be loaded and
changed as follows:

```php
use GCWorld\ORM\Generated\Member as GeneratedMember;

final class Member extends GeneratedMember
{
}

$member = new Member(42);
$member->setEmail('person@example.com');
$member->save();
```

Exact class and factory names follow table, primary-key, and unique-index names
from the schema. Generated classes also expose change tracking through
`_hasChanged()`, `_getChanged()`, and `_getLastChanged()`.

`DirectDBClass` is available when an application needs public generic `get()`,
`set()`, `getArray()`, and `setArray()` access in addition to generated field
methods.

## Caching and auditing

Caching is enabled per table through `cache_ttl` and uses the cache returned by
the configured Common implementation. A value of `-1` disables caching, `0`
keeps entries without an ORM expiration, and a positive value sets the lifetime
in seconds. `cache_after_purge` controls whether a saved object is immediately
cached again.

Auditing can be disabled globally, per table, or per field. The default audit
handler records before-and-after values using the application's audit database
configuration. Applications may provide a custom class implementing
`AuditInterface`; sensitive fields should use `audit_ignore` when their values
must not be retained in change logs.

## Local development

The supported local environment uses the public KongHack PHP 8.4 image:

```console
./dc up -d
./dc exec php composer install
./dc exec php composer check
./dc down
```

The committed Compose configuration mounts only this repository. It does not
expose host SSH keys or Composer credentials. Developers who require private
Composer authentication can copy `docker-compose.override.yml.example` to the
ignored `docker-compose.override.yml`; that override exposes credentials to
container processes and should only be enabled when needed.

Individual quality commands are also available:

```console
./dc exec php composer lint
./dc exec php composer phpstan
./dc exec php composer phpcs
./dc exec php composer test
```

PHPStan is enforced at level 6. PHPCS enforces PSR-12 errors while retaining
the three underscore-prefixed change-tracking methods as a legacy public API.
GitHub Actions runs the complete suite on PHP 8.4 and 8.5.

## Releases

Releases use bare semantic-version tags such as `6.4.34`. Before tagging a
release:

1. Add release notes under the matching version heading in `CHANGELOG.md`.
2. Update `VERSION` and the value immediately below `### Version` in this file.
3. Push the release commit and matching tag.

GitHub Actions validates the release metadata and complete PHP quality matrix
before creating a GitHub Release from `CHANGELOG.md`. Release tags must not be
moved or reused.

## License

GCWorld ORM is open-source software licensed under the MIT License.
