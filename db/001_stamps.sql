-- Adminer 4.2.5 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `stamps`;
CREATE TABLE `stamps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stamp` varchar(255) NOT NULL,
  `server_id` int(11) NOT NULL,
  `timestamp` datetime NOT NULL,
  `alert_after` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_id_stamp` (`server_id`,`stamp`),
  CONSTRAINT `stamps_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- 2017-03-02 21:21:49