-- Adminer 4.2.5 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `session`;
CREATE TABLE `session` (
  `id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `timestamp` datetime NOT NULL,
  `lifetime` int(11) NOT NULL DEFAULT '7200',
  `name` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value` varchar(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`,`name`)
) ENGINE=MEMORY DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `salt` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- 2017-03-02 21:20:43