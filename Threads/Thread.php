<?php

declare(strict_types=1);

namespace Async\Threads;

use Async\Threads\TWorker;

final class Thread
{
  /** @var array[string|int => int|\UVAsync] */
  protected $threads = [];

  /** @var array[string|int => mixed] */
  protected $result = [];

  /** @var array[string|int => Throwable] */
  protected $exception = [];

  /** @var callable[] */
  protected $successCallbacks = [];

  /** @var callable[] */
  protected $errorCallbacks = [];

  /**
   * State Detection.
   *
   * @var array[string|int => mixed]
   */
  protected $status = [];

  /** @var callable */
  protected $success;

  /** @var callable */
  protected $failed;

  protected $loop;
  protected $hasLoop = false;

  /** @var boolean for **Coroutine** `yield` usage */
  protected $isYield = false;
  protected $tid = 0;

  protected $uv = null;

  public static function isCoroutine($object): bool
  {
    return (\class_exists('Coroutine', false)
      && \is_object($object)
      && \method_exists($object, 'addFuture')
      && \method_exists($object, 'execute')
      && \method_exists($object, 'executeTask')
      && \method_exists($object, 'run')
      && \method_exists($object, 'isPcntl')
      && \method_exists($object, 'createTask')
      && \method_exists($object, 'getUV')
      && \method_exists($object, 'schedule')
      && \method_exists($object, 'scheduleFiber')
      && \method_exists($object, 'ioStop')
      && \method_exists($object, 'fiberOn')
      && \method_exists($object, 'fiberOff')
    );
  }

  /**
   * @param object $loop
   * @param \UVLoop|null $uv
   * @param boolean $yielding
   */
  public function __construct(object $loop = null, bool $yielding = false)
  {
    if (!\IS_THREADED_UV)
      throw new \InvalidArgumentException('This `Thread` class requires PHP `ZTS` and the libuv `ext-uv` extension!');

    $uv = null;
    $lock = \mutex_lock();
    $this->isYield = $yielding;
    $this->hasLoop = \is_object($loop) && \method_exists($loop, 'executeTask') && \method_exists($loop, 'run');
    if (self::isCoroutine($loop)) {
      $this->loop = $loop;
      $this->isYield = true;
      $uv = $loop->getUV();
    } elseif ($this->hasLoop) {
      $this->loop = $loop;
    }

    $this->uv = $uv instanceof \UVLoop ? $uv : \uv_loop_new();
    $this->success = $this->isYield ? [$this, 'yieldAsFinished'] : [$this, 'triggerSuccess'];
    $this->failed = $this->isYield ? [$this, 'yieldAsFailed'] : [$this, 'triggerError'];
    \mutex_unlock($lock);
  }

  /**
   * @codeCoverageIgnore
   */
  public function create_ex(callable $task, ...$args): TWorker
  {
    return $this->create(\uniqid(), $task, $args);
  }

  /**
   * This will cause a _new thread_ to be **created** and **executed** for the associated `Thread` object,
   * where its _internal_ task `queue` will begin to be processed.
   *
   * @param string|int $tid Thread ID
   * @param callable $task
   * @param mixed ...$args
   * @return TWorker
   */
  public function create($tid, callable $task, ...$args): TWorker
  {
    $lock = \mutex_lock();
    $tid = \is_scalar($tid) ? $tid : (int) $tid;
    $this->tid = $tid;
    $this->status[$tid] = 'queued';
    $async = $this;
    if (!isset($this->threads[$tid]))
      $this->threads[$tid] = \uv_async_init($this->uv, function ($async) use ($tid) {
        $this->handlers($tid);
        $this->remove($tid);
        \uv_close($async);
      });
    \mutex_unlock($lock);

    \uv_queue_work($this->uv, function () use (&$async, &$task, $tid, &$args) {
      include 'vendor/autoload.php';
      try {
        if (!$async->isCancelled($tid))
          $result = $task(...$args);

        if (!$async->isCancelled($tid))
          $async->setResult($tid, $result);
      } catch (\Throwable $exception) {
        $async->setException($tid, $exception);
      }

      if (isset($async->threads[$tid]) && $async->threads[$tid] instanceof \UVAsync && \uv_is_active($async->threads[$tid])) {
        \uv_async_send($async->threads[$tid]);
        \sleep(2 * $async->count());
      }
    }, function () {
    });

    return new TWorker($this, $tid);
  }

