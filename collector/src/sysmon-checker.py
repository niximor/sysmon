#!/usr/bin/env python

import requests
import json
import socket
import syslog
import subprocess
import re

import time


SYSMON_ADDRESS = "https://sysmon.lan.gcm.cz"


def check_http(check):
    address = check["params"]["ADDRESS"]
    validate_ssl = bool(check["params"].get("VALIDATE_SSL", False))
    required_status = int(check["params"].get("STATUS"))
    required_keyword = check["params"].get("KEYWORD")

    start = time.time()
    r = requests.get(address, verify=validate_ssl)
    response_time = time.time() - start

    alerts = []
    readings = {}

    if required_status is not None:
        if r.status_code != required_status:
            alerts.append({
                "type": "http_invalid_status",
                "data": {
                    "required_status": required_status,
                    "actual_status": r.status_code
                }
            })

    if required_keyword is not None:
        if r.text.find(required_keyword) < 0:
            alerts.append({
                "type": "http_missing_keyword",
                "data": {
                    "keyword": required_keyword
                }
            })

    readings["status"] = r.status_code
    readings["time"] = response_time

    return {
        "alerts": alerts,
        "readings": readings
    }


def check_ping(check):
    """
    For ping, utilize the system's ping utility. Otherwise, the script must be run with root privileges,
    which is not desired.
    """
    cmd = "ping"

    if check["params"].get("IPV6"):
        cmd = "ping6"

    output = subprocess.check_output([cmd, check["params"]["ADDRESS"], "-c", check["params"].get("COUNT", "1")])
    match = re.search(r"(\d+) packets transmitted, (\d+) received", output)

    alerts = []
    readings = {}

    if match:
        if match.group(1) != match.group(2):
            if match.group(2) != "0":
                alerts.append({
                    "type": "ping_packetloss",
                    "data": {
                        "sent": match.group(1),
                        "received": match.group(2)
                    }
                })

                readings["loss"] = (int(match.group(1)) - int(match.group(2))) * 100.0 / int(match.group(1))
            else:
                readings["loss"] = 100

                alerts.append({
                    "type": "ping_failed",
                    "data": {
                        "reason": "no_packet_received"
                    }
                })
    else:
        alerts.append({
            "type": "ping_failed",
            "data": {
                "reason": "command_failed",
                "output": output
            }
        })

    rtt = re.search(r"rtt min/avg/max/mdev = ([0-9.]+)/([0-9.]+)/([0-9.]+)/([0-9.]+) ms", output)
    if rtt:
        readings["rtt"] = float(rtt.group(2))

    return {
        "alerts": alerts,
        "readings": readings
    }


def process_check(check):
    check_calls = {
        "ping": check_ping,
        "http": check_http,
        "port-open": None,
    }

    type_name = check.get("type")

    if type_name not in check_calls or check_calls[type_name] is None:
        syslog.syslog(syslog.LOG_WARNING, "Check unavailable: %s for check %s." % (check.get("type"), check.get("name")))

        return {
            "id": check["id"],
            "alerts": [
                {
                    "type": "check_unavailable",
                    "data": {
                        "type": type_name
                    }
                }
            ]
        }

    out = {
        "id": check["id"]
    }

    syslog.syslog(syslog.LOG_INFO, "Checking %s" % (check.get("name"), ))

    try:
        out.update(check_calls[type_name](check))
    except Exception as e:
        out.update({
            "alerts": [
                {
                    "type": "check_failed",
                    "data": {
                        "exception": str(e)
                    }
                }
            ]
        })

    return out


def main():
    syslog.openlog(logoption=syslog.LOG_PID)

    try:
        hostname = socket.gethostname()
        url = "%s/checks/list/%s" % (SYSMON_ADDRESS, hostname)
        checks = json.loads(requests.get(url).text)

        response = []

        for check in checks:
            response.append(process_check(check))

        if response:
            r = requests.put("%s/checks/put" % (SYSMON_ADDRESS, ), data=json.dumps(response))

            if r.status_code != 200:
                syslog.syslog(syslog.LOG_ERR, r.text)
    except Exception as e:
        import traceback
        traceback.print_exc()

        syslog.syslog(syslog.LOG_ERR, str(e))

    syslog.closelog()

if __name__ == "__main__":
    main()
