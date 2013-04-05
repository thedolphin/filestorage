CREATE TABLE `files` (
  `uuid` binary(16) NOT NULL COMMENT 'Binary UUID value',
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `hash` binary(32) NOT NULL COMMENT 'Hash of file content',
  `group` int(11) NOT NULL COMMENT 'Group index',
  `deleted` boolean NOT NULL DEFAULT FALSE,
  `linked` boolean NOT NULL DEFAULT FALSE COMMENT 'True, if there is corresponding valid records in main database',
  PRIMARY KEY (`uuid`),
  KEY (`hash`, `group`, `deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `attributes` (
  `uuid` binary(16) NOT NULL COMMENT 'Binary UUID value',
  `attribute` varchar(32) NOT NULL,
  `value` varchar(256) NOT NULL,
  KEY (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
