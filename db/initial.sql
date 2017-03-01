-- Adminer 4.2.5 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `alerts`;
CREATE TABLE `alerts` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `server_id` int(11) NOT NULL,
      `timestamp` datetime NOT NULL,
      `until` datetime DEFAULT NULL,
      `type` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `data` text NOT NULL,
      `active` int(11) NOT NULL DEFAULT '0',
      `sent` int(11) NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`),
      KEY `server_id` (`server_id`),
      CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `changelog`;
CREATE TABLE `changelog` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `server_id` int(11) NOT NULL,
      `timestamp` datetime NOT NULL,
      `component` varchar(255) NOT NULL,
      `action` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `old_value` text,
      `old_version` varchar(255) DEFAULT NULL,
      `new_value` text,
      `new_version` varchar(255) DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `server_id` (`server_id`),
      CONSTRAINT `changelog_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `packages`;
CREATE TABLE `packages` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `server_id` int(11) NOT NULL,
      `package` varchar(255) NOT NULL,
      `version` varchar(255) NOT NULL,
      `since` datetime NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `server_id_package` (`server_id`,`package`),
      CONSTRAINT `packages_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `servers`;
CREATE TABLE `servers` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `hostname` varchar(255) NOT NULL,
      `last_check` datetime NOT NULL,
      `distribution` varchar(255) NOT NULL,
      `version` varchar(255) NOT NULL,
      `kernel` varchar(255) NOT NULL,
      `ip` varchar(255) NOT NULL,
      `uptime` int(10) unsigned DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `hostname` (`hostname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- 2017-03-01 13:57:47