  /**
   * This method will sends a cancellation request to the thread.
   *
   * @param string|int $tid Thread ID
   * @return void
   */
  public function cancel($tid = null): void
  {
    $lock = \mutex_lock();
    if (isset($this->status[$tid])) {
      $this->status[$tid] = ['cancelled'];
      $this->exception[$tid] = new \RuntimeException(\sprintf('Thread %s cancelled!', (string)$tid));
      if (isset($this->threads[$tid]) && $this->threads[$tid] instanceof \UVAsync && \uv_is_active($this->threads[$tid])) {
        \uv_async_send($this->threads[$tid]);
        //  $this->join($tid);
      }
    }
    \mutex_unlock($lock);
  }

  /**
   * This method will join a single thread by `tid` or `all` threads.
   * - It will first wait for that thread's internal task queue to finish.
   *
   * @param string|int $tid Thread ID
   * @return void
   */
  public function join($tid = null): void
  {
    $isCoroutine = $this->hasLoop && \is_object($this->loop) && \method_exists($this->loop, 'futureOn') && \method_exists($this->loop, 'futureOff');
    $isCancelled = $this->isCancelled($tid);
    while (!\is_null($tid) ? $this->isRunning($tid) || $isCancelled : $this->count() > 0) {
      if ($isCoroutine) { // @codeCoverageIgnoreStart
        $this->loop->futureOn();
        $this->loop->run();
        $this->loop->futureOff();
      } elseif ($this->hasLoop) {
        $this->loop->run(); // @codeCoverageIgnoreEnd
      } else {
        \uv_run($this->uv, (\is_null($tid) ? \UV::RUN_NOWAIT : \UV::RUN_ONCE));
      }

      if ($isCancelled)
        break;
    }
  }

  /**
   * @internal
   *
   * @param string|int $tid Thread ID
   * @return void
   */
  protected function handlers($tid): void
  {
    if ($this->isSuccessful($tid)) {
      if ($this->hasLoop) // @codeCoverageIgnoreStart
        $this->loop->executeTask($this->success, $tid);
      elseif ($this->isYield)
        $this->yieldAsFinished($tid);  // @codeCoverageIgnoreEnd
      else
        $this->triggerSuccess($tid);
    } else {
      if ($this->hasLoop)  // @codeCoverageIgnoreStart
        $this->loop->executeTask($this->failed, $tid);
      elseif ($this->isYield)
        $this->yieldAsFailed($tid);  // @codeCoverageIgnoreEnd
      else
        $this->triggerError($tid);
    }
  }

  /**
   * Get `Thread`'s task count.
   *
   * @return integer
   */
  public function count(): int
  {
    return \is_array($this->threads) ? \count($this->threads) : 0;
  }

  public function isEmpty(): bool
  {
    return empty($this->threads);
  }

  /**
   * Tell if the referenced `tid` is cancelled.
   *
   * @param string|int $tid Thread ID
   * @return bool
   */
  public function isCancelled($tid): bool
  {
    return isset($this->status[$tid]) && \is_array($this->status[$tid]);
  }

  /**
   * Tell if the referenced `tid` is executing.
   *
   * @param string|int $tid Thread ID
   * @return bool
   */
  public function isRunning($tid): bool
  {
    return isset($this->status[$tid]) && \is_string($this->status[$tid]);
  }

  /**
   * Tell if the referenced `tid` has completed execution successfully.
   *
   * @param string|int $tid Thread ID
   * @return bool
   */
  public function isSuccessful($tid): bool
  {
    return isset($this->status[$tid]) && $this->status[$tid] === true;
  }

