#!/usr/bin/env python

import requests
import subprocess
import socket
import os
import json
import syslog


SYSMON_ADDRESS = "https://sysmon.lan.gcm.cz"


def get_hostname():
	return socket.gethostname()


def get_system_version():
	distribution = None
	version = None
	
	try:
		distribution = subprocess.check_output("lsb_release -is", shell=True).strip()
		version = subprocess.check_output("lsb_release -rs", shell=True).strip()
	except subprocess.CalledProcessError:
		if os.path.exists("/etc/debian_version"):
			distribution = "Debian"
			version = open("/etc/debian_version", "r").read()

	return distribution, version


def get_system_kernel():
	try:
		return subprocess.check_output("uname -srvmpio", shell=True).strip()
	except subprocess.CalledProcessError:
		return None


def get_uptime():
	if os.path.exists("/proc/uptime"):
		try:
			return int(float(open("/proc/uptime", "r").read().split(" ")[0]))
		except Exception as e:
			syslog.syslog(syslog.LOG_WARNING, "Unable to get uptime: %s" % (str(e)))
			return None
	else:
		return None


def get_packages():
	packages = {}

	for line in subprocess.check_output("dpkg -l | egrep '^i' | sed -e 's/[ ]\+/ /g' | cut -d' ' -f2-3", shell=True).split("\n"):
		if not line:
			continue

		name, version = line.split(" ")
		packages[name] = version

	return packages


def main():
	syslog.openlog(logoption=syslog.LOG_PID)
	syslog.syslog(syslog.LOG_INFO, "Sysmon collector starting.")

	try:
		hostname = get_hostname()
		distribution, version = get_system_version()
		kernel = get_system_kernel()
		uptime = get_uptime()

		packages = get_packages()

		r = requests.put("%s/collect.php" % (SYSMON_ADDRESS, ), data=json.dumps({
			"hostname": hostname,
			"distribution": distribution,
			"version": version,
			"kernel": kernel,
			"uptime": uptime,
			"packages": packages
		}))

		if r.status_code != 200:
			syslog.syslog(syslog.LOG_ERR, r.text)
		else:
			syslog.syslog(syslog.LOG_DEBUG, r.text)
	except Exception as e:
		syslog.syslog(syslog.LOG_ERR, str(e))

	syslog.syslog(syslog.LOG_INFO, "Sysmon collector finished.")
	syslog.closelog()

if __name__ == "__main__":
	main()

