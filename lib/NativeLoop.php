<?php

namespace Amp\Loop;

use Amp\Loop\Internal\Timer;
use Amp\Loop\Internal\Watcher;
use Interop\Async\Loop\UnsupportedFeatureException;

class NativeLoop extends Loop {
    /**
     * @var resource[]
     */
    private $readStreams = [];

    /**
     * @var \Amp\Loop\Internal\Watcher[][]
     */
    private $readWatchers = [];

    /**
     * @var resource[]
     */
    private $writeStreams = [];

    /**
     * @var \Amp\Loop\Internal\Watcher[][]
     */
    private $writeWatchers = [];

    /**
     * @var int[]
     */
    private $timerExpires = [];

    /**
     * @var \Amp\Loop\Internal\Timer[]
     */
    private $timerHeap = [];

    /**
     * @var int
     */
    private $timerCount = 0;

    /**
     * @var \Amp\Loop\Internal\Watcher[][]
     */
    private $signalWatchers = [];

    /**
     * @var bool
     */
    private $signalHandling;

    public function __construct() {
        $this->signalHandling = \extension_loaded("pcntl");
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch($blocking) {
        $timeout = 0;

        if ($blocking) {
            if (!empty($this->timerHeap)) {
                $timeout = $this->timerHeap[0]->expiration - (int) (\microtime(true) * self::MILLISEC_PER_SEC);
                if ($timeout < 0) {
                    $timeout = 0;
                }
            } else {
                $timeout = -1;
            }
        }

        $this->selectStreams($this->readStreams, $this->writeStreams, $timeout);

        if (!empty($this->timerExpires)) {
            $time = (int) (\microtime(true) * self::MILLISEC_PER_SEC);

            while (!empty($this->timerHeap)) {
                $timer = $this->timerHeap[0];

                $watcher = $timer->watcher;
                $id = $watcher->id;

                if (isset($this->timerExpires[$id]) && $this->timerExpires[$id] > $time) {
                    break; // Timer at top of heap has not expired.
                }

                // Extract timer from heap.
                $this->timerHeap[0] = $this->timerHeap[--$this->timerCount];
                unset($this->timerHeap[$this->timerCount]);

                $node = 0;
                while (($child = ($node << 1) + 1) < $this->timerCount) {
                    if (($this->timerHeap[$child]->expiration < $this->timerHeap[$node]->expiration)
                        && ($child + 1 >= $this->timerCount
                            || $this->timerHeap[$child]->expiration < $this->timerHeap[$child + 1]->expiration
                        )
                    ) {
                        // Left child is greater than parent and greater than right child.
                        $temp = $this->timerHeap[$node];
                        $this->timerHeap[$node] = $this->timerHeap[$child];
                        $this->timerHeap[$child] = $temp;

                        $node = $child;
                    } elseif ($child + 1 < $this->timerCount
                        && $this->timerHeap[$child + 1]->expiration < $this->timerHeap[$node]->expiration
                    ) {
                        // Right child is greater than parent and greater than left child.
                        $temp = $this->timerHeap[$node];
                        $this->timerHeap[$node] = $this->timerHeap[$child + 1];
                        $this->timerHeap[$child + 1] = $temp;

                        $node = $child + 1;
                    } else {  // Left and right child are less than parent.
                        break;
                    }
                }

                if (!isset($this->timerExpires[$id]) || $this->timerExpires[$id] !== $timer->expiration) {
                    continue; // Timer was removed from queue.
                }

                if ($watcher->type & Watcher::REPEAT) {
                    $this->activate([$watcher]);
                } else {
                    $this->cancel($id);
                }

                // Execute the timer.
                $callback = $watcher->callback;
                $callback($id, $watcher->data);
            }
        }

        if ($this->signalHandling) {
            \pcntl_signal_dispatch();
        }
    }

    /**
     * @param resource[] $read
     * @param resource[] $write
     * @param int $timeout
     */
    private function selectStreams(array $read, array $write, $timeout) {
        $timeout /= self::MILLISEC_PER_SEC;

        if (!empty($read) || !empty($write)) { // Use stream_select() if there are any streams in the loop.
            if ($timeout >= 0) {
                $seconds = (int) $timeout;
                $microseconds = (int) (($timeout - $seconds) * self::MICROSEC_PER_SEC);
            } else {
                $seconds = null;
                $microseconds = null;
            }

            $except = null;

            // Error reporting suppressed since stream_select() emits an E_WARNING if it is interrupted by a signal.
            $count = @\stream_select($read, $write, $except, $seconds, $microseconds);

            if ($count) {
                foreach ($read as $stream) {
                    $streamId = (int) $stream;
                    if (isset($this->readWatchers[$streamId])) {
                        foreach ($this->readWatchers[$streamId] as $watcher) {
                            if (!isset($this->readWatchers[$streamId][$watcher->id])) {
                                continue; // Watcher disabled by another IO watcher.
                            }

                            $callback = $watcher->callback;
                            $callback($watcher->id, $stream, $watcher->data);
                        }
                    }
                }

                foreach ($write as $stream) {
                    $streamId = (int) $stream;
                    if (isset($this->writeWatchers[$streamId])) {
                        foreach ($this->writeWatchers[$streamId] as $watcher) {
                            if (!isset($this->writeWatchers[$streamId][$watcher->id])) {
                                continue; // Watcher disabled by another IO watcher.
                            }

                            $callback = $watcher->callback;
                            $callback($watcher->id, $stream, $watcher->data);
                        }
                    }
                }
            }

            return;
        }

        if ($timeout > 0) { // Otherwise sleep with usleep() if $timeout > 0.
            \usleep($timeout * self::MICROSEC_PER_SEC);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Interop\Async\Loop\UnsupportedFeatureException If the pcntl extension is not available.
     * @throws \RuntimeException If creating the backend signal handler fails.
     */
    public function onSignal($signo, callable $callback, $data = null) {
        if (!$this->signalHandling) {
            throw new UnsupportedFeatureException("Signal handling requires the pcntl extension");
        }

        return parent::onSignal($signo, $callback, $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $watchers) {
        foreach ($watchers as $watcher) {
            switch ($watcher->type) {
                case Watcher::READABLE:
                    $streamId = (int) $watcher->value;
                    $this->readWatchers[$streamId][$watcher->id] = $watcher;
                    $this->readStreams[$streamId] = $watcher->value;
                    break;

                case Watcher::WRITABLE:
                    $streamId = (int) $watcher->value;
                    $this->writeWatchers[$streamId][$watcher->id] = $watcher;
                    $this->writeStreams[$streamId] = $watcher->value;
                    break;

                case Watcher::DELAY:
                case Watcher::REPEAT:
                    $expiration = (int) (\microtime(true) * self::MILLISEC_PER_SEC) + $watcher->value;
                    $this->timerExpires[$watcher->id] = $expiration;

                    $timer = new Timer;
                    $timer->watcher = $watcher;
                    $timer->expiration = $expiration;

                    $node = $this->timerCount;
                    $this->timerHeap[$this->timerCount++] = $timer;

                    while (0 !== $node && $timer->expiration < $this->timerHeap[$parent = ($node - 1) >> 1]->expiration) {
                        $this->timerHeap[$node] = $this->timerHeap[$parent];
                        $this->timerHeap[$parent] = $timer;
                        $node = $parent;
                    }
                    break;

                case Watcher::SIGNAL:
                    if (!isset($this->signalWatchers[$watcher->value])) {
                        if (!@\pcntl_signal($watcher->value, function ($signo) {
                            foreach ($this->signalWatchers[$signo] as $watcher) {
                                if (!isset($this->signalWatchers[$signo][$watcher->id])) {
                                    continue;
                                }

                                $callback = $watcher->callback;
                                $callback($watcher->id, $signo, $watcher->data);
                            }
                        })) {
                            throw new \RuntimeException("Failed to register signal handler");
                        }
                    }

                    $this->signalWatchers[$watcher->value][$watcher->id] = $watcher;
                    break;

                default:
                    throw new \DomainException("Unknown watcher type");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deactivate(Watcher $watcher) {
        switch ($watcher->type) {
            case Watcher::READABLE:
                $streamId = (int) $watcher->value;
                unset($this->readWatchers[$streamId][$watcher->id]);
                if (empty($this->readWatchers[$streamId])) {
                    unset($this->readWatchers[$streamId], $this->readStreams[$streamId]);
                }
                break;

            case Watcher::WRITABLE:
                $streamId = (int) $watcher->value;
                unset($this->writeWatchers[$streamId][$watcher->id]);
                if (empty($this->writeWatchers[$streamId])) {
                    unset($this->writeWatchers[$streamId], $this->writeStreams[$streamId]);
                }
                break;

            case Watcher::DELAY:
            case Watcher::REPEAT:
                unset($this->timerExpires[$watcher->id]);
                break;

            case Watcher::SIGNAL:
                if (isset($this->signalWatchers[$watcher->value])) {
                    unset($this->signalWatchers[$watcher->value][$watcher->id]);

                    if (empty($this->signalWatchers[$watcher->value])) {
                        unset($this->signalWatchers[$watcher->value]);
                        @\pcntl_signal($watcher->value, \SIG_DFL);
                    }
                }
                break;

            default: throw new \DomainException("Unknown watcher type");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHandle() {
        return null;
    }
}
