
# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).





## [3.7.4](https://github.com/KongHack/ORM/releases/tag/3.7.4)
 - @GameCharmer Add patch to handle audit_pk_set field



## [3.7.3](https://github.com/KongHack/ORM/releases/tag/3.7.3)
 - @GameCharmer patching up issues with the builder script



## [3.7.2](https://github.com/KongHack/ORM/releases/tag/3.7.2)
 - @GameCharmer eliminate audit master class



## [3.7.1](https://github.com/KongHack/ORM/releases/tag/3.7.1)
 - @GameCharmer prevent recursion, update config pointers



## [3.7.0](https://github.com/KongHack/ORM/releases/tag/3.7.0)
 - @GameCharmer ***WARNING*** Experimental branch for audit builder system


## [3.6.5](https://github.com/KongHack/ORM/releases/tag/3.6.5)
 - @GameCharmer Strict `\PDO::FETCH_ASSOC` enforcement for Audits


## [3.6.4](https://github.com/KongHack/ORM/releases/tag/3.6.4)
 - @GameCharmer Change config pathing to relative
 - symfony/yaml updated from v3.3.13 to v3.3.18
   See changes: https://github.com/symfony/yaml/compare/v3.3.13...v3.3.18
   Release notes: https://github.com/symfony/yaml/releases/tag/v3.3.18


## [3.6.3](https://github.com/KongHack/ORM/releases/tag/3.6.3)
 - @GameCharmer Add support for protecting generated setter functions


## [3.6.2.1](https://github.com/KongHack/ORM/releases/tag/3.6.2.1)
 - @GameCharmer Fix for real this time
 
 
## [3.6.2](https://github.com/KongHack/ORM/releases/tag/3.6.2)
 - @GameCharmer Fix Composer Installer


## [3.6.1](https://github.com/KongHack/ORM/releases/tag/3.6.1)
 **BROKEN, DO NOT USE**
 - @GameCharmer remove inline ini handling, move to contrib file


## [3.6.0](https://github.com/KongHack/ORM/releases/tag/3.6.0)
 **BROKEN, DO NOT USE**
 - @GameCharmer Convert to YML


## 3.5.0.2
 - @GameCharmer audit master fix.


## 3.5.0.1
 - @GameCharmer quick fix to master.sql.  If you've already used 3.5.0, delete the table it creates and run it again
 
 
## 3.5.0 
 - @GameCharmer New Audit Master system for better version control of tables


## 3.4.2 
 - @GameCharmer Update Database to fix backtick issue


## 3.4.1 
 - @GameCharmer Resolve Backtick Issue on new table create


## 3.4.0
 - @GameCharmer COMMON CONFIG HAS CHANGED!

## 3.3.2
 - @GameCharmer Audit Static Member ID Override System
 

## 3.3.1
 - @GameCharmer added $_canCacheAfterPurge to DirectSingle to auto-reload caches **!!WARNING!!** Defaults to true
 

## 3.3.0
 - @GameCharmer New option ``type_hinting``
 - @GameCharmer New type hinting override system (see docs)


## 3.2.0
 - @GameCharmer bump to PHP 7.1 for the new PEX stuff


## 3.1.0
 - @GameCharmer Audit Ignore system


## 3.0.1.1
 - @GameCharmer bug fix, switch to this one instead of 3.0.1


## 3.0.1
 - @GameCharmer code cleaning, phpcs & phpstan
 - @GameCharmer WARNING: parameter order changed in storeLog, so if you're using it outside of stock, move member ID to the end


## 3.0.0
 - @GameCharmer WARNING, WILL TRASH YOUR CONFIG FILE
 - @GameCharmer new config structure
 - @GameCharmer override feature for class constructors


## 2.8.1.4
 - @GameCharmer have DirectSingle compensate for 0 as the primary id


## 2.8.1.3
 - @GameCharmer compensate for "CURRENT_TIMESTAMP" as a default value


## 2.8.1.2
 - @GameCharmer cleanup echos and spacing


## 2.8.1.1
 - @GameCharmer strip out data type parameters when type checking (ex. BIGINT(20) UNSIGNED becomes just BIGINT)


## 2.8.1
 - @GameCharmer defaults_override_null Option
 - @GameCharmer added a config documentation file & updated the readme


## 2.8.0
 - @GameCharmer Use Default Option
 - @GameCharmer Updated Composer


## 2.7.4
 - @GameCharmer Updated Composer


## 2.7.3
 - @GameCharmer added the _hasChanged function to both abstract classes


## 2.7.2
 - @GameCharmer improve the auditing system for DirectSingle


## 2.7.1
 - @GameCharmer Prevent (something got cut off?)


## 2.5.5
 - @GameCharmer **New Config Value:** get_set_funcs (Toggle auto-generation of getters/setters)
 - @GameCharmer **New Config Value:** var_visibility (Define public/protected state of generated properties)


## 2.4.4
 - @GameCharmer Implement Doc Blocks on DBInfo array
 - @GameCharmer Implement Doc Blocks on generated properties


## 2.4.3
 - @GameCharmer Start of changelog tracking
 - @GameCharmer Added a UserLoader class **(and config requirement)** to streamline loading singleton user classes.
 - @GameCharmer NOTE: user config option will not be fully required until 3.0
