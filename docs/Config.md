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
     - audit_ignore (bool) - Completely ignore this table from auditing
     - constructor: (protected/public) - Adjust constructor visibility
     - save_hook: (string) - Path to hook method post-save
     - fields:
       - name_of_field:
         - visibility (protected/public) - Adjust setter visibility
         - type_hint (string) define a type_hint
         - audit_ignore (boolean) ignore just this field from the audit system
         - uuid_field (boolean) define fields as uuid fields that don't contain `_uuid` in the field name
         - getter_ignore (boolean) do not generate a getter if true
         - setter_ignore (boolean) do not generate a setter if true
         - required (boolean) Add this field to base exception checks if not part of a unique key
