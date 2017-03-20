# SYSmon

Tool for monitoring system status of Linux servers. Currently the support is only for dpkg-based systems, such as Debian and Ubuntu.

***WARNING: SYSmon is still very much work in progress. It does not have most of the planned features. Stay tuned for new releases.***

# Features

- Collect basic information about Linux system
- Collect list of packages and their versions
- Logs history of changed attributes and packages
- Supports monitoring of periodic processes
- Support periodic chcecks of various metrics with charting and alerting.
- Sends XMPP notifications when host did not updated it's status in a while.

The list of features is not final, new features are added on per-demand basis.

# Installation

## Website
- Create empty database in the MySQL server.
- Import db/initial.sql and all increments there to the database.
- Edit config file `website/lib/config.php.example`. When you are done with it, rename the file to `config.php`.
- Insert initial user account into the database, use SHA256 hash of salt + password as password.
- Point your webserver to the `website` directory.

## Collector
- Edit collector/etc/sysmon.cfg, there is `server_address` option. Point it to your website installation address.
- Compile debian packages in the collector directory by either calling `make deb` or manually using `dpkg-buildpackage`.
- Distribute created packages to your servers.
- There is one metapackage, `gcm-sysmon-collector`, which has dependencies for all other packages, if you want to install complete suite. You can also install individual packages. `gcm-sysmon-collector-core` is base required package for anything to work. Then, you can install individual checks located in the `gcm-sysmon-check-*` packages.
- Now you can open your browser and point it to your sysmon installation. The servers should appear in a few minutes.
