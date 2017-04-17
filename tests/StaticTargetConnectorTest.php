<?php

namespace React\Tests\SocketClient;

use React\SocketClient\StaticTargetConnector;
use React\Promise;

class StaticTargetConnectorTest extends TestCase
{
    public function testPassCtorArgsToUnderlyingConnector()
    {
        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())
                  ->method('create')
                  ->with($this->equalTo('proxy.example.com'), $this->equalTo(8080))
                  ->will($this->returnValue(Promise\reject(new \Exception())));

        $static = new StaticTargetConnector($connector, 'proxy.example.com', 8080);

        $promise = $static->create('reactphp.org', 80);

        $promise->then(null, $this->expectCallableOnce());
    }
}
