## Common Config

The audit portion of this system requires the usage of a new config block within your common's primary config.yml

An example is shown below.


```yaml
audit:
    enable: true
    connection: default
    database: db_audit
    prefix: Audit_

```

- `enable`: **REQUIRED** Use true/false to enable/disable the entire audit system
- `connection`: **REQUIRED** Connection will use your pre-defined database connection via Common to connect
- `database`: *OPTIONAL* If you are re-using a connection but would like to still use a different database, define your database name here
- `prefix`: *CONDITIONAL* A non-blank prefix is required if you are storing your audit tables in the same database as your primary tables.  This is highly unadvised

