import json
from sys import stderr, stdout

def alert(name, data):
    stderr.write("ALERT:%s:%s\n" % (name, json.dumps(data)))

def reading(name, value):
    stdout.write("%s=%s\n" % (name, value))
