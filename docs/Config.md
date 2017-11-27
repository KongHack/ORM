## Config

The config will NOT update itself to add missing values upon update. Below is the list of config options, possible values, and what they do.


### [general]

 - common: \Your\Fully\Qualified\Class\Name\Here
   - Must be the fully qualified class name of your Common class

 - user: \Your\Fully\Qualified\Class\Name\Here
   - Must be the fully qualified class name of your User class


### [options]
 - get_set_funcs: true
   - true/false - Will automatically generate default get/set functions.  Should be used if your var_visibility is not public

 - var_visibility: public
   - public/protected - Default variable visibility, should be protected if you're using getters/setters.

 - json_serialize: true
   - true/false - Will enable the jsonSerialize interface and create the function with a default array based on available fields

 - enable_backtrace: false
   - true/false - Will dump a full backtrace upon exception

 - use_defaults: true
   - true/false - Will try to use the database's default values instead of setting every variable = null

 - defaults_override_null: true
   - true/false - use_defaults must be true for this option to do anything.  It will try to set non-null defaults based on data type when the default is null and the null option is not set on the field
   
 - type_hinting: false
   - true/false - Will enable type hinting in get/set functions, ALL THE TIME
   
   
### [tables]
   - TABLE_NAME
     - overrides
       - constructor: protected *(Default constructor visibility, default is public, so only set if you need a protected constructor for things like factory implementation)*
     - type_hints
       - field_name: type *(mixed is applicable)*
     - audit_ignore *(will ignore these fields all together in the audit process)*
       - ``- field 1``
       - ``- field 2``
       - ``- field 3``
     