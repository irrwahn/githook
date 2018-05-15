# GitHook

GitHub webhook push event notification email gizmo.


## Purpose

GitHook strives to replicate the functionality provided by the deprecated
GitHub email notification service, which will be removed from GitHub.com
on January 31st, 2019.
(See [here](https://developer.github.com/changes/2018-04-25-github-services-deprecation/)
for more information.)


## Description

GitHook makes use of the GitHub "webhook" interface to receive push event
notifications, extract relevant information from the POST requests json
payload and forward it as formatted email to the recipients configured
for the respective repository.

Note: GitHook uses the third party [PHPMailer](https://github.com/PHPMailer/PHPMailer)
module for SMTP mail transport, as the PHP built-in `mail()` function is
somewhat limited and e.g. will not work on simple web-space installations,
where no mail transport agent (MTA) is configured.

As it was written to simply scratch one personal itch, in its current
state GitHook's configuration options are quite limited and a lot of
stuff is hard-coded. Please feel free to submit suggestions, fixes or
patches, if you think a particular issue or use case should be addressed.


## Installation

1. Clone the `githook` repository:

        git clone --recursive git@github.com:irrwahn/githook.git

   Note: The `--recursive` option tells git to also clone submodules,
   in this case the [PHPMailer](https://github.com/PHPMailer/PHPMailer)
   repository. With Git version 2.13 and later, `--recursive` has
   been deprecated and `--recurse-submodules` should be used instead:

        git clone --recurse-submodules git@github.com:irrwahn/githook.git


2. Make sure the `hook.php` script is accessible over the internet, so
   GitHub webhook events can be delivered to:
   `http[s]://<your_domain_and_path>/githook/hook.php`

   **NOTES:**

   * Please apply common sense and make sure that access via the web
   server is reasonably restricted. The only file that needs to be
   directly accessible is the `hook.php` script, outside access to
   everything else should be denied as e.g. suggested by the included
   `.htaccess` file. First and foremost you do not want anyone to see
   your configuration file, as it contains mail account credentials
   in plain text. See also the first note in section *Configuration*
   below.

   * If the script is configured to log events to a file, that file
   should be writable by the web server.


## Configuration

1. Copy the file `githook.ini.php.example` to `githook.ini.php`.

   **NOTE:** By default, the `hook.php` script will expect the ini file
   to reside in the same directory as the script itself. However, before
   parsing the ini file, a file named `preload.php`, if present, will be
   included by the script. This allows to override the ini file location
   and thus the actual githook configuration file to be placed outside
   the web server's document root subtree. An actual `preload.php` file
   might for example look like this:

        <?php
            $configFile = '/etc/githook.ini';
        ?>

   It is of course also possible to specify a path relative to the script
   directory, e.g.:

        <?php
            $configFile = '../../githook.ini';
        ?>

2. Edit the configuration file to accommodate your needs, e.g.:

        [general]
        logfile = "hook.log"

        [smtp]
        host = "smpthost.example.com"
        port = 465
        security = "SSL"
        auth = "LOGIN"
        user = "githook@example.com"
        passwd = "***secret***"
        from_email = "githook@example.com"
        from_name = "My GitHook Gizmo"

        [my_gihub_account/my_repo_1]
        notify = "recipient1@example.com,foo@invalid.none,bar@fizz.buzz"

        [my_gihub_account/my_repo_2]
        notify = "fred@example.org"

   **NOTE:** SSL/TLS secured mail transport may fail in unpredictable
   (and often hard to debug) ways, if there is a problem relating to the
   certificate, such as a bad, self-signed or expired certificate, or
   server redirection by the ISP, or out of date CA file on the PHP host.

3. In GitHub, add new webhooks for e.g. `my_repo_1` and `my_repo_2`,
   enter the Payload URL, e.g. `https://<your_domain_and_path>/githook/hook.php`
   and set the content type to `application/x-www-form-urlencoded`.
   Tick the `Just the push event` radio button and save the settings.

   **NOTE:** Upon webhook creation, GitHub will send a `ping` event to
   your payload URL. Those events are neither processed nor forwarded by
   `hook.php`, but will still be logged to `hook.log`.


## License

GitHook is distributed under the Modified ("3-clause") BSD License.
See `LICENSE` file for more information.

**Note:** [PHPMailer](https://github.com/PHPMailer/PHPMailer),
which is used as a submodule, comes with its own separate
[license](https://github.com/PHPMailer/PHPMailer/blob/master/LICENSE).

----------------------------------------------------------------------
