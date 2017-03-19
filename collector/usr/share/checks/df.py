#!/usr/bin/env python

import os
from lib import alert, reading

def main():
    try:
        mountpoint = os.environ["MOUNTPOINT"]

        stat = os.statvfs(mountpoint)

        total_size = stat.f_frsize * stat.f_blocks
        free_space = stat.f_frsize * stat.f_bavail
        free_percent = free_space * 100.0 / total_size

        reading("size_bytes", total_size)
        reading("free_bytes", free_space)
        reading("free_percent", free_percent)

        try:
            if free_percent < float(os.environ.get("ALERT_THRESHOLD", 0)):
                alert("low_disk_space", {
                        "mountpoint": mountpoint,
                        "free_percent": free_percent,
                        "free_bytes": free_space,
                        "size_bytes": total_size
                    })
        except ValueError as e:
            alert("bad_param", {
                    "name": "ALERT_THRESHOLD",
                    "expected": "float"
                })

    except KeyError as e:
        alert("bad_params", {
                "name": str(e),
                "expected": "Mount point"
            })

if __name__ == "__main__":
    main()