  /**
   * Tell if the referenced `tid` was terminated during execution; suffered fatal errors, or threw uncaught exceptions.
   *
   * @param string|int $tid Thread ID
   * @return bool
   */
  public function isTerminated($tid): bool
  {
    return isset($this->status[$tid]) && $this->status[$tid] === false;
  }

  /**
   * @param string|int $tid Thread ID
   * @param \Throwable|null $exception
   * @return void
   */
  protected function setException($tid, \Throwable $exception): void
  {
    $lock = \mutex_lock();
    if (isset($this->status[$tid])) {
      $this->status[$tid] = false;
      $this->exception[$tid] = $exception;
    }
    \mutex_unlock($lock);
  }

  /**
   * @param string|int $tid Thread ID
   * @param mixed $result
   * @return void
   */
  protected function setResult($tid, $result): void
  {
    $lock = \mutex_lock();
    if (isset($this->status[$tid])) {
      $this->status[$tid] = true;
      $this->result[$tid] = $result;
    }
    \mutex_unlock($lock);
  }

  public function getResult($tid)
  {
    if (isset($this->result[$tid]))
      return $this->result[$tid];
  }

  public function getException($tid): \Throwable
  {
    if (isset($this->exception[$tid]))
      return $this->exception[$tid];
  }

  public function getSuccess(): array
  {
    return $this->result;
  }

  public function getFailed(): array
  {
    return $this->exception;
  }

  /**
   * Add handlers to be called when the `Thread` execution is _successful_, or _erred_.
   *
   * @param callable $thenCallback
   * @param callable|null $failCallback
   * @return self
   */
  public function then(callable $thenCallback, callable $failCallback = null, $tid = null): self
  {
    $lock = \mutex_lock();
    $this->successCallbacks[(\is_null($tid) ? $this->tid : $tid)][] = $thenCallback;
    \mutex_unlock($lock);
    if ($failCallback !== null) {
      $this->catch($failCallback, $tid);
    }

    return $this;
  }

  /**
   * Add handlers to be called when the `Thread` execution has _errors_.
   *
   * @param callable $callback
   * @return self
   */
  public function catch(callable $callback, $tid = null): self
  {
    $lock = \mutex_lock();
    $this->errorCallbacks[(\is_null($tid) ? $this->tid : $tid)][] = $callback;
    \mutex_unlock($lock);

    return $this;
  }

  /**
   * Call the success callbacks.
   *
   * @param string|int $tid Thread ID
   * @return mixed
   */
  public function triggerSuccess($tid)
  {
    if (isset($this->result[$tid])) {
      $result = $this->result[$tid];
      if ($this->isYield)
        return $this->yieldSuccess($result, $tid);

      foreach ($this->successCallbacks[$tid] as $callback)
        $callback($result);

      return $result;
    }
  }

  /**
   * Call the error callbacks.
   *
   * @param string|int $tid Thread ID
   * @return mixed
   */
  public function triggerError($tid)
  {
    if (isset($this->exception[$tid])) {
      $exception = $this->exception[$tid];
      if ($this->isYield)
        return $this->yieldError($exception, $tid);

      foreach ($this->errorCallbacks[$tid] as $callback)
        $callback($exception);

      if (!$this->errorCallbacks)
        throw $exception;
    }
  }

  protected function yieldSuccess($output, $tid)
  {
    foreach ($this->successCallbacks[$tid] as $callback)
      yield $callback($output);

    return $output;
  }

  protected function yieldError($exception, $tid)
  {
    foreach ($this->errorCallbacks[$tid] as $callback)
      yield $callback($exception);

    if (!$this->errorCallbacks) {
      throw $exception;
    }
  }

  /**
   * @param string|int $tid Thread ID
   * @return void
   */
  protected function remove($tid): void
  {
    if (isset($this->threads[$tid]) && $this->threads[$tid] instanceof \UVAsync) {
      $lock = \mutex_lock();
      unset($this->threads[$tid]);
      \mutex_unlock($lock);
    }
  }

  public function yieldAsFinished($tid)
  {
    return yield from $this->triggerSuccess($tid);
  }

  public function yieldAsFailed($tid)
  {
    return yield $this->triggerError($tid);
  }
}
