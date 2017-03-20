#!/usr/bin/env python

from unittest import TestCase, main
import sys
import os
from lib.config import AppConfig

class TestBoolConversion(TestCase):
    def test_conv_bool_t(self):
        self.assertTrue(AppConfig._get_bool(True))

    def test_conv_bool_f(self):
        self.assertFalse(AppConfig._get_bool(False))

    def test_conv_int_t(self):
        self.assertTrue(AppConfig._get_bool(1))

    def test_conv_int_f(self):
        self.assertFalse(AppConfig._get_bool(0))

    def test_conv_str_num_t(self):
        self.assertTrue(AppConfig._get_bool("1"))

    def test_conv_str_num_f(self):
        self.assertFalse(AppConfig._get_bool("0"))

    def test_conv_str_name_t(self):
        self.assertTrue(AppConfig._get_bool("True"))
        self.assertTrue(AppConfig._get_bool("TRUE"))
        self.assertTrue(AppConfig._get_bool("true"))

    def test_conv_str_name_f(self):
        self.assertFalse(AppConfig._get_bool("False"))
        self.assertFalse(AppConfig._get_bool("FALSE"))
        self.assertFalse(AppConfig._get_bool("false"))

    def test_conv_u_num_t(self):
        self.assertTrue(AppConfig._get_bool(u"1"))

    def test_conv_u_num_f(self):
        self.assertFalse(AppConfig._get_bool(u"0"))

    def test_conv_u_name_t(self):
        self.assertTrue(AppConfig._get_bool(u"True"))
        self.assertTrue(AppConfig._get_bool(u"TRUE"))
        self.assertTrue(AppConfig._get_bool(u"true"))

    def test_conv_u_name_f(self):
        self.assertFalse(AppConfig._get_bool(u"False"))
        self.assertFalse(AppConfig._get_bool(u"FALSE"))
        self.assertFalse(AppConfig._get_bool(u"false"))

    def test_fail_gibrish(self):
        with self.assertRaises(ValueError):
            self.assertFalse(AppConfig._get_bool("gibrish"))
