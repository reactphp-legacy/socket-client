<?php

namespace React\Tests\SocketClient;

use React\EventLoop\Factory as LoopFactory;
use React\Socket\Server;
use React\Dns\Resolver\Factory as DnsFactory;
use React\SocketClient\Connector;
use React\SocketClient\SecureConnector;
use React\Stream\Stream;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

class SecureConnectorTest extends TestCase
{
    public function testA()
    {
        // TODO: peer verification disabled for now so we can test our self-signed certificate
        stream_context_set_default(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));

        $loop = LoopFactory::create();

        $receivedServer = '';
        $receivedClient = '';
        $connected = 0;

        $server = new Server($loop);
        $server->on('connection', function (Stream $stream) use (&$receivedServer, &$connected) {
            $connected++;

            // $stream->pipe($stream);
            $stream->on('data', function ($data) use ($stream, &$receivedServer) {
                $receivedServer .= $data;
                $stream->write($data);
            });
        });
        $server->listen(6000);

        $dnsResolverFactory = new DnsFactory();
        $resolver = $dnsResolverFactory->createCached('8.8.8.8', $loop);

        $connector = new Connector($loop, $resolver);

        // verify server is listening by creating an unencrypted connection once
        $promise = $connector->create('127.0.0.1', 6001);
        try {
            $client = $this->waitFor($promise, $loop);
            /* @var $client Stream */
            $client->close();
        } catch (\Exception $e) {
            $this->markTestSkipped('stunnel not reachable?');
        }

        $this->assertEquals(0, $connected);

        $secureConnector = new SecureConnector($connector, $loop);

        $promise = $secureConnector->create('127.0.0.1', 6001);
        //$promise = $connector->create('127.0.0.1', 6000);
        $client = $this->waitFor($promise, $loop);
        /* @var $client Stream */

        while (!$connected) {
            $loop->tick();
        }

        $client->on('data', function ($data) use (&$receivedClient) {
            $receivedClient .= $data;
        });

        $this->assertEquals('', $receivedServer);
        $this->assertEquals('', $receivedClient);

        $echo = function ($str) use (&$receivedClient, &$receivedClient, $loop, $client) {
            $receivedClient = $receivedServer = '';
            $client->write($str);
            while ($receivedClient !== $str) {
                $loop->tick();
            }
        };

        $echo('hello');

        $echo('world');

        // send a 10k message once to fill buffer (failing!)
        //$echo(str_repeat('1234567890', 10000));

        $echo('again');
    }

    private function waitFor(PromiseInterface $promise, LoopInterface $loop)
    {
        $ret = null;
        $promise->then(
            function ($value) use (&$ret) {
                $ret = $value;
            },
            function ($error) use (&$ret) {
                $ret = $error;
            }
        );

        while ($ret === null) {
            $loop->tick();
        }

        if ($ret instanceof \Exception) {
            throw $ret;
        }

        return $ret;
    }
}
