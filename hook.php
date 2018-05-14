<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

require 'guess_ip.php';
require 'evt_push.php';
require 'evt_default.php';

// Create a silent dummy session for internal (logging ID) use only:
ini_set( "session.use_cookies", 0 );
session_start();
header_remove();

// Logging and termination facilities:
$logid = session_id();
$logfp = fopen( 'hook.log', 'a' ) or $logfp = STDERR;
$log = function ( $msg ) use ( $logfp, $logid ) {
    $now = new DateTime();
    fputs( $logfp, sprintf( "[%s] [%s] %s", $now->format('Y-m-d H:i:s'), $logid, $msg . PHP_EOL ) );
};
$die = function ( $code, $msg ) use ( $log ) {
    $log( $msg );
    session_destroy();
    exit( $code );
};

// Log connection info:
$remoteIP = determineIP();
if ( $remoteIP !== $_SERVER['REMOTE_ADDR'] )
    $log( 'Connection from ' . $_SERVER['REMOTE_ADDR'] . ' for ' . $remoteIP );
else
    $log( 'Connection from ' . $_SERVER['REMOTE_ADDR'] );

// Check request header:
if ( empty( $_SERVER['HTTP_X_GITHUB_EVENT'] ) )
    $die( 3, 'ERROR: Not a GitHub event: ' . PHP_EOL . print_r( $_SERVER, true ) . print_r( $_POST, true ) );
$event = $_SERVER['HTTP_X_GITHUB_EVENT'];

// Read and parse configuration:
$configFile = __DIR__ . '/config.ini';
if ( !is_file( $configFile ) )
    $die( 1, 'ERROR: Missing config file.' );
$config = parse_ini_file( $configFile, true );
if ( !$config || !isset( $config['smtp'] ) )
    $die( 2, 'ERROR: Invalid config file.' );

// Check and parse request payload:
if ( empty( $_POST['payload'] ) )
    $die( 4, 'ERROR: No payload provided.' );
$json = $_POST['payload'];
$payload = json_decode( $json );
if ( !$payload )
    $die( 5, 'ERROR: Could not parse json: ' . $json );
if ( empty( $payload->repository->full_name ) )
    $die( 6, 'ERROR: Event provided no repository full name.' );
$repoName = $payload->repository->full_name;
if ( empty( $config[$repoName] ) )
    $die( 7, sprintf( 'ERROR: Repository "%s" not configured.', $repoName ) );
$log( sprintf( 'Processing hook for "%s" event "%s".', $repoName, $event ) );

// Invoke appropriate formatter for event and check result:
$message = [ 'subject' => '', 'body' => '', 'errno' => 0, 'errmsg' => '' ];
if ( $event === 'push' )
    evt_push( $event, $payload, $config, $message );
else
    evt_default( $event, $payload, $config, $message );

if ( !empty( $message['errno'] ) )
    $die( $message['errno'], $message['errmsg'] );
if ( empty( $message['subject'] ) && empty( $message['body'] ) )
    $die( 9, 'ERROR: empty message subject and body, not sending email.' );

// Send notification email:
$message['body'] .= PHP_EOL . '-- ' . PHP_EOL . $config['smtp']['from_name'] . PHP_EOL;
try {
    $mailer = new PHPMailer;
    $mailer->isSMTP();
    //$mailer->SMTPDebug = 3;
    $mailer->Host = $config['smtp']['host'];
    $mailer->Port = $config['smtp']['port'];
    $mailer->SMTPSecure = $config['smtp']['security'];  // '' | 'SSL' | 'TLS'
    $mailer->SMTPAutoTLS = false;
    $mailer->SMTPAuth = !empty( $config['smtp']['auth'] );
    $mailer->AuthType = $config['smtp']['auth'];        // '' | 'CRAM-MD5' | 'LOGIN' | 'PLAIN' | 'XOAUTH2'
    $mailer->Username = $config['smtp']['user'];
    $mailer->Password = $config['smtp']['passwd'];
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom( $config['smtp']['from_email'], $config['smtp']['from_name'] );
    $mailer->Subject = $message['subject'];
    $mailer->Body = $message['body'];
    $recipients = explode( ',', $config[$repoName]['notify'] );
    foreach ( $recipients as $to ) {
        $log( 'Adding recipient: ' . $to );
        $mailer->addAddress( $to, '' );
    }
    if ( !$mailer->send() )
        $die( 101, 'ERROR: Sending email failed: ' . $mailer->ErrorInfo );
    $log( sprintf( 'Email sent: "%s"', $mailer->Subject ) );
} catch ( Exception $e ) {
    $die( 102, 'ERROR: PHPMailer exception: %s' . $e->errorMessage() );
} catch ( \Exception $e ) {
    $die( 103, 'ERROR: General exception: %s' . $e->getMessage() );
}

// We're done, all good:
$die( 0, 'Hook processing successfully completed.' );
?>
