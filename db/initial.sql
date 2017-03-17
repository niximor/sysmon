-- Adminer 4.2.5 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `alerts`;
CREATE TABLE `alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `stamp_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL,
  `until` datetime DEFAULT NULL,
  `type` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `data` text NOT NULL,
  `active` int(11) NOT NULL DEFAULT '0',
  `sent` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  KEY `check_id` (`check_id`),
  KEY `stamp_id` (`stamp_id`),
  CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`check_id`) REFERENCES `checks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_ibfk_3` FOREIGN KEY (`stamp_id`) REFERENCES `stamps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `alert_templates`;
CREATE TABLE `alert_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `template` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alert_type` (`alert_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `alert_templates` (`id`, `alert_type`, `template`) VALUES
(3,   'dead',     '{% if alert.active %}\r\nHost is dead since {{ alert.timestamp|datetime }} (down for {{ (alert.until|timestamp - alert.timestamp|timestamp)|duration }}).\r\n{% else %}\r\nHost was dead since {{ alert.timestamp|datetime }} (was down for {{ (alert.until|timestamp - alert.timestamp|timestamp)|duration }}).\r\n{% endif %}'),
(4,   'rebooted', 'Host has been rebooted. Was up for {{ alert.data.uptime|duration }}.'),
(5,   'stamp',    'Stamp check has failed for stamp {{ alert.data.stamp }}. Last check was on {{ alert.data.last_run|datetime }}, which is {{ (alert.until|timestamp - alert.data.last_run|timestamp)|duration }} ago.'),
(6,   'check_unavailable',    'Check <code>{{ alert.data.type }}</code> is unavailable on host {{ alert.hostname }}.'),
(7,   'check_failed',   'Check has failed with following error:\r\n<code>{{ alert.data.exception|nl2br }}</code>'),
(8,   'ping_failed',    '{% if alert.data.reason == \'no_packet_received\' %}\r\nPing failed. No packet returned.\r\n{% elseif alert.data.reason == \'command_failed\' %}\r\nPing failed: <code>{{ alert.data.output|nl2br }}</code>.\r\n{% else %}\r\nPing failed for unknown reason.\r\n{% endif %}'),
(9,   'ping_packetloss',      'Ping encountered a packetloss of {{ ((alert.data.sent - alert.data.received) * 100 / alert.data.sent)|round }}%. Sent {{ alert.data.sent }} packets, received {{ alert.data.received }} packets.'),
(10,  'http_invalid_status',  'HTTP status differs from required. Required: {{ alert.data.required_status }}, received: {{ alert.data.actual_status }}.'),
(11,  'http_missing_keyword', 'Keyword <code>{{ alert.data.keyword }}</code> is missing in server response.'),
(12,  'bad_param',      'Check received bad parameter value for parameter {{ alert.data.name }}. Expected {{ alert.data.expected }}.'),
(13,  'port_check_failed',    '{{ alert.check }} has failed with errno {{ alert.data.errno }}: {{ alert.data.strerror }}.'),
(14,  'low_disk_space', 'Host {{ alert.hostname }} has low disk space on {{ alert.data.mountpoint }}. Only {{ alert.data.free_percent|round }}% of free space remains.');

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


DROP TABLE IF EXISTS `checks`;
CREATE TABLE `checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `interval` int(11) NOT NULL,
  `last_check` datetime DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `type_id` int(11) NOT NULL,
  `params` text NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `group_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  KEY `type` (`type_id`),
  KEY `group` (`group_id`),
  CONSTRAINT `checks_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `checks_ibfk_2` FOREIGN KEY (`type_id`) REFERENCES `check_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `checks_ibfk_3` FOREIGN KEY (`group_id`) REFERENCES `check_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `check_charts`;
CREATE TABLE `check_charts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `check_type_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `check_type_id_name` (`check_type_id`,`name`),
  CONSTRAINT `check_charts_ibfk_1` FOREIGN KEY (`check_type_id`) REFERENCES `check_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `check_charts` (`id`, `check_type_id`, `name`) VALUES
(2,   1,    'Average response time'),
(3,   2,    'Response status'),
(4,   2,    'Response time'),
(5,   4,    'Disk free space');

