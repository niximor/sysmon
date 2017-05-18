import json
from sys import stderr, stdout
import traceback

def alert(name, data):
    stderr.write("ALERT:%s:%s\n" % (name, json.dumps(data)))

def reading(name, value):
    stdout.write("%s=%s\n" % (name, value))

def main(callback):
    try:
        callback()
    except Exception as e:
        alert("check_failed", {"exception": e.__class__.__name__, "message": str(e)})
        traceback.print_exc()
