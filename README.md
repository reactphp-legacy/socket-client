# SocketClient Component

[![Build Status](https://secure.travis-ci.org/reactphp/socket-client.png?branch=master)](http://travis-ci.org/reactphp/socket-client) [![Code Climate](https://codeclimate.com/github/reactphp/socket-client/badges/gpa.svg)](https://codeclimate.com/github/reactphp/socket-client)

Async Connector to open TCP/IP and SSL/TLS based connections.

## Introduction

Think of this library as an async version of
[`fsockopen()`](http://www.php.net/function.fsockopen) or
[`stream_socket_client()`](http://php.net/function.stream-socket-client).

Before you can actually transmit and receive data to/from a remote server, you
have to establish a connection to the remote end. Establishing this connection
through the internet/network takes some time as it requires several steps in
order to complete:

1. Resolve remote target hostname via DNS (+cache)
2. Complete TCP handshake (2 roundtrips) with remote target IP:port
3. Optionally enable SSL/TLS on the new resulting connection

## Usage

In order to use this project, you'll need the following react boilerplate code
to initialize the main loop.

```php
$loop = React\EventLoop\Factory::create();
```

### Async TCP/IP connections

The `React\SocketClient\TcpConnector` provides a single promise-based
`create($ip, $port)` method which resolves as soon as the connection
succeeds or fails.

```php
$tcpConnector = new React\SocketClient\TcpConnector($loop);

$tcpConnector->create('127.0.0.1', 80)->then(function (React\Stream\Stream $stream) {
    $stream->write('...');
    $stream->end();
});

$loop->run();
```

Note that this class only allows you to connect to IP/port combinations.
If you want to connect to hostname/port combinations, see also the following chapter.

### DNS resolution

The `DnsConnector` class decorates a given `TcpConnector` instance by first
looking up the given domain name and then establishing the underlying TCP/IP
connection to the resolved IP address.

It provides the same promise-based `create($host, $port)` method which resolves with
a `Stream` instance that can be used just like above.

Make sure to set up your DNS resolver and underlying TCP connector like this:

```php
$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$dnsConnector = new React\SocketClient\DnsConnector($tcpConnector, $dns);

$dnsConnector->create('www.google.com', 80)->then(function (React\Stream\Stream $stream) {
    $stream->write('...');
    $stream->end();
});

$loop->run();
```

The legacy `Connector` class can be used for backwards-compatiblity reasons.
It works very much like the newer `DnsConnector` but instead has to be
set up like this:

```php
$connector = new React\SocketClient\Connector($loop, $dns);

$connector->create('www.google.com', 80)->then($callback);
```

### Async SSL/TLS connections

The `SecureConnector` class decorates a given `Connector` instance by enabling
SSL/TLS encryption as soon as the raw TCP/IP connection succeeds.

It provides the same promise- based `create($host, $port)` method which resolves with
a `Stream` instance that can be used just like any non-encrypted stream:

```php
$secureConnector = new React\SocketClient\SecureConnector($dnsConnector, $loop);

$secureConnector->create('www.google.com', 443)->then(function (React\Stream\Stream $stream) {
    $stream->write("GET / HTTP/1.0\r\nHost: www.google.com\r\n\r\n");
    ...
});

$loop->run();
```

The `withContext(array $context)` method can be used to return a
new `SecureConnector` instance with the given
[additional TLS/SSL context options](http://php.net/manual/en/context.ssl.php) applied.
For example, this can be used to disable peer verification in a trusted network:
 
```php
$secureConnector->withContext(array(
    'verify_peer' => false,
    'verify_peer_name' => false,
))->create('intranet.example.com', 443)->then($callback);
```

### Unix domain sockets

Similarly, the `UnixConnector` class can be used to connect to Unix domain socket (UDS)
paths like this:

```php
$connector = new React\SocketClient\UnixConnector($loop);

$connector->create('/tmp/demo.sock')->then(function (React\Stream\Stream $stream) {
    $stream->write("HELLO\n");
});

$loop->run();
```
