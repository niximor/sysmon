from ConfigParser import SafeConfigParser, NoSectionError, NoOptionError
from argparse import ArgumentParser
import os.path
import __main__
import logging
from logging import StreamHandler
from logging.handlers import WatchedFileHandler, SysLogHandler
import sys


class AppConfig:
    def __init__(self, options=None, args=None):
        """
        :param options: Tuple containing (option_name, option_type, option_description) with options
        that the program accepts.
        """
        self.options = {}

        if options is None:
            options = []

        options.append(("log", str, "Specify STDOUT or STDERR for console output, SYSLOG for syslog output or valid file name. Default is STDERR."))
        options.append(("log_level", str, "Specify highest log level that should be logged. Can be DEBUG, INFO, WARNING, ERROR, CRITICAL. Default is DEBUG."))

        config = SafeConfigParser()

        parser = ArgumentParser()
        parser.add_argument("-f", "--config", help="Config file location", default="/etc/gcm-sysmon/sysmon.cfg")

        if options is not None:
            for option_name, option_type, option_description in options:
                if option_type == bool:
                    parser.add_argument("--%s" % (option_name, ), action="store_const", const=True, dest=option_name, help=option_description)
                    parser.add_argument("--no-%s" % (option_name, ), action="store_const", const=False, dest=option_name, help=option_description)
                else:
                    parser.add_argument("--%s" % (option_name, ), type=option_type, help=option_description)

        if args is not None:
            for arg in args:
                name = arg["name"]
                del arg["name"]

                parser.add_argument(name, **arg)

        args = parser.parse_args()

        if args.config:
            config.read([args.config])

        section, _ = os.path.splitext(os.path.basename(__main__.__file__))

        if options is not None:
            for option_name, option_type, _ in options:
                try:
                    val = config.get(section, option_name)
                    self.options[option_name] = self._convert_type(val, option_type)

                except (NoOptionError, NoSectionError) as e:
                    try:
                        val = config.get("DEFAULT", option_name)
                        self.options[option_name] = self._convert_type(val, option_type)
                    except NoOptionError as e:
                        pass
                    except NoSectionError as e:
                        pass

        for key, val in vars(args).iteritems():
            if val is not None:
                self.options[key] = val

        self.setup_logging()

        logging.debug("Configuration dump:")
        for option, value in self.options.iteritems():
            logging.debug("    %s=%s" % (option, value))

    def _convert_type(self, val, option_type):
        if option_type == bool:
            return self._get_bool(val)
        else:
            return option_type(val)

    @staticmethod
    def _get_bool(val):
        if isinstance(val, bool):
            return val
        elif isinstance(val, (str, unicode)):
            if val == "1" or val.lower() == "true":
                return True
            elif val == "0" or val.lower() == "false":
                return False
            else:
                raise ValueError(val)
        elif isinstance(val, (int, float)):
            return bool(val)
        else:
            raise ValueError(val)

    def setup_logging(self):
        root = logging.getLogger()
        root.handlers = []
        root.name, _ = os.path.splitext(os.path.basename(__main__.__file__))

        output = self.get("log", "STDERR")

        handler = None
        formatter = logging.Formatter("%(asctime)s %(name)s [%(process)s] %(levelname)s: %(message)s {%(filename)s:%(lineno)s}")

        if output == "STDERR":
            handler = StreamHandler(sys.stderr)
            handler.setFormatter(formatter)
        elif output == "STDOUT":
            handler = StreamHandler(sys.stdout)
            handler.setFormatter(formatter)
        elif output == "SYSLOG":
            handler = SysLogHandler("/dev/log")
            handler.setFormatter(logging.Formatter("%(name)s[%(process)s] %(levelname)s: %(message)s {%(filename)s:%(lineno)s}"))
        else:
            handler = WatchedFileHandler(output)
            handler.setFormatter(formatter)

        level = None
        try:
            level = {
                "DEBUG": logging.DEBUG,
                "INFO": logging.INFO,
                "WARNING": logging.WARNING,
                "ERROR": logging.ERROR,
                "CRITICAL": logging.CRITICAL
            }[self.get("log_level", "DEBUG")]
        except KeyError as e:
            level = logging.DEBUG;

        root.addHandler(handler)
        root.setLevel(level)

    def get(self, option, default=None):
        return self.options.get(option, default)

    def __getattr__(self, name):
        return self.options[name]

    @staticmethod
    def argument(name, **kwargs):
        kwargs["name"] = name
        return kwargs
