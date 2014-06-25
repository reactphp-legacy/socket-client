<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\Promise;
use React\Promise\Deferred;

class Connector implements ConnectorInterface
{
    private $loop;
    private $resolver;

    public function __construct(LoopInterface $loop, Resolver $resolver)
    {
        $this->loop = $loop;
        $this->resolver = $resolver;
    }

    public function create($address)
    {
        $parts = $this->splitSocketAddress($address);

        return $this
            ->resolveHostname($parts['host'])
            ->then(function($host) use ($parts) {
                $parts['host'] = $host;
                $address = $this->joinSocketAddress($parts);

                return $this->createSocketForAddress($address);
            });
    }

    public function createSocketForAddress($address)
    {
        $socket = stream_socket_client($address, $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

        if (!$socket) {
            return Promise\reject(new \RuntimeException(
                sprintf("connection to %s failed: %s", $address, $errstr),
                $errno
            ));
        }

        stream_set_blocking($socket, 0);

        // wait for connection

        return $this
            ->waitForStreamOnce($socket)
            ->then(array($this, 'checkConnectedSocket'))
            ->then(array($this, 'handleConnectedSocket'));
    }

    protected function waitForStreamOnce($stream)
    {
        $deferred = new Deferred();

        $loop = $this->loop;

        $this->loop->addWriteStream($stream, function ($stream) use ($loop, $deferred) {
            $loop->removeWriteStream($stream);

            $deferred->resolve($stream);
        });

        return $deferred->promise();
    }

    public function checkConnectedSocket($socket)
    {
        // The following hack looks like the only way to
        // detect connection refused errors with PHP's stream sockets.
        if (false === stream_socket_get_name($socket, true)) {
            return Promise\reject(new ConnectionException('Connection refused'));
        }

        return Promise\resolve($socket);
    }

    public function handleConnectedSocket($socket)
    {
        return new Stream($socket, $this->loop);
    }

    protected function splitSocketAddress($address)
    {
        if (false === strpos($address, '://')) {
            $address = 'tcp://' . $address;
        }

        else if (0 === strpos($address, 'unix://')) {
            $address = str_replace('unix://', 'file://', $address);
            $isUnix = true;
        }

        $scheme = parse_url($address, PHP_URL_SCHEME);
        $host = trim(parse_url($address, PHP_URL_HOST), '[]');
        $port = parse_url($address, PHP_URL_PORT);
        $path = parse_url($address, PHP_URL_PATH);

        if (isset($isUnix)) {
            $scheme = 'unix';
        }

        if (null === $host) {
            $host = 'localhost';
        }

        return [
            'scheme'  => $scheme,
            'host'    => $host,
            'port'    => $port,
            'path'    => $path
        ];
    }

    protected function joinSocketAddress($parts)
    {
        $address = $parts['scheme'] . '://';

        // enclose IPv6 addresses in square brackets before appending port
        if (strpos($parts['host'], ':') !== false) {
            $parts['host'] = '[' . $parts['host'] . ']';
        }

        $address .= $parts['host'];

        if ($parts['port']) {
            $address .= ':' . $parts['port'];
        }

        $address .= $parts['path'];

        return $address;
    }

    protected function resolveHostname($host)
    {
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return Promise\resolve($host);
        }

        return $this->resolver->resolve($host);
    }
}
