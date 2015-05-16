<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Promise\CancellablePromiseInterface;

trait TimeoutTrait
{
    protected function setTimeout(LoopInterface $loop, CancellablePromiseInterface $promise, $seconds = 30)
    {
        $seconds = (int)$seconds;

        $timer = $loop->addTimer($seconds, function() use ($promise, $seconds) {
            $promise->cancel();
        });

        $promise->then(function() use ($timer) {
            $timer->cancel();
        });
    }
}
