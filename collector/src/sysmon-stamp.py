#!/usr/bin/env python

import argparse
import requests
import subprocess
import socket
import sys
import logging

from lib.config import AppConfig


def main():
    conf = AppConfig([
        ("server_address", str, "Address of SYSmon server."),
        ("server_verify_ssl", bool, "Verify SYSmon server's SSL certificate?")
    ], [
        AppConfig.argument("stamp", type=str, nargs=1, help="Stamp name"),
        AppConfig.argument("command", metavar="COMMAND", type=str, nargs=argparse.REMAINDER, help="Optional command to execute.")
    ])

    exitcode = 0

    if len(conf.command):
        logging.debug("Executing command %s" % (conf.command))
        try:
            exitcode = subprocess.call(conf.command, shell=len(conf.command) == 1)
        except Exception as e:
            logging.exception(e)
            exitcode = 255

    if exitcode == 0:
        logging.info("Writing stamp `%s`." % (conf.stamp[0], ))
        hostname = socket.gethostname()
        requests.get("%sstamps/put/%s/%s" % (conf.server_address, hostname, conf.stamp[0]), verify=conf.get("server_verify_ssl", True))
        sys.exit(0)
    else:
        logging.info("NOT writing stamp `%s` because of bad exit code %d." % (conf.stamp[0], exitcode))
        sys.exit(exitcode)


if __name__ == "__main__":
    main()
