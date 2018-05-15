<?php

// Default event handler:

function evt_default( $event, $payload, $config, &$message ) {
    $repoName = $payload->repository->full_name;

    if ( empty( $config['general']['forward_all'] ) ) {
        $message['errno'] = 8;
        $message['errmsg'] = sprintf( 'STOP: Not forwarding events of type "%s".', $event );
        return false;
    }

    $message['subject'] = sprintf( '[%s] received event "%s"', $repoName, $event );
    $message['body'] = 'X-GitHub-Event: ' . $event . PHP_EOL . print_r( $payload, true );
    $message['errno'] = 0;
    $message['errmsg'] = '';
    return true;
}

?>
