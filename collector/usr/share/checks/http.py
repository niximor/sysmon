#!/usr/bin/env python

import os
import requests
import time

from lib import reading, alert

def main():
    address = os.environ["ADDRESS"]
    validate_ssl = bool(os.environ.get("VALIDATE_SSL", False))
    required_status = int(os.environ["STATUS"]) if "STATUS" in os.environ else None
    required_keyword = os.environ.get("KEYWORD")

    start = time.time()
    r = requests.get(address, verify=validate_ssl)
    response_time = time.time() - start

    if required_status is not None:
        if r.status_code != required_status:
            alert("http_invalid_status", {
                    "required_status": required_status,
                    "actual_status": r.status_code
                })

    if required_keyword is not None:
        if r.text.find(required_keyword) < 0:
            alert("http_missing_keyword", {
                    "keyword": required_keyword
                })

    reading("status", r.status_code)
    reading("time", response_time)


if __name__ == "__main__":
    main()
