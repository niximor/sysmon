<?php

set_time_limit(0);

require_once "lib/common.php";

function get_ip() {
    if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        return $_SERVER["HTTP_X_FORWARDED_FOR"];
    } else {
        return $_SERVER["REMOTE_ADDR"];
    }
}

function update_server(mysqli $db, int $id = NULL, string $hostname, string $distribution = NULL, string $version = NULL, string $kernel = NULL, int $uptime = NULL, string $ip = NULL) {
	$db_id = escape($db, $id);
	$hostname = escape($db, $hostname);
	$distribution = escape($db, $distribution ?? "");
	$version = escape($db, $version ?? "");
    $kernel = escape($db, $kernel ?? "");
    if (is_null($ip)) {
    	$ip = escape($db, get_ip());
    } else {
    	$ip = escape($db, $ip);
    }

    $db_uptime = escape($db, $uptime);

    $where = [];

    if (!is_null($id)) {
    	$where[] = "`id` = ".$db_id;
    } else {
    	$where[] = "`hostname` = ".$hostname;
    }

	$q = $db->query("SELECT `id`, `distribution`, `version`, `kernel`, `ip`, `uptime` FROM `servers` WHERE ".implode(" AND ", $where)) or fail($db->error);
	if ($a = $q->fetch_array()) {
		if (is_null($db_uptime)) {
			$db_uptime = "`uptime`";
		}

		$db->query("UPDATE `servers` SET `distribution` = ".$distribution.", `version` = ".$version.", `kernel` = ".$kernel.", `last_check` = NOW(), `ip` = ".$ip.", `uptime` = ".$db_uptime." WHERE `id` = '".$db->real_escape_string($a["id"])."'") or fail($db->error);

		$changes = [];

		$gen_change = function($component, $new) use ($a, &$changes) {
			if ($a[$component] != $new) {
				$changes[] = [
					"server_id" => $a["id"],
					"component" => $component,
					"action" => "change",
					"old_value" => $a[$component],
					"new_value" => $new
				];
			}
		};

		$gen_change("distribution", $distribution);
		$gen_change("version", $version);
		$gen_change("kernel", $kernel);
		$gen_change("ip", $ip);

		if (!is_null($a["uptime"]) && !is_null($uptime) && $uptime < $a["uptime"]) {
			send_alert($db, $a["id"], "rebooted", ["uptime" => $a["uptime"]], 1);
		}

		return $a["id"];
	} elseif (is_null($id)) {
		$db->query("INSERT INTO `servers` (`hostname`, `distribution`, `version`, `kernel`, `last_check`, `uptime`) VALUES (".$hostname.", ".$distribution.", ".$version.", ".$kernel.", NOW(), ".$ip.", ".$db_uptime.")") or fail($db->error);
		return $db->insert_id;
	}
}

function update_packages(mysqli $db, int $server_id, $packages) {
	$db_server_id = escape($db, $server_id);
	$q = $db->query("SELECT `id`, `package`, `version`, `since` FROM `packages` WHERE `server_id` = ".$db_server_id) or fail($db->error);

	$db_packages = [];

	while ($a = $q->fetch_array()) {
		$db_packages[$a["package"]] = ["version" => $a["version"], "since" => $a["since"], "id" => $a["id"]];
	}

	$changes = [];

	$to_insert = [];
	$found_packages = [];

	foreach ($packages as $package => $version) {
		$found_packages[$package] = true;

		if (isset($db_packages[$package]) && $db_packages[$package]["version"] != $version) {
			$db_package = $db_packages[$package];

			$changes[] = [
				"server_id" => $server_id,
				"component" => "packages",
				"action" => "version",
				"old_value" => $package,
				"new_value" => $package,
				"old_version" => $db_package["version"],
				"new_version" => $version
			];

			$db->query("UPDATE `packages` SET `version` = '".$db->real_escape_string($version)."', `since` = NOW() WHERE `id` = '".$db->real_escape_string($db_package["id"])."'") or fail($db->error);
		} elseif (!isset($db_packages[$package])) {
			$to_insert[] = "(".$db_server_id.", '".$db->real_escape_string($package)."', '".$db->real_escape_string($version)."', NOW())";

			$changes[] = [
				"server_id" => $server_id,
				"component" => "packages",
				"action" => "install",
				"new_value" => $package,
				"new_version" => $version
			];
		}
	}

	foreach ($db_packages as $package => $db_package) {
		if (!isset($found_packages[$package])) {
			$changes[] = [
				"server_id" => $server_id,
				"component" => "packages",
				"action" => "remove",
				"old_value" => $package,
				"old_version" => $db_package["version"]
			];

			$db->query("DELETE FROM `packages` WHERE `id` = ".(int)$db_package["id"]);
		}
	}

	multi_changelog($db, $changes);

	if (!empty($to_insert)) {
		$db->query("INSERT INTO `packages` (`server_id`, `package`, `version`, `since`) VALUES ".implode(",", $to_insert)) or fail($db->error);
	}
}

// Update server config
try {
	$db = connect();

	if ($_SERVER["REQUEST_METHOD"] != "PUT" && $_SERVER["REQUEST_METHOD"] != "POST") {
		http_response_code(405);
		throw new RuntimeException("Method ".$_SERVER["REQUEST_METHOD"]." not allowed.");
	}

	$db->autocommit(false);
	$db->query("SET NAMES utf8") or fail($db->last_error);

	$data = json_decode(file_get_contents("php://input"));
	if (!isset($data->hostname)) {
		http_response_code(400);
		throw new RuntimeException("Insufficient data. Missing hostname.");
	}

	$server_id = update_server($db,
		$data->id ?? NULL,
		$data->hostname,
		$data->distribution ?? NULL,
		$data->version ?? NULL,
		$data->kernel ?? NULL,
		$data->uptime ?? NULL,
		$data->ip ?? NULL);

	update_packages($db, $server_id, $data->packages);

	$db->commit();
	echo "OK";
} catch (Throwable $t) {
	$db->rollback();

	if (http_response_code() == 200) {
		http_response_code(500);
	}

	echo $t;
}
