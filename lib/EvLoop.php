<?php

namespace Amp\Loop;

use Amp\Loop\Internal\Watcher;

class EvLoop extends Loop {
    /**
     * @var \EvLoop
     */
    private $handle;

    /**
     * @var \EvWatcher[]
     */
    private $events = [];

    /**
     * @var callable
     */
    private $ioCallback;

    /**
     * @var callable
     */
    private $timerCallback;
    
    /**
     * @var callable
     */
    private $signalCallback;
    
    /**
     * @var \EvSignal[]
     */
    private $signals = [];
    
    /**
     * @var \EvSignal[]|null
     */
    private static $activeSignals;

    public static function supported() {
        return \extension_loaded("ev");
    }

    public function __construct() {
        $this->handle = new \EvLoop;
        
        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function (\EvIO $event) {
            /** @var \Amp\Loop\Internal\Watcher $watcher */
            $watcher = $event->data;

            $callback = $watcher->callback;
            $callback($watcher->id, $watcher->value, $watcher->data);
        };

        $this->timerCallback = function (\EvTimer $event) {
            /** @var \Amp\Loop\Internal\Watcher $watcher */
            $watcher = $event->data;

            if ($watcher->type & Watcher::DELAY) {
                $this->cancel($watcher->id);
            }

            $callback = $watcher->callback;
            $callback($watcher->id, $watcher->data);
        };

        $this->signalCallback = function (\EvSignal $event) {
            /** @var \Amp\Loop\Internal\Watcher $watcher */
            $watcher = $event->data;

            $callback = $watcher->callback;
            $callback($watcher->id, $watcher->value, $watcher->data);
        };
    }

    public function __destruct() {
        foreach ($this->events as $event) {
            $event->stop();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run() {
        $active = self::$activeSignals;
        
        foreach ($active as $event) {
            $event->stop();
        }
        
        self::$activeSignals = &$this->signals;
        
        foreach ($this->signals as $event) {
            $event->start();
        }
        
        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->stop();
            }
            
            self::$activeSignals = &$active;
            
            foreach ($active as $event) {
                $event->start();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop() {
        $this->handle->stop();
        parent::stop();
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch($blocking) {
        $this->handle->run($blocking ? \Ev::RUN_ONCE : \Ev::RUN_ONCE | \Ev::RUN_NOWAIT);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $watchers) {
        foreach ($watchers as $watcher) {
            if (!isset($this->events[$id = $watcher->id])) {
                switch ($watcher->type) {
                    case Watcher::READABLE:
                        $this->events[$id] = $this->handle->io($watcher->value, \Ev::READ, $this->ioCallback, $watcher);
                        break;

                    case Watcher::WRITABLE:
                        $this->events[$id] = $this->handle->io($watcher->value, \Ev::WRITE, $this->ioCallback, $watcher);
                        break;

                    case Watcher::DELAY:
                    case Watcher::REPEAT:
                        $interval = $watcher->value / self::MILLISEC_PER_SEC;
                        $this->events[$id] = $this->handle->timer(
                            $interval,
                            $watcher->type & Watcher::REPEAT ? $interval : 0,
                            $this->timerCallback,
                            $watcher
                        );
                        break;

                    case Watcher::SIGNAL:
                        $this->events[$id] = $this->handle->signal($watcher->value, $this->signalCallback, $watcher);
                        break;

                    default:
                        throw new \DomainException("Unknown watcher type");
                }
            } else {
                $this->events[$id]->start();
            }

            if ($watcher->type === Watcher::SIGNAL) {
                $this->signals[$id] = $this->events[$id];
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function deactivate(Watcher $watcher) {
        if (isset($this->events[$id = $watcher->id])) {
            $this->events[$id]->stop();
            if ($watcher->type === Watcher::SIGNAL) {
                unset($this->signals[$id]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cancel($watcherIdentifier) {
        parent::cancel($watcherIdentifier);
        unset($this->events[$watcherIdentifier]);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getHandle() {
        return $this->handle;
    }
}
