<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

require 'guess_ip.php';

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
if ( !isset( $_SERVER['HTTP_X_GITHUB_EVENT'] ) || $_SERVER['HTTP_X_GITHUB_EVENT'] !== 'push' )
    $die( 3, 'ERROR: Not a GitHub push event: ' . PHP_EOL . print_r( $_SERVER, true ) . print_r( $_POST, true ) );

// Read and parse configuration:
$configFile = __DIR__ . '/config.ini';
if ( !is_file( $configFile ) )
    $die( 1, 'ERROR: Missing config file.' );
$config = parse_ini_file( $configFile, true );
if ( !$config || !isset( $config['smtp'] ) )
    $die( 2, 'ERROR: Invalid config file.' );

// Check and parse request payload:
if ( !isset( $_POST['payload'] ) )
    $die( 4, 'ERROR: No payload provided.' );
$json = $_POST['payload'];
$payload = json_decode( $json );
if ( !$payload )
    $die( 5, 'ERROR: Could not parse json: ' . $json );
if ( !isset( $payload->repository->full_name ) )
    $die( 6, 'ERROR: Event provided no repository full name.' );
$repoFull = $payload->repository->full_name;
if ( !isset( $config[$repoFull] ) )
    $die( 7, sprintf( 'ERROR: Repository "%s" not configured.', $repoFull ) );
if ( !isset( $payload->ref ) )
    $die( 8, sprintf( 'ERROR: Event provided no ref for repository "%s".', $repoFull ) );

// Extract reference properties.
$refraw = $payload->ref;
$refparts = explode( '/', $refraw );
$refType = $refparts[1] === 'tags' ? 'tag' : 'branch';
$refName = $refparts[count($refparts)-1];
if ( $payload->created == '1' )
    $refAction = 'created';
else if ( $payload->deleted == '1' )
    $refAction = 'deleted';
else if ( $payload->forced == '1' )
    $refAction = 'forced';
else
    $refAction = 'modified';
$refId = $refAction === 'deleted' ? $payload->before : $payload->after;
$refId7 = substr( $refId, 0, 7 );

$log( sprintf( 'Hook for "%s" %s "%s" %s (%s) triggered.',
                $repoFull, $refType, $refName, $refAction, $refId7 ) );

// Assemble email subject summary:
$summary = sprintf( '[%s] %s "%s" %s (%s)', $repoFull, $refType, $refName, $refAction, $refId7 );
if ( $refType !== 'tag' )
    $summary .= ' : ' . str_replace( array("\r\n","\r","\n"), " \\ ", $payload->head_commit->message );

// Assemble email message body:
$msg = '';
$msg .= sprintf( 'Repository : %s', $repoFull ) . PHP_EOL;
$msg .= sprintf( '             %s', $payload->repository->url ) . PHP_EOL;
$msg .= sprintf( 'Reference  : %s', $payload->ref ) . PHP_EOL;
$msg .= sprintf( 'Before     : %s', $payload->before ) . PHP_EOL;
$msg .= sprintf( 'After      : %s', $payload->after ) . PHP_EOL;
if ( $payload->head_commit ) {
    $msg .= sprintf( 'Head commit: %s', $payload->head_commit->id ) . PHP_EOL;
    $msg .= sprintf( '             %s', $payload->head_commit->url ) . PHP_EOL;
}
$msg .= sprintf( 'Pusher     : %s <%s>', $payload->pusher->name,
                                         $payload->pusher->email ). PHP_EOL;
$msg .= sprintf( 'Date       : %s', date( 'c', $payload->repository->pushed_at ) ). PHP_EOL;
$msg .= PHP_EOL;

$msg .= sprintf( 'Event      : %s "%s" %s', $refType, $refName, $refAction ) . PHP_EOL;
if ( $refType === 'tag' ) {
    if ( $refAction !== 'deleted' ) {
        $msg .= sprintf( 'Tag URL    : %s/releases/tag/%s',
                         $payload->repository->url, $refName ) . PHP_EOL;
        if ( $refAction !== 'forced' )
            $msg .= sprintf( 'Compare    : %s', $payload->compare ) . PHP_EOL;
    }
    $msg .= PHP_EOL;
} else {
    $msg .= sprintf( 'Tree URL   : %s/tree/%s',
                    $payload->repository->url, $refName ) . PHP_EOL;
    $msg .= sprintf( 'Compare    : %s', $payload->compare ) . PHP_EOL;
    $msg .= PHP_EOL;
    foreach ( $payload->commits as $commit ) {
        $msg .= PHP_EOL;
        $msg .= sprintf( 'Commit: %s', $commit->id ) . PHP_EOL;
        $msg .= sprintf( '        %s', $commit->url ) . PHP_EOL;
        $msg .= sprintf( 'Author: %s <%s>', $commit->author->name,
                                            $commit->author->email ) . PHP_EOL;
        $msg .= sprintf( 'Date  : %s', $commit->timestamp ) . PHP_EOL;
        $msg .= PHP_EOL;
        $msg .= 'Log message:' . PHP_EOL;
        $mline = strtok( $commit->message, "\r\n" );
        while ( $mline !== false ) {
            $msg .= '  ' . $mline . PHP_EOL;
            $mline = strtok( "\r\n" );
        }
        $msg .= PHP_EOL;
        $msg .= 'Changed paths:' . PHP_EOL;
        foreach ( $commit->added as $chg )
            $msg .= sprintf( '  A %s', $chg ) . PHP_EOL;
        foreach ( $commit->modified as $chg )
            $msg .= sprintf( '  M %s', $chg ) . PHP_EOL;
        foreach ( $commit->removed as $chg )
            $msg .= sprintf( '  D %s', $chg ) . PHP_EOL;
        $msg .= PHP_EOL;
    }
}
$msg .= PHP_EOL . '-- ' . PHP_EOL . $config['smtp']['from_name'] . PHP_EOL;

// Send notification email:
try {
    $mailer = new PHPMailer;
    $mailer->isSMTP();
    //$mailer->SMTPDebug = 3;
    $mailer->Host = $config['smtp']['host'];
    $mailer->Port = $config['smtp']['port'];
    $mailer->SMTPAuth = true;
    $mailer->AuthType = $config['smtp']['auth'];   // CRAM-MD5, LOGIN, PLAIN, XOAUTH2, attempted in that order if not specified.
    $mailer->Username = $config['smtp']['user'];
    $mailer->Password = $config['smtp']['passwd'];
    $mailer->SMTPSecure = '';      // '', 'SSL', 'TLS'
    $mailer->SMTPAutoTLS = false;
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom( $config['smtp']['from_email'], $config['smtp']['from_name'] );
    $mailer->Subject = $summary;
    $mailer->Body = $msg;
    $recipients = explode( ',', $config[$repoFull]['notify'] );
    foreach ( $recipients as $email ) {
        $log( 'Adding recipient: ' . $email );
        $mailer->addAddress( $email, '' );
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
