# GCWorld ORM


![Packagist](https://img.shields.io/packagist/dm/gcworld/orm.svg)
![Packagist](https://img.shields.io/packagist/dt/gcworld/orm.svg)

![Packagist PHP](https://img.shields.io/packagist/php-v/gcworld/orm.svg)
![Packagist](https://img.shields.io/packagist/v/gcworld/orm.svg)
![GitHub](https://img.shields.io/github/tag/konghack/orm.svg)


The GCWorld ORM builds extensible classes used for selecting and updating objects.

##### Features include

  - Fully formed classes containing all database fields.
  - Set / Get / Save functions.
  - Built in auditing
  - Audit User tracking via Common getUser() function
  - Redis smart caching
  - Read More About The Config Options [HERE](docs/Config.md)
  - Auto-loading of common via config file
  - Insertable type single-key objects (using on duplicate key update)
  - DBInterface for keeping DirectDB systems in line and universal
  - Support for auto-generation of getters/setters
  - Custom definition of variable visibility
  - Use Default Option (with a null override thing!)


### Version
6.4.7


###### Todo
- Upgrade the audit system to handle BINARY(16) UUIDs in place of member_id BIGINT(20) on config option
- Add more docblocks to generated scripts


### Additional Information
