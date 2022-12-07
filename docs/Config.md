## Config

The config will NOT update itself to add missing values upon update. Below is the list of config options, possible values, and what they do.


### [general]

 - common: \Your\Fully\Qualified\Class\Name\Here
   - Must be the fully qualified class name of your Common class

 - user: \Your\Fully\Qualified\Class\Name\Here
   - Must be the fully qualified class name of your User class

 - audit: boolean
   - Enable / Disable all auditing features

 - audit_handler: \Your\Fully\Qualified\Class\Name\Here
   - (OPTIONAL).  Leave blank to use the default audit handler.  Class provided must implement `AuditInterface`

 - trust_cache: boolean
   - If true, the ORM config system will stop processing immediately after loading the config cache php file.
     This should speed up config load times, as all additional config checks, table.yml loads, sorts, etc. will not execute



### [table_dir]
 - If this value is set, the ORM will look for a directory of Table_Name.yml files relative to your config file location.  
   Files will be loaded in from this location, then the table_dir value will be removed from the resulting config array.



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
     - audit_ignore (bool) - Completely ignore this table from auditing
     - audit_handler (string) - Custom audit handler per table
     - constructor: (protected/public) - Adjust constructor visibility
     - cache_ttl (int) - (-1) to disable caching, 0 to allow permanent caching, any other positive integer will define a timeout on cached data
     - save_hook: (string) - Path to hook method post-save
     - cache_time: (int) - cache time in seconds.  0 = unlimited, -1 = disabled
     - fields:
       - name_of_field:
         - visibility (protected/public) - Adjust setter visibility
         - type_hint (string) define a type_hint. This can be a full class name, enum, etc
         - audit_ignore (boolean) ignore just this field from the audit system
         - uuid_field (boolean) define fields as uuid fields that don't contain `_uuid` in the field name
         - getter_ignore (boolean) do not generate a getter if true
         - setter_ignore (boolean) do not generate a setter if true
         - required (boolean) Add this field to base exception checks if not part of a unique key
