# php-wcfg-router
Have a PHP web application project written in Windows that heavily depended on rules configured with the IIS web server, but have to be tested in a macOS or Linux environment? You're in the right place.

**⚠️ PLEASE DO NOT USE IT IN PRODUCTION, ONLY DEVELOPMENT!**

# What is it?
The `router.php` file in this repository is a PHP router file to parse web.config rules for the PHP built-in web server, for development usage in temporarily running PHP web projects written for IIS web server to run in anywhere, including macOS/Linux.

It should support most common directives in `web.config`. If not, feel free to fork your own or contribute.

# How to use it?
To use it, simply `cd` into your project folder containing the `web.config` file and start the PHP built-in web server.
```
windows_php_webapp/
├─ xxxxx.php
├─ xxxxx.php
├─ router.php
├─ web.config
```
```
$ php -S 0.0.0.0:80 router.php

PHP 7.1.23 Development Server started at Sat Dec 18 12:34:56 2021
Listening on http://0.0.0.0:80
Document root is /home/nrockhouse/windows_php_webapp
Press Ctrl-C to quit.
```
Read the official documentation for [PHP built-in web server](https://www.php.net/manual/en/features.commandline.webserver.php) for other command line arguments.

# Acknowledgements
Special thanks to my boss at [Radica Software](https://radicasoftware.com/) for giving me the permission to release it. This router script is written for the company during my internship period.
