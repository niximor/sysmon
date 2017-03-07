VERSION=$(strip $(shell dpkg-parsechangelog | grep Version: | cut -d':' -f2))
DEPLOY_DESTS=

all:


include Makefile.*

install:
	install -D src/sysmon-collect.py $(DESTDIR)/usr/bin/sysmon-collect.py
	install -D src/sysmon-checker.py $(DESTDIR)/usr/bin/sysmon-checker.py

deb:
	dpkg-buildpackage

define deploy-package
	ssh root@$1 "apt-get update && apt-get install -y gcm-sysmon-collector=$(VERSION)";
endef

deploy:
	$(foreach host,$(DEPLOY_DESTS),$(call deploy-package,$(host)))
