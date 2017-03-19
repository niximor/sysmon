#!/usr/bin/env python

from lib import reading, alert
import os
import subprocess
import re

def main():
    """
    For ping, utilize the system's ping utility. Otherwise, the script must be run with root privileges,
    which is not desired.
    """

    cmd = "ping"

    if os.environ.get("IPV6", "0") == "1":
        cmd = "ping6"

    output = subprocess.check_output([cmd, os.environ["ADDRESS"], "-A", "-c", os.environ.get("COUNT", "1")])
    match = re.search(r"(\d+) packets transmitted, (\d+) received", output)

    if match:
        if match.group(1) != match.group(2):
            if match.group(2) != "0":
                alert("ping_packetloss", {
                        "sent": match.group(1),
                        "received": match.group(2)
                    })

                reading("loss", (int(match.group(1)) - int(match.group(2))) * 100.0 / int(match.group(1)))
            else:
                reading("loss", 100)
                alert("ping_failed", {
                        "reason": "no_packet_received"
                    })
        else:
            reading("loss", 0)

        rtt = re.search(r"rtt min/avg/max/mdev = ([0-9.]+)/([0-9.]+)/([0-9.]+)/([0-9.]+) ms", output)
        if rtt:
            reading("rtt", float(rtt.group(2)))
    else:
        alert("ping_failed", {
                "reason": "command_failed",
                "output": output
            })

if __name__ == "__main__":
    main()