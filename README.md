# GCWorld ORM

The GCWorld ORM builds extensible classes used for selecting and updating objects.

##### Features include

  - Fully formed classes containing all database fields.
  - Set / Get / Save functions.
  - Built in auditing
  - Audit User tracking via Common getUser() function
  - Redis smart caching
  - Read More About The Config Options [HERE](docs/Config.md)


##### New Features in V2

 - Multi-Primary key support!
 - Auto-loading of common via config file
 - Insertable type single-key objects (using on duplicate key update)
 - DBInterface for keeping DirectDB systems in line and universal
 - Support for auto-generation of getters/setters
 - Custom definition of variable visibility
 - Use Default Option (with a null override thing!)


### Version
3.0.0


###### Todo
- Upgrade audit system to function in the DirectDBMultiClass
- Upgrade audit system to handle non-integer primary keys
- Add more docblocks to generated scripts


### Additional Information

* [GCWorld Public Gitlab](https://gitlab.konghack.com/groups/GCWorld)
