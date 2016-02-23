<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;

class SecureConnector implements ConnectorInterface
{
    private $connector;
    private $streamEncryption;
    private $context = array();

    public function __construct(ConnectorInterface $connector, LoopInterface $loop)
    {
        $this->connector = $connector;
        $this->streamEncryption = new StreamEncryption($loop);
    }

    /**
     * sets additional context options (for SSL context wrapper)
     *
     * @param array $sslContextOptions assosiative array of additional context options
     * @return self returns a new instance with the additional context options applied
     * @link http://php.net/manual/en/context.ssl.php
     */
    public function withContext(array $sslContextOptions)
    {
        $connector = clone $this;
        $connector->context = array_filter($sslContextOptions + $connector->context, function ($value) {
            return ($value !== null);
        });

        return $connector;
    }

    public function create($host, $port)
    {
        // merge explicit context options with default context
        $context = $this->context + array(
            'SNI_enabled' => true,
            'SNI_server_name' => $host,
            'peer_name' => $host
        );

        return $this->connector->create($host, $port)->then(function (Stream $stream) use ($context) {
            // (unencrypted) TCP/IP connection succeeded

            // set required SSL/TLS context options
            stream_context_set_option($stream->stream, array('ssl' => $context));

            // try to enable encryption
            return $this->streamEncryption->enable($stream)->then(null, function ($error) use ($stream) {
                // establishing encryption failed => close invalid connection and return error
                $stream->close();
                throw $error;
            });
        });
    }
}
