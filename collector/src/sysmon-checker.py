#!/usr/bin/env python

from lib.config import AppConfig
import logging

import requests
import json
import socket
import subprocess
import time
import traceback
import os

log = None


"""
Check output format:
STDOUT:
reading=value
everything else goes to log with info severity

STDERR:
ALERT:type:params as json
everything else goes to log with error severity
"""


class Check:
    def __init__(self, name, binary):
        self.name = name
        self.binary = binary

    def execute(self, check):
        check_log = logging.getLogger("check.%s" % self.name)

        try:
            new_env = {
                key: val for key, val in os.environ.iteritems()
            }
            new_env.update(check["params"])

            logging.info("Checking %s." % (check.get("name"), ))
            p = subprocess.Popen([self.binary], env=new_env, stdin=None, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            out, err = p.communicate()

            readings = {}
            alerts = []

            if out is None:
                out = ""

            if err is None:
                err = ""

            for line in out.split("\n"):
                if not line:
                    continue

                reading = line.split("=", 2)
                if (len(reading) != 2):
                    check_log.info(line)
                else:
                    try:
                        readings[reading[0]] = float(reading[1])
                    except ValueError as e:
                        check_log.info(line)

            for line in err.split("\n"):
                if not line:
                    continue

                if line.startswith("ALERT:"):
                    alert = line.split(":", 3)
                    if len(alert) > 1:
                        try:
                            alerts.append({
                                "type": alert[2],
                                "data": json.loads(alert[3]) if len(alert) > 2 else {}
                            })
                        except ValueError as e:
                            check_log.error("Invalid JSON alert data: %s" % (str(e), ))
                            check_log.debug(traceback.format_exc())
                    else:
                        check_log.error(line)
                else:
                    check_log.error(line)


            return {
                "id": check["id"],
                "readings": readings,
                "alerts": alerts
            }

        except Exception as e:
            check_log.error(str(e))
            check_log.debug(traceback.format_exc())

            return {
                "id": check["id"],
                "alerts": [
                    {
                        "type": "check_failed",
                        "data": {
                            "exception": e.__class__.__name__,
                            "message": str(e)
                        }
                    }
                ]
            }


def find_checks(dirs):
    checks = {}

    for dirname in dirs:
        for file in os.listdir(dirname):
            full_name = os.path.join(dirname, file)

            # Executable non-directory is check.
            if not os.path.isdir(full_name) and os.access(full_name, os.X_OK):
                name, _ = os.path.splitext(file)

                if name not in checks:
                    checks[name] = Check(name, full_name)
                else:
                    log.warning("Duplicate check binary: %s. Using %s for check %s." % (full_name, checks[name].binary, name))

    return checks



def main():
    conf = AppConfig([
        ("server_address", str, "Address of SYSmon server."),
        ("dir", str, "Directory to search for checks. To specify more than one path, separate paths with semicolon.")
    ])

    log = logging.getLogger()
    log.info("SYSmon checker is starting.")

    try:
        server_address = conf.get("server_address")
        if server_address is None:
            raise Exception("No server address configured.")

        hostname = socket.gethostname()
        url = "%s/checks/list/%s" % (server_address, hostname)
        checks = json.loads(requests.get(url).text)

        response = []

        known_check = find_checks(conf.get("dir", "/usr/share/gcm-sysmon/checks/").split(";"))

        for check in checks:
            check_name = check["type"]
            try:
                response.append(known_check[check_name].execute(check))
            except KeyError as e:
                response.append({
                    "id": check["id"],
                    "alerts": [
                        {
                            "type": "check_unavailable",
                            "data": {
                                "type": check_name
                            }
                        }
                    ]
                })

        if response:
            r = requests.put("%s/checks/put" % (server_address, ), data=json.dumps(response))

            if r.status_code != 200:
                logging.error(r.text)
    except Exception as e:
        logging.error(str(e))
        logging.debug(traceback.format_exc())

    log.info("SYSmon checker finished.")

if __name__ == "__main__":
    main()
