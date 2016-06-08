<?php

namespace Amp\Loop\Internal;

class Timer {
    /**
     * @var \Amp\Loop\Internal\Watcher
     */
    public $watcher;

    /**
     * @var int
     */
    public $expiration;
}
