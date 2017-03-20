#!/usr/bin/env python

from lib import reading, alert

import os.path
import re

def main():
    if "INTERFACE" not in os.environ:
        alert("bad_param", {
            "name": "INTERFACE",
            "expected": "Interface name."
            })
        return

    interface = os.environ["INTERFACE"]

    iface_root = "/sys/class/net/%s/statistics" % (interface, )

    if os.path.exists(iface_root):
        reading("rx_bytes", open(os.path.join(iface_root, "rx_bytes"), "r").read().strip())
        reading("tx_bytes", open(os.path.join(iface_root, "tx_bytes"), "r").read().strip())

        reading("rx_packets", open(os.path.join(iface_root, "rx_packets"), "r").read().strip())
        reading("tx_packets", open(os.path.join(iface_root, "tx_packets"), "r").read().strip())
    elif os.path.exists("/proc/net/dev"):
        found = False
        for line in open("/proc/net/dev", "r"):
            parts = re.split(r"\s+", line.strip())
            if parts[0] == "%s:" % (interface, ):
                found = True

                reading("rx_bytes", parts[1])
                reading("tx_bytes", parts[9])

                reading("rx_packets", parts[2])
                reading("tx_packets", parts[10])

                break

        if not found:
            alert("iface_missing", {
                "interface": interface
                })

    elif os.path.exists("/sys/class/net/"):
        alert("iface_missing", {
            "interface": interface
            })
    else:
        alert("check_failed", {
            "message": "No known method of retrieving interface statistics available on this host.",
            })

if __name__ == "__main__":
    main()
