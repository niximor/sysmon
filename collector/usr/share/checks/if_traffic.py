#!/usr/bin/env python

from lib import reading, alert, main
from lib.snmp import SNMP

import os.path
import re


def snmp_main():
    err = False

    if "SNMP_VERSION" not in os.environ or os.environ["SNMP_VERSION"] not in ("1", "2c"):
        alert("bad_param", {"name": "SNMP_VERSION", "expected": "1 or 2c"})
        err = True

    if "SNMP_COMMUNITY" not in os.environ:
        alert("bad_param", {"name": "SNMP_COMMUNITY", "expected": "Community name"})
        err = True

    if "SNMP_HOSTNAME" not in os.environ:
        alert("bad_param", {"name": "SNMP_HOSTNAME", "expected": "Hostname"})
        err = True

    if "INTERFACE" not in os.environ:
        alert("bad_param", {"name": "INTERFACE", "expected": "Interface name."})
        err = True

    port = 161
    try:
        port = int(os.environ.get("SNMP_PORT", 161))
    except ValueError:
        alert("bad_param", {"name": "SNMP_PORT", "expected": "Port number."})
        err = True

    timeout = 10.0
    try:
        timeout = float(os.environ.get("SNMP_TIMEOUT", 10.0))
    except ValueError:
        alert("bad_param", {"name": "SNMP_TIMEOUT", "expected": "Timeout in seconds."})
        err = True

    if err:
        return

    snmp = SNMP(
        os.environ["SNMP_VERSION"],
        os.environ["SNMP_COMMUNITY"],
        os.environ["SNMP_HOSTNAME"],
        port,
        timeout,
    )

    interface = os.environ["INTERFACE"]

    if_count = snmp.get("1.3.6.1.2.1.2.1.0")

    for i in range(1, if_count + 1):
        if_name = snmp.get("1.3.6.1.2.1.31.1.1.1.1.%d" % (i, ))
        if if_name == interface:
            rx_mcast_packets = snmp.get("1.3.6.1.2.1.31.1.1.1.8.%d" % (i, ))
            rx_bcast_packets = snmp.get("1.3.6.1.2.1.31.1.1.1.9.%d" % (i, ))
            rx_ucast_packets = snmp.get("1.3.6.1.2.1.31.1.1.1.7.%d" % (i, ))
            rx_octets = snmp.get("1.3.6.1.2.1.31.1.1.1.6.%d" % (i, ))

            tx_mcast_packets = snmp.get("1.3.6.1.2.1.31.1.1.1.12.%d" % (i, ))
            tx_bcast_packets = snmp.get("1.3.6.1.2.1.31.1.1.1.13.%d" % (i, ))
            tx_ucast_packets = snmp.get("1.3.6.1.2.1.31.1.1.1.11.%d" % (i, ))
            tx_octets = snmp.get("1.3.6.1.2.1.31.1.1.1.10.%d" % (i, ))

            reading("rx_bytes", rx_octets)
            reading("tx_bytes", tx_octets)

            reading("rx_packets", rx_mcast_packets + rx_bcast_packets + rx_ucast_packets)
            reading("tx_packets", tx_mcast_packets + tx_bcast_packets + tx_ucast_packets)
            return

    alert("iface_missing", {
        "interface": interface
        })


def local_main():
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


def check():
    if "USE_SNMP" in os.environ and os.environ["USE_SNMP"] == "1":
        snmp_main()
    else:
        local_main()

if __name__ == "__main__":
    main(check)
