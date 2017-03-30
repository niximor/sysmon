#!/usr/bin/env python

from lib.config import AppConfig
import logging

import socket
import requests
import json
import traceback

from pyasn1.modules import rfc1157
from pyasn1.codec.ber import encoder, decoder


class SnmpException(Exception):
    pass


class SNMP:
    reqid = 0

    def __init__(self, version, community, address, port):
        self.version = version
        self.community = community

        if self.version not in ("1", "2c"):
            raise ValueError("SNMP version can be only 1 or 2c.")

        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.server_address = (address, port)

    @staticmethod
    def nextid():
        SNMP.reqid += 1
        return SNMP.reqid

    def get(self, oid):
        msg = rfc1157.Message()

        if self.version == "1":
            msg["version"] = 0
        elif self.version == "2c":
            msg["version"] = 1

        msg["community"] = self.community

        msg["data"] = msg["data"]
        msg["data"]["get-request"] = msg["data"]["get-request"]

        get_request = msg["data"]["get-request"]
        get_request["request-id"] = self.nextid()
        get_request["error-status"] = 0
        get_request["error-index"] = 0

        get_request["variable-bindings"] = get_request["variable-bindings"]

        var = rfc1157.VarBind()
        var["name"] = oid
        var["value"] = var["value"]
        var["value"]["simple"] = var["value"]["simple"]
        var["value"]["simple"]["empty"] = None

        get_request["variable-bindings"].append(var)

        msg["data"]["get-request"] = get_request

        raw_msg = encoder.encode(msg)
        self.sock.sendto(raw_msg, self.server_address)
        raw_msg, server = self.sock.recvfrom(4096)

        msg, _ = decoder.decode(raw_msg, asn1Spec=msg)

        if msg is None or msg["data"] is None or msg["data"]["get-response"] is None:
            raise SnmpException("No response in data.")

        if msg["data"]["get-response"]["error-status"] and int(msg["data"]["get-response"]["error-status"]) != 0:
            raise SnmpException(str(msg["data"]["get-response"]["error-status"]))

        if msg["data"]["get-response"]["variable-bindings"] is None or len(msg["data"]["get-response"]["variable-bindings"]) == 0:
            raise SnmpException("No response in data.")

        value = msg["data"]["get-response"]["variable-bindings"][0]["value"]

        if value["simple"]:
            simple = value["simple"]
            if simple["string"]:
                return str(simple["string"])
            elif simple["number"]:
                return int(simple["number"])
            elif simple["object"]:
                return str(simple["object"])
            elif simple["empty"]:
                return None
        elif value["application-wide"]:
            app = value["application-wide"]
            if app["address"]:
                if app["internet"]:
                    return str(app["internet"])
                else:
                    return None
            elif app["counter"]:
                return int(app["counter"])
            elif app["gauge"]:
                return int(app["gauge"])
            elif app["ticks"]:
                return int(app["ticks"])
            elif app["arbitrary"]:
                return str(app["arbitrary"])
        else:
            return None


def try_device(conf, log, device):
    try:
        snmp = SNMP(device["version"], device["community"], device["hostname"], int(device["port"]))
        hostname = snmp.get("1.3.6.1.2.1.1.5.0")
        uptime = snmp.get("1.3.6.1.2.1.1.3.0") / 100
        kernel = snmp.get("1.3.6.1.2.1.1.1.0")

        r = requests.put("%s/collect.php" % (conf.get("server_address"), ), data=json.dumps({
            "id": device["id"],
            "hostname": hostname,
            "kernel": kernel,
            "uptime": uptime,
        }), verify=conf.get("server_verify_ssl", True))

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
