<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

require __DIR__ . '/guess_ip.php';

// Read and parse configuration:
$configFile = __DIR__ . '/githook.ini';
if ( !is_readable( $configFile ) )
{
    $configFile .= '.php';
    if ( !is_readable( $configFile ) )
    {
        error_log( '['. __FILE__ . '] ERROR: Missing or inaccessible config file.' );
        die( 1 );
    }
}
$config = parse_ini_file( $configFile, true );
if ( !$config || !isset( $config['smtp'] ) )
{
    error_log( '['. __FILE__ . '] ERROR: Invalid config file "' . $configFile . '".' );
    die( 2 );
}

// Logging and termination facilities:
ini_set( "session.use_cookies", 0 );
session_start();    // Silent dummy session for logging purposes only.
header_remove();
$logId = session_id();
$logFp = empty( $config['general']['logfile'] ) ? false
        : fopen( $config['general']['logfile'], 'a' );
if ( !$logFp )
    $logFp = fopen( 'php://stderr', 'w' );
$log = function ( $msg ) use ( $logFp, $logId ) {
    $now = new DateTime();
    fputs( $logFp, sprintf( "[%s] [%s] %s", $now->format('Y-m-d H:i:s'), $logId, $msg . PHP_EOL ) );
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

// Invoke appropriate event handler and check result:
$eventHandler = 'evt_' . $event;
$eventHandlerModule = __DIR__ . '/' . $eventHandler . '.php';
if ( !is_readable( $eventHandlerModule )
    || !(include $eventHandlerModule)
    || !is_callable( $eventHandler ) ) {
    require __DIR__ . '/evt_default.php';
    $eventHandler = 'evt_default';
}
$message = [ 'subject' => '', 'body' => '', 'errno' => 0, 'errmsg' => '' ];
if ( $eventHandler( $event, $payload, $config, $message ) == false
    || !empty( $message['errno'] ) )
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
