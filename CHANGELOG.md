
# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).





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
