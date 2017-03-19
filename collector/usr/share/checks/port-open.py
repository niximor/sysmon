#!/usr/bin/env python

from lib import alert
import os
import socket

def main():
    alerts = []
    readings = {}

    s_family = socket.AF_INET

    all_ok = True

    v6 = os.environ.get("IPV6", "0")
    if v6 == "1":
        s_family = socket.AF_INET6
    elif v6 not in ("0", "1"):
        alert("bad_param", {
                "name": "IPV6",
                "expected": "0 or 1"
            })
        all_ok = False

    s_type = socket.SOCK_STREAM

    address = os.environ.get("ADDRESS", "127.0.0.1")

    try:
        port = int(os.environ["PORT"])
        if port <= 0 or port > 65535:
            raise ValueError("port outside range")
    except (KeyError, ValueError) as e:
        alert("bad_param", {
                "name": "PORT",
                "expected": "int"
            })
        all_ok = False

    try:
        timeout = float(os.environ.get("TIMEOUT", "0"))
        if timeout < 0.0:
            raise ValueError("timeout must be positive")
        elif timeout < 0.0001:
            timeout = None
    except ValueError as e:
        alert("bad_param", {
                "name": "TIMEOUT",
                "expected": "float"
            })
        all_ok = False

    if all_ok:
        try:
            s = socket.socket(s_family, s_type)
            s.settimeout(timeout)
            s.connect((address, port))
            s.close()
        except socket.error as e:
            alert("port_check_failed", {
                    "errno": e.errno,
                    "strerror": e.strerror
                })

if __name__ == "__main__":
    main()