#!/usr/bin/env python

from unittest import TestCase, main
import sys
import os
import subprocess
import re
import json

class TestIfTraffic(TestCase):
    def setUp(self):
        self.env = {}
        for key, val in os.environ.iteritems():
            self.env[key] = val

    def popen(self, env=None):
        if env is not None:
            self.env.update(env)

        executable = os.path.join(os.path.dirname(__file__), "..", "usr", "share", "checks", "if_traffic.py")

        p = subprocess.Popen([executable], env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        out, err = p.communicate()
        return out, err

    def assertAlert(self, err, alert_name, data=None):
        if not isinstance(alert_name, (str, unicode)):
            raise TypeError("alert_name must be str or unicode. Instead, got %s." % (type(alert_name).__name__, ))

        if data is not None and not isinstance(data, (dict, )):
            raise TypeError("data must be dict. Instead, got %s." % (type(data).__name__, ))

        matches = re.findall(r"^ALERT:%s:(.*?)$" % (re.escape(alert_name), ),
                          err, re.MULTILINE)
        if not matches:
            if data:
                raise AssertionError("Expected alert %s with data %s was not found. Got only: %s" % (alert_name, json.dumps(data), err))
            else:
                raise AssertionError("Expected alert %s was not found. Got only: %s" % (alert_name, err))
        elif data is not None:
            for match in matches:
                fail = False
                alert_data = json.loads(match)
                for key, val in data.iteritems():
                    if key not in alert_data:
                        fail = True
                    elif alert_data[key] != val:
                        fail = True

                if not fail:
                    return

            raise AssertionError("Expected alert %s with data %s. Got only %s." % (alert_name, json.dumps(data), err))

    def test_nonexisting_iface(self):
        out, err = self.popen({
            "INTERFACE": "nonexisting-iface",
        })

        self.assertAlert(err, "iface_missing", {"interface": "nonexisting-iface"})
        self.assertEqual(out, "")

    def test_missing_iface(self):
        out, err = self.popen({})

        self.assertAlert(err, "bad_param", {"name": "INTERFACE", "expected": "Interface name."})
        self.assertEqual(out, "")

    def test_existing_iface(self):
        out, err = self.popen({"INTERFACE": "lo"})
        self.assertEqual(err, "")
        self.assertRegexpMatches(out, r"^rx_bytes=[0-9]+\ntx_bytes=[0-9]+\nrx_packets=[0-9]+\ntx_packets=[0-9]+\nrx_errors=[0-9]+\ntx_errors=[0-9]+\n$")

    def test_snmp_bad_params(self):
        out, err = self.popen({"USE_SNMP": "1"})
        self.assertEqual(out, "")
        self.assertAlert(err, "bad_param", {"name": "SNMP_VERSION", "expected": "1 or 2c"})
        self.assertAlert(err, "bad_param", {"name": "SNMP_COMMUNITY", "expected": "Community name"})
        self.assertAlert(err, "bad_param", {"name": "SNMP_HOSTNAME", "expected": "Hostname"})
        self.assertAlert(err, "bad_param", {"name": "INTERFACE", "expected": "Interface name."})

    def test_snmp_bad_port(self):
        out, err = self.popen({"USE_SNMP": "1", "SNMP_VERSION": "1", "SNMP_COMMUNITY": "public", "SNMP_HOSTNAME": "localhost", "SNMP_PORT": "abc", "INTERFACE": "lo"})
        self.assertEqual(out, "")
        self.assertAlert(err, "bad_param", {"name": "SNMP_PORT", "expected": "Port number."})

    def test_snmp_bad_timeout(self):
        out, err = self.popen({"USE_SNMP": "1", "SNMP_VERSION": "1", "SNMP_COMMUNITY": "public", "SNMP_HOSTNAME": "localhost", "SNMP_TIMEOUT": "abc", "INTERFACE": "lo"})
        self.assertEqual(out, "")
        self.assertAlert(err, "bad_param", {"name": "SNMP_TIMEOUT", "expected": "Timeout in seconds."})


    def test_snmp_bad_hostname(self):
        out, err = self.popen({"USE_SNMP": "1", "SNMP_VERSION": "1", "SNMP_COMMUNITY": "public", "SNMP_HOSTNAME": "nonexisting-host.example.com", "INTERFACE": "lo"})
        self.assertEqual(out, "")
        self.assertAlert(err, "check_failed", {"exception": "SnmpException", "message": "Hostname nonexisting-host.example.com was not found."})

    def test_snmp_timeout(self):
        out, err = self.popen({"USE_SNMP": "1", "SNMP_VERSION": "1", "SNMP_COMMUNITY": "public", "SNMP_HOSTNAME": "localhost", "INTERFACE": "lo", "SNMP_PORT": "1", "SNMP_TIMEOUT": "0.1"})
        self.assertEqual(out, "")
        self.assertAlert(err, "check_failed", {"exception": "SnmpException", "message": "Timeout when talking to localhost."})
