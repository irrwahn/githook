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
notifications, optionally check the request payload signature and extract
relevant information from the POST request json payload and forward it as
formatted email to the recipients configured for the respective repository.

Although originally written to only handle the `push` event, it can be
configured to send emails containing pretty-printed payload data for all
other types of events. Moreover, GitHook can easily be extended without
introducing changes to the core logic simply by providing additional
scripts. These scripts are automatically executed for a given event, if
they are named following the scheme `evt_eventname.php` and declare a
function named `evt_event()`. See `evt_push.php` and `evt_default.php`
to get an idea of how this works.

Note:
GitHook uses the third party [PHPMailer](https://github.com/PHPMailer/PHPMailer)
module for SMTP mail transport, as the PHP built-in `mail()` function is
somewhat limited and e.g. will not work on simple web-space installations,
where no mail transport agent (MTA) is configured.


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
        forward_all = 1
        secret = "my_github_webhook_secret"

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

3. In GitHub, add new webhooks for e.g. `my_repo_1` and `my_repo_2`, enter
   the Payload URL, e.g. `https://<your_domain_and_path>/githook/hook.php`
   and set the content type to `application/x-www-form-urlencoded`. Set
   the secret to the same value as in the GitHook configuration file.
   Finally, select the events you wish to receive notifications for and
   save the settings.

   **NOTE:** Upon webhook creation, GitHub will send out a `ping` event
   to your payload URL. This event will only be processed and forwarded
   by GitHook, if `forward_all` is enabled in the configuration.


## License

GitHook is distributed under the Modified ("3-clause") BSD License.
See `LICENSE` file for more information.

**Note:** [PHPMailer](https://github.com/PHPMailer/PHPMailer),
which is used as a submodule, comes with its own separate
[license](https://github.com/PHPMailer/PHPMailer/blob/master/LICENSE).

----------------------------------------------------------------------
