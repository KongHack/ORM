CREATE TABLE IF NOT EXISTS __REPLACE__ (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `primary_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL DEFAULT '0',
  `log_timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `log_before` longtext COLLATE utf8_bin NOT NULL,
  `log_after` longtext COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `primary_id` (`primary_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;