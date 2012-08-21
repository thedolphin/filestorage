CREATE TABLE `files` (
  `uuid` binary(16) NOT NULL COMMENT 'Converted UUID value',
  `uuid-text` char(36) NOT NULL COMMENT 'Textual UUID value',
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `hash` binary(16) NOT NULL COMMENT 'MD5 hash of file content',
  `size` int(11) NOT NULL COMMENT 'Size of file',
  `ext` varchar(16) NOT NULL COMMENT 'File extension',
  `group` int(11) NOT NULL COMMENT 'Group index',
  `deleted` boolean NOT NULL DEFAULT FALSE COMMENT 'We will never delete records, just mark as deleted',
  `linked` boolean NOT NULL DEFAULT FALSE COMMENT 'True, if there is corresponding valid records in main database',
  PRIMARY KEY (`uuid`),
  KEY (`uuid`, `hash`, `size`, `group`, `deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `attributes` (
  `uuid` binary(16) NOT NULL,
  `attribute` varchar(32) NOT NULL,
  `value` varchar(256) NOT NULL,
  KEY (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
