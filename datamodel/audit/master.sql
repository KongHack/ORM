CREATE TABLE __REPLACE__ (
  `audit_id` SERIAL NOT NULL ,
  `audit_schema` VARCHAR(64) NOT NULL ,
  `audit_table` VARCHAR(64) NOT NULL ,
  `audit_version` INT(11) NOT NULL ,
  `audit_datetime_created` DATETIME NOT NULL ,
  `audit_datetime_updated` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`audit_id`)
) ENGINE = InnoDB;

