from ConfigParser import SafeConfigParser, NoSectionError, NoOptionError
from argparse import ArgumentParser
from sys import argv
import os.path
import __main__
import logging
from logging import StreamHandler
from logging.handlers import WatchedFileHandler, SysLogHandler
import sys

class AppConfig:
    def __init__(self, options=None):
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
        parser.add_argument("-f", "--config", help="Config file location")

        if options is not None:
            for option_name, option_type, option_description in options:
                if isinstance(option_type, bool):
                    parser.add_argument("--%s" % (option_name, ), action="store_true", help=option_description)
                else:
                    parser.add_argument("--%s" % (option_name, ), type=option_type, help=option_description)

        args = parser.parse_args()

        if args.config:
            config.read([args.config])

        section, _ = os.path.splitext(os.path.basename(__main__.__file__))

        if options is not None:
            for option_name, option_type, _ in options:
                try:
                    val = config.get(section, option_name)
                    self.options[option_name] = option_type(val)

                except NoOptionError as e:
                    pass

                except NoSectionError as e:
                    try:
                        val = config.get("DEFAULT", option_name)
                        self.options[option_name] = option_type(val)
                    except NoOptionError as e:
                        pass
                    except NoSectionError as e:
                        pass

        for key, val in vars(args).iteritems():
            if val is not None or key not in self.options:
                self.options[key] = val

        self.setup_logging()

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