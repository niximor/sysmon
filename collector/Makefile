VERSION=$(strip $(shell dpkg-parsechangelog | grep Version: | cut -d':' -f2))
DEPLOY_DESTS=
root_dir := $(patsubst %/,%, $(dir $(realpath $(firstword $(MAKEFILE_LIST)))))

all:

include Makefile.*

install:
	install -m 644 -D etc/sysmon.cfg $(DESTDIR)/etc/gcm-sysmon/sysmon.cfg
	install -m 755 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin src/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/lib src/lib/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/pyasn1 src/pyasn1/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/pyasn1/codec src/pyasn1/codec/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/pyasn1/codec/ber src/pyasn1/codec/ber/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/pyasn1/codec/cer src/pyasn1/codec/cer/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/pyasn1/codec/der src/pyasn1/codec/der/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/pyasn1/codec/native src/pyasn1/codec/native/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/pyasn1/compat src/pyasn1/compat/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/pyasn1/modules src/pyasn1/modules/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/bin/pyasn1/type src/pyasn1/type/*.py
	install -m 644 -D -t $(DESTDIR)/usr/share/gcm-sysmon/checks/lib usr/share/checks/lib/*.py
	install -m 755 -D -t $(DESTDIR)/usr/share/gcm-sysmon/checks usr/share/checks/*.py

check:
	PYTHONPATH=$(root_dir)/src:$(root_dir)/tests python -m unittest discover tests

deb:
	dpkg-buildpackage

define deploy-package
	echo "\033[92m***\033[0m Deploying to $1";
	ssh root@$1 "apt-get update && apt-get install -y gcm-sysmon-collector=$(VERSION) || apt-get -yf install";
endef

deploy:
	$(foreach host,$(DEPLOY_DESTS),$(call deploy-package,$(host)))

clean:
	find . -iname *.pyc -exec rm -f \{\} \;

.PHONY: all install deb deploy check clean
