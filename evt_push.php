<?php

// PUSH event handler:

function evt_push( $event, $payload, $config, &$message ) {
    $repoName = $payload->repository->full_name;

    // Check for reference:
    if ( empty( $payload->ref ) )
    {
        $message['errno'] = 10;
        $message['errmsg'] = sprintf( 'ERROR: Push event provided no ref for repository "%s".', $repoName );
        return false;
    }

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

    // Assemble email message subject:
    $subject = sprintf( '[%s] %s "%s" %s (%s)', $repoName, $refType, $refName, $refAction, $refId7 );
    if ( $refType !== 'tag' )
        $subject .= ' : ' . str_replace( array("\r\n","\r","\n"), " \\ ", $payload->head_commit->message );

    // Assemble email message body:
    $body = '';
    $body .= sprintf( 'Repository : %s', $repoName ) . PHP_EOL;
    $body .= sprintf( '             %s', $payload->repository->url ) . PHP_EOL;
    $body .= sprintf( 'Reference  : %s', $payload->ref ) . PHP_EOL;
    $body .= sprintf( 'Before     : %s', $payload->before ) . PHP_EOL;
    $body .= sprintf( 'After      : %s', $payload->after ) . PHP_EOL;
    if ( $payload->head_commit ) {
        $body .= sprintf( 'Head commit: %s', $payload->head_commit->id ) . PHP_EOL;
        $body .= sprintf( '             %s', $payload->head_commit->url ) . PHP_EOL;
    }
    $body .= sprintf( 'Pusher     : %s <%s>', $payload->pusher->name,
                                             $payload->pusher->email ). PHP_EOL;
    $body .= sprintf( 'Date       : %s', date( 'c', $payload->repository->pushed_at ) ). PHP_EOL;
    $body .= PHP_EOL;

    $body .= sprintf( 'Event      : %s "%s" %s', $refType, $refName, $refAction ) . PHP_EOL;
    if ( $refType === 'tag' ) {
        if ( $refAction !== 'deleted' ) {
            $body .= sprintf( 'Tag URL    : %s/releases/tag/%s',
                             $payload->repository->url, $refName ) . PHP_EOL;
            if ( $refAction !== 'forced' )
                $body .= sprintf( 'Compare    : %s', $payload->compare ) . PHP_EOL;
        }
        $body .= PHP_EOL;
    } else {
        $body .= sprintf( 'Tree URL   : %s/tree/%s',
                        $payload->repository->url, $refName ) . PHP_EOL;
        $body .= sprintf( 'Compare    : %s', $payload->compare ) . PHP_EOL;
        $body .= PHP_EOL;
        foreach ( $payload->commits as $commit ) {
            $body .= PHP_EOL;
            $body .= sprintf( 'Commit: %s', $commit->id ) . PHP_EOL;
            $body .= sprintf( '        %s', $commit->url ) . PHP_EOL;
            $body .= sprintf( 'Author: %s <%s>', $commit->author->name,
                                                $commit->author->email ) . PHP_EOL;
            $body .= sprintf( 'Date  : %s', $commit->timestamp ) . PHP_EOL;
            $body .= PHP_EOL;
            $body .= 'Log message:' . PHP_EOL;
            $mline = strtok( $commit->message, "\r\n" );
            while ( $mline !== false ) {
                $body .= '  ' . $mline . PHP_EOL;
                $mline = strtok( "\r\n" );
            }
            $body .= PHP_EOL;
            $body .= 'Changed paths:' . PHP_EOL;
            foreach ( $commit->added as $chg )
                $body .= sprintf( '  A %s', $chg ) . PHP_EOL;
            foreach ( $commit->modified as $chg )
                $body .= sprintf( '  M %s', $chg ) . PHP_EOL;
            foreach ( $commit->removed as $chg )
                $body .= sprintf( '  D %s', $chg ) . PHP_EOL;
            $body .= PHP_EOL;
        }
    }

    $message['subject'] = $subject;
    $message['body'] = $body;
    $message['errno'] = 0;
    $message['errmsg'] = '';
    return true;
}

?>
