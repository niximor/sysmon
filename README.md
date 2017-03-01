# SYSmon

Tool for monitoring system status of Linux servers. Currently the support is only for dpkg-based systems, such as Debian and Ubuntu.

***WARNING: SYSmon is still very much work in progress. It does not have most of the planned features. Stay tuned for new releases.***

# Features

- Collect basic information about Linux system
- Collect list of packages and their versions
- Logs history of changed attributes and packages
- Sends XMPP notifications when host did not updated it's status in a while.

The list of features is not final, new features are added on per-demand basis.

# Configuration

- Create empty database in the MySQL server.
- Import db/initial.sql and all increments there to the database.
- Edit config file `lib/config.php.example`. When you are done with it, rename the file to `config.php`.
- Edit collector/sysmon-collect.py, there is `SYSMON_ADDRESS` address variable at the top of the file. Point it to your installation address.
- Compile debian package gcm-sysmon-collector and distribute it to your servers.
- Now you can open your browser and point it to your sysmon installation. The servers should appear in a few minutes.
