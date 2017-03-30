#!/usr/bin/env python

from lib.config import AppConfig

import requests
import subprocess
import socket
import os
import json
import logging
import traceback


log = None


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
            log.warning("Unable to get uptime: %s" % (str(e)))
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
    conf = AppConfig([
        ("server_address", str, "Address of SYSmon server."),
        ("server_verify_ssl", bool, "Verify SYSmon server's SSL certificate?")
    ])

    log = logging.getLogger()
    log.info("SYSmon collector is starting.")

    try:
        server_address = conf.get("server_address")
        if server_address is None:
            raise Exception("No server address configured.")

        hostname = get_hostname()
        distribution, version = get_system_version()
        kernel = get_system_kernel()
        uptime = get_uptime()

        packages = get_packages()

        r = requests.put("%s/collect.php" % (conf.get("server_address"), ), data=json.dumps({
            "hostname": hostname,
            "distribution": distribution,
            "version": version,
            "kernel": kernel,
            "uptime": uptime,
            "packages": packages
        }), verify=conf.get("server_verify_ssl", True))

        if r.status_code != 200:
            log.error(r.text)
        else:
            log.debug(r.text)
    except Exception as e:
        log.error(str(e))
        log.debug(traceback.format_exc())

    log.info("SYSmon collector finished.")

if __name__ == "__main__":
    main()
