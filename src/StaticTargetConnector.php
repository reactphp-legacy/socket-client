<?php

namespace React\SocketClient;

use React\SocketClient\ConnectorInterface;

/**
 * The host/port to connect to is set once during instantiation, the actual
 * target host/port is then ignored.
 */
class StaticTargetConnector implements ConnectorInterface
{
    private $connector;
    private $host;
    private $port;

    public function __construct(ConnectorInterface $connector, $host, $port)
    {
        $this->connector = $connector;
        $this->host = $host;
        $this->port = $port;
    }

    public function create($unusedHost, $unusedPort)
    {
        return $this->connector->create($this->host, $this->port);
    }
}
