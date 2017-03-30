#!/usr/bin/env python

from lib.config import AppConfig
from lib.snmp import SNMP
import logging

import socket
import requests
import json
import traceback


def get_microtic(snmp, data):
    data["distribution"] = "RouterOS"
    data["version"] = snmp.get("1.3.6.1.4.1.14988.1.1.4.4.0")


vendor_specific = {
    "1.3.6.1.4.1.14988.1": get_microtic
}


def try_device(conf, log, device):
    try:
        log.info("Querying %s." % (device["hostname"], ))

        snmp = SNMP(device["version"], device["community"], device["hostname"], int(device["port"]))

        data = {
            "id": device["id"],
            "hostname": snmp.get("1.3.6.1.2.1.1.5.0"),
            "kernel": snmp.get("1.3.6.1.2.1.1.1.0"),
            "uptime": snmp.get("1.3.6.1.2.1.1.3.0") / 100,
        }

        vendor = snmp.get("1.3.6.1.2.1.1.2.0")

        if vendor in vendor_specific:
            vendor_specific[vendor](snmp, data)

        r = requests.put("%s/collect.php" % (conf.get("server_address"), ), data=json.dumps(data), verify=conf.get("server_verify_ssl", True))

        if r.status_code != 200:
            log.error(r.text)
        else:
            log.debug(r.text)

    except Exception as e:
        log.error(str(e))
        log.debug(traceback.format_exc())


def main():
    conf = AppConfig([
        ("server_address", str, "Address of SYSmon server."),
        ("server_verify_ssl", bool, "Verify SYSmon server's SSL certificate?")
    ])

    log = logging.getLogger()
    log.info("SYSmon SNMP agent is starting.")

    try:
        server_address = conf.get("server_address")
        if server_address is None:
            raise Exception("No server address configured.")

        hostname = socket.gethostname()
        url = "%shosts/list/%s" % (server_address, hostname)
        r = requests.get(url, verify=conf.get("server_verify_ssl", True))
        devices = json.loads(r.text)

        for device in devices:
            try_device(conf, log, device)

    except Exception as e:
        log.error(str(e))
        log.debug(traceback.format_exc())

    log.info("SYSmon SNMP agent finished.")


if __name__ == "__main__":
    main()
