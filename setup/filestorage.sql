CREATE TABLE `files` (
  `uuid` binary(16) NOT NULL COMMENT 'Binary UUID value',
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `hash` binary(32) NOT NULL COMMENT 'Hash of file content',
  `group` int(11) NOT NULL COMMENT 'Group index',
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  `linked` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'True, if there is corresponding valid records in main database',
  PRIMARY KEY (`uuid`),
  KEY `hash` (`hash`,`group`,`deleted`)
);

CREATE TABLE `attributes` (
  `uuid` binary(16) NOT NULL COMMENT 'Binary UUID value',
  `attribute` varchar(32) NOT NULL,
  `value` varchar(256) NOT NULL,
  KEY `uuid` (`uuid`)
);

DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `fspath`(b BINARY(16)) RETURNS char(43) CHARSET utf8
    DETERMINISTIC
BEGIN
  DECLARE hex CHAR(32);
  SET hex = HEX(b);
  RETURN LOWER(CONCAT('/', MID(hex, 29, 2), '/', MID(hex, 27, 2), '/', LEFT(hex, 8), '-', MID(hex, 9,  4), '-', MID(hex, 13, 4), '-', MID(hex, 17, 4), '-', RIGHT(hex, 12)));
END ;;
DELIMITER ;

DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `fsurl`(u BINARY(16), g INT(11), e VARCHAR(256)) RETURNS varchar(256) CHARSET utf8
    DETERMINISTIC
BEGIN
  RETURN CONCAT('img', LPAD(g, 2, '0'), '.wikimart.ru', fspath(u), '.', e);
END ;;
DELIMITER ;

DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `fsurlbyhash`(h BINARY(32)) RETURNS varchar(256) CHARSET utf8
    READS SQL DATA
BEGIN
  DECLARE url VARCHAR(256);
  SELECT fsurl(`files`.`uuid`, `files`.`group`, `attributes`.`value`) INTO url FROM `files` JOIN `attributes` USING (`uuid`) WHERE `hash` = h AND `attributes`.`attribute` = 'Extension' LIMIT 1;
  RETURN url;
END ;;
DELIMITER ;

DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `fsurlbyhash`(h BINARY(32))
BEGIN
  SELECT fsurl(`files`.`uuid`, `files`.`group`, `attributes`.`value`) FROM `files` JOIN `attributes` USING (`uuid`) WHERE `hash` = h AND `attributes`.`attribute` = 'Extension';
END ;;
DELIMITER ;
