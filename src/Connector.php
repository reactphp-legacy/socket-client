<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\Promise;

class Connector implements ConnectorInterface
{
    use TimeoutTrait;

    private $loop;
    private $resolver;

    public function __construct(LoopInterface $loop, Resolver $resolver)
    {
        $this->loop = $loop;
        $this->resolver = $resolver;
    }

    public function create($host, $port)
    {
        return $this
            ->resolveHostname($host)
            ->then(function ($address) use ($port) {
                return $this->createSocketForAddress($address, $port);
            });
    }

    public function createSocketForAddress($address, $port, $timeout = 30)
    {
        $url = $this->getSocketUrl($address, $port);

        $socket = stream_socket_client($url, $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

        if (!$socket) {
            return Promise\reject(new \RuntimeException(
                sprintf("connection to %s:%d failed: %s", $address, $port, $errstr),
                $errno
            ));
        }

        stream_set_blocking($socket, 0);

        // wait for connection

        return $this
            ->waitForStreamOnce($socket, (int)$timeout)
            ->then(array($this, 'checkConnectedSocket'))
            ->then(array($this, 'handleConnectedSocket'));
    }

    protected function waitForStreamOnce($stream, $timeout)
    {
        $resolver = function(callable $resolve, callable $reject, callable $notify) use ($stream) {
            $this->loop->addWriteStream($stream, function($stream) use ($resolve) {
                $this->loop->removeWriteStream($stream);

                $resolve($stream);
            });
        };

        $canceller = function(callable $resonse, callable $reject, callable $progress) use ($stream, $timeout) {
            $this->loop->removeWriteStream($stream);

            stream_socket_shutdown($stream, STREAM_SHUT_RDWR);
            fclose($stream);

            $reject(new \RuntimeException("Timeout: failed to connect after {$timeout} seconds"));
        };

        $promise = new Promise\Promise($resolver, $canceller);

        $this->setTimeout($this->loop, $promise, $timeout);

        return $promise;
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

    protected function getSocketUrl($host, $port)
    {
        if (strpos($host, ':') !== false) {
            // enclose IPv6 addresses in square brackets before appending port
            $host = '[' . $host . ']';
        }
        return sprintf('tcp://%s:%s', $host, $port);
    }

    protected function resolveHostname($host)
    {
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return Promise\resolve($host);
        }

        return $this->resolver->resolve($host);
    }
}
