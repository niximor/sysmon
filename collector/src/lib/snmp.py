import socket

from pyasn1.modules import rfc1157
from pyasn1.codec.ber import encoder, decoder
from pyasn1.error import PyAsn1Error


class SnmpException(Exception):
    pass


class SNMP:
    reqid = 0

    def __init__(self, version, community, address, port, timeout=10.0):
        self.version = version
        self.community = community

        if self.version not in ("1", "2c"):
            raise ValueError("SNMP version can be only 1 or 2c.")

        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock.settimeout(timeout)
        self.server_address = (address, port)

    @staticmethod
    def nextid():
        SNMP.reqid += 1
        return SNMP.reqid

    def get(self, oid):
        try:
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
            var["value"]["simple"]["empty-value"] = None

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
                if simple["string-value"] is not None:
                    return str(simple["string-value"])
                elif simple["integer-value"] is not None:
                    return int(simple["integer-value"])
                elif simple["objectID-value"] is not None:
                    return str(simple["objectID-value"])
                else:
                    print "Warning: Unknown simple-value: %s" % (simple.prettyPrint(), )
                    return None
            elif value["application-wide"]:
                app = value["application-wide"]
                if app["ipAddress-value"] is not None:
                    return str(app["ipAddress-value"])
                elif app["counter-value"] is not None:
                    return int(app["counter-value"])
                elif app["gauge32-value"] is not None:
                    return int(app["gauge"])
                elif app["timeticks-value"] is not None:
                    return int(app["timeticks-value"])
                elif app["arbitrary-value"] is not None:
                    return str(app["arbitrary-value"])
                elif app["big-counter-value"] is not None:
                    return int(app["big-counter-value"])
                else:
                    print "Warning: Unknown application-wide: %s" % (app.prettyPrint(), )
                    return None
            else:
                print "Warning: Unknown value: %s" % (value.prettyPrint(), )
                return None
        except socket.gaierror as e:
            raise SnmpException("Hostname %s was not found." % (self.server_address[0], ))
        except socket.timeout as e:
            raise SnmpException("Timeout when talking to %s." % (self.server_address[0], ))
        except PyAsn1Error:
            return None
