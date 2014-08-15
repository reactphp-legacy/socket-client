<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;

class SecureConnector implements ConnectorInterface
{
    private $connector;
    private $streamEncryption;

    public function __construct(ConnectorInterface $connector, LoopInterface $loop)
    {
        $this->connector = $connector;
        $this->streamEncryption = new StreamEncryption($loop);
    }

    public function create($host, $port)
    {
        return $this->connector->create($host, $port)->then(function (Stream $stream) use($host) {
            // (unencrypted) connection succeeded

            // since DNS is resolved before creating the socket, PHP expects the cert name to match the resolved IP
            // instead of the DNS name, so tell it to expect the name instead
            stream_context_set_option($stream->stream, 'ssl', 'peer_name', $host);

            // try to enable encryption
            return $this->streamEncryption->enable($stream)->then(null, function ($error) use ($stream) {
                // establishing encryption failed => close invalid connection and return error
                $stream->close();
                throw $error;
            });
        });
    }
}
