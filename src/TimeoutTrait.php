<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;

trait TimeoutTrait
{
    protected function setTimeout(LoopInterface $loop, Deferred $defer, $seconds = 30) {
        $seconds = (int)$seconds;

        $timer = $loop->addTimer($seconds, function() use ($defer, $seconds) {
            $defer->reject(new \RuntimeException("Timeout: failed to connected after {$seconds} seconds"));
        });

        $defer->promise()->then(function() use ($timer) {
            $timer->cancel();
        });
    }

}
