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
    private $peerNameCtxKey;

    public function __construct(LoopInterface $loop, Resolver $resolver)
    {
        $this->loop = $loop;
        $this->resolver = $resolver;
        $this->peerNameCtxKey = PHP_VERSION_ID < 50600 ? 'CN_match' : 'peer_name';
    }

    /**
     * We need to set various context options related to the expected SSL certificate name here even though we
     * don't know whether we are creating an SSL connection or not, for compatibility with PHP<5.6.
     *
     * We don't specifically enable verify_peer or verify_peer_name here because these may require additional
     * options such as specifying a cafile etc, which means the user will need to do this manually.
     *
     * @param array  $contextOpts
     * @param string $host
     * @return array
     */
    private function normalizeSSLContextOptions(array $contextOpts, $host)
    {
        // Allow the user to override the certificate peer name with the context option, unless it's a wildcard
        if (isset($contextOpts['ssl']['peer_name']) && false === strpos($contextOpts['ssl']['peer_name'], '*')) {
            $host = $contextOpts['ssl']['peer_name'];
        } else if (isset($contextOpts['ssl']['CN_match']) && false === strpos($contextOpts['ssl']['CN_match'], '*')) {
            $host = $contextOpts['ssl']['CN_match'];
        }

        // Make sure that SNI requests the correct certificate name
        if (!isset($contextOpts['ssl']['SNI_enabled'])
            || $contextOpts['ssl']['SNI_enabled'] && !isset($contextOpts['ssl']['SNI_server_name'])) {
            $contextOpts['ssl']['SNI_enabled'] = true;
            $contextOpts['ssl']['SNI_server_name'] = $host;
        }

        // Make sure PHP verifies the certificate name against the requested name
        if (!isset($contextOpts['ssl'][$this->peerNameCtxKey])) {
            $contextOpts['ssl'][$this->peerNameCtxKey] = $host;
        }

        // Disable TLS compression by default
        if (!isset($contextOpts['ssl']['disable_compression'])) {
            $contextOpts['ssl']['disable_compression'] = true;
        }

        return $contextOpts;
    }

    public function create($host, $port, array $contextOpts = [])
    {
        return $this
            ->resolveHostname($host)
            ->then(function ($address) use ($port, $host, $contextOpts) {
                $contextOpts = $this->normalizeSSLContextOptions($contextOpts, $host);
                return $this->createSocketForAddress($address, $port, $contextOpts);
            });
    }

    public function createSocketForAddress($address, $port, array $contextOpts = [])
    {
        $url = $this->getSocketUrl($address, $port);

        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
        $context = stream_context_create($contextOpts);
        $socket = stream_socket_client($url, $errno, $errstr, 0, $flags, $context);

        if (!$socket) {
            return Promise\reject(new \RuntimeException(
                sprintf("connection to %s:%d failed: %s", $address, $port, $errstr),
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