DROP TABLE IF EXISTS `check_chart_readings`;
CREATE TABLE `check_chart_readings` (
  `chart_id` int(11) NOT NULL,
  `reading_id` int(11) NOT NULL,
  PRIMARY KEY (`chart_id`,`reading_id`),
  KEY `reading_id` (`reading_id`),
  CONSTRAINT `check_chart_readings_ibfk_1` FOREIGN KEY (`chart_id`) REFERENCES `check_charts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `check_chart_readings_ibfk_2` FOREIGN KEY (`reading_id`) REFERENCES `readings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `check_chart_readings` (`chart_id`, `reading_id`) VALUES
(3,   1),
(4,   2),
(2,   3),
(5,   4),
(5,   5);

DROP TABLE IF EXISTS `check_groups`;
CREATE TABLE `check_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `check_types`;
CREATE TABLE `check_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `check_types` (`id`, `identifier`, `name`) VALUES
(1,   'ping',     'Ping'),
(2,   'http',     'HTTP'),
(3,   'port-open',      'Port is open'),
(4,   'df', 'Disk free space'),
(8,   'if_traffic',     'Interface traffic');

DROP TABLE IF EXISTS `check_type_options`;
CREATE TABLE `check_type_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `check_type_id` int(11) NOT NULL,
  `option_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `check_type_id_option_name` (`check_type_id`,`option_name`),
  CONSTRAINT `check_type_options_ibfk_1` FOREIGN KEY (`check_type_id`) REFERENCES `check_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `check_type_options` (`id`, `check_type_id`, `option_name`) VALUES
(8,   1,    'ADDRESS'),
(9,   1,    'COUNT'),
(10,  1,    'IPV6'),
(4,   2,    'ADDRESS'),
(7,   2,    'KEYWORD'),
(6,   2,    'STATUS'),
(5,   2,    'VALIDATE_SSL'),
(11,  3,    'ADDRESS'),
(13,  3,    'IPV6'),
(12,  3,    'PORT'),
(14,  3,    'TIMEOUT'),
(3,   4,    'ALERT_THRESHOLD'),
(2,   4,    'MOUNTPOINT'),
(1,   8,    'INTERFACE');

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


DROP TABLE IF EXISTS `readings`;
CREATE TABLE `readings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `check_type_id` int(11) NOT NULL DEFAULT '1',
  `data_type` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'raw',
  `precision` int(11) NOT NULL DEFAULT '2',
  PRIMARY KEY (`id`),
  UNIQUE KEY `check_type_id_name` (`check_type_id`,`name`),
  CONSTRAINT `readings_ibfk_1` FOREIGN KEY (`check_type_id`) REFERENCES `check_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `readings` (`id`, `name`, `check_type_id`, `data_type`, `precision`) VALUES
(1,   'status',   2,    'raw',      0),
(2,   'time',     2,    'time',     2),
(3,   'rtt',      1,    'time',     2),
(4,   'free_bytes',     4,    'bytes',    0),
(5,   'size_bytes',     4,    'bytes',    0),
(6,   'free_percent',   4,    'percent',  0),
(7,   'rx', 8,    'bytes',    0),
(8,   'tx', 8,    'bytes',    0);

DROP TABLE IF EXISTS `readings_daily`;
CREATE TABLE `readings_daily` (
  `check_id` int(11) NOT NULL,
  `reading_id` int(11) NOT NULL,
  `datetime` datetime NOT NULL,
  `value` double NOT NULL,
  PRIMARY KEY (`check_id`,`reading_id`,`datetime`),
  KEY `reading_id` (`reading_id`),
  KEY `check_id_datetime` (`check_id`,`datetime`),
  CONSTRAINT `readings_daily_ibfk_1` FOREIGN KEY (`check_id`) REFERENCES `checks` (`id`),
  CONSTRAINT `readings_daily_ibfk_2` FOREIGN KEY (`reading_id`) REFERENCES `readings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `readings_monthly`;
CREATE TABLE `readings_monthly` (
  `check_id` int(11) NOT NULL,
  `reading_id` int(11) NOT NULL,
  `datetime` datetime NOT NULL,
  `value` double NOT NULL,
  PRIMARY KEY (`check_id`,`reading_id`,`datetime`),
  KEY `reading_id` (`reading_id`),
  KEY `check_id_datetime` (`check_id`,`datetime`),
  CONSTRAINT `readings_monthly_ibfk_1` FOREIGN KEY (`check_id`) REFERENCES `checks` (`id`),
  CONSTRAINT `readings_monthly_ibfk_2` FOREIGN KEY (`reading_id`) REFERENCES `readings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `readings_weekly`;
CREATE TABLE `readings_weekly` (
  `check_id` int(11) NOT NULL,
  `reading_id` int(11) NOT NULL,
  `datetime` datetime NOT NULL,
  `value` double NOT NULL,
  PRIMARY KEY (`check_id`,`reading_id`,`datetime`),
  KEY `reading_id` (`reading_id`),
  KEY `check_id_datetime` (`check_id`,`datetime`),
  CONSTRAINT `readings_weekly_ibfk_1` FOREIGN KEY (`check_id`) REFERENCES `checks` (`id`),
  CONSTRAINT `readings_weekly_ibfk_2` FOREIGN KEY (`reading_id`) REFERENCES `readings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `readings_yearly`;
CREATE TABLE `readings_yearly` (
  `check_id` int(11) NOT NULL,
  `reading_id` int(11) NOT NULL,
  `datetime` datetime NOT NULL,
  `value` double NOT NULL,
  PRIMARY KEY (`check_id`,`reading_id`,`datetime`),
  KEY `reading_id` (`reading_id`),
  KEY `check_id_datetime` (`check_id`,`datetime`),
  CONSTRAINT `readings_yearly_ibfk_1` FOREIGN KEY (`check_id`) REFERENCES `checks` (`id`),
  CONSTRAINT `readings_yearly_ibfk_2` FOREIGN KEY (`reading_id`) REFERENCES `readings` (`id`) ON DELETE CASCADE
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
  `virt_role` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `virt_host_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hostname` (`hostname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `session`;
CREATE TABLE `session` (
  `id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `timestamp` datetime NOT NULL,
  `lifetime` int(11) DEFAULT NULL,
  `name` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value` varchar(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`,`name`)
) ENGINE=MEMORY DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `stamps`;
CREATE TABLE `stamps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stamp` varchar(255) NOT NULL,
  `server_id` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL,
  `alert_after` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_id_stamp` (`server_id`,`stamp`),
  CONSTRAINT `stamps_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `salt` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- 2017-03-17 06:44:33