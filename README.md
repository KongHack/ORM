# GCWorld ORM


![Packagist](https://img.shields.io/packagist/dm/gcworld/orm.svg)
![Packagist](https://img.shields.io/packagist/dt/gcworld/orm.svg)

![Packagist PHP](https://img.shields.io/packagist/php-v/gcworld/orm.svg)
![Packagist](https://img.shields.io/packagist/v/gcworld/orm.svg)
![GitHub](https://img.shields.io/github/tag/konghack/orm.svg)


The GCWorld ORM builds extensible classes used for selecting and updating objects.

### WARNING
If you are using a version prior to 3.6, please use the convert ini to yml file in the contrib folder
to convert your GCWorld_ORM.ini to a yml file.


##### Features include

  - Fully formed classes containing all database fields.
  - Set / Get / Save functions.
  - Built in auditing
  - Audit User tracking via Common getUser() function
  - Redis smart caching
  - Read More About The Config Options [HERE](docs/Config.md)
  - Multi-Primary key support!
  - Auto-loading of common via config file
  - Insertable type single-key objects (using on duplicate key update)
  - DBInterface for keeping DirectDB systems in line and universal
  - Support for auto-generation of getters/setters
  - Custom definition of variable visibility
  - Use Default Option (with a null override thing!)


### Version
3.7.4


###### Todo
- Upgrade audit system to function in the DirectDBMultiClass
- Upgrade audit system to handle non-integer primary keys
- Add more docblocks to generated scripts


### Additional Information

* [GCWorld Public Gitlab](https://gitlab.konghack.com/groups/GCWorld)
