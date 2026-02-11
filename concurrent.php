<?php

/**
 * Exception thrown when a task is cancelled.
 */
class CancelledException extends RuntimeException {}

/**
 * Exception thrown when a timeout occurs.
 */
class TimeoutException extends RuntimeException {}

/**
 * Represents a concurrent task (a Fiber).
 * Immutable handle – you never create these directly, only via Concurrent::spawn().
 */
final class Task
{
    private int $id;
    private Fiber $fiber;
    private bool $done = false;
    private mixed $result = null;
    private ?Throwable $exception = null;
    private bool $cancelled = false;

    public function __construct(Fiber $fiber, int $id)
    {
        $this->fiber = $fiber;
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Returns the task's result. If the task threw, rethrows that exception.
     * @throws RuntimeException if task not finished
     * @throws Throwable the exception that was thrown inside the task
     */
    public function getResult(): mixed
    {
        if (!$this->done) {
            throw new RuntimeException('Task not completed');
        }
        if ($this->exception !== null) {
            throw $this->exception;
        }
        return $this->result;
    }

    /**
     * Mark the task as cancelled. If the fiber is suspended, we throw
     * a CancelledException into it.
     */
    public function cancel(): void
    {
        if ($this->done) {
            return;
        }
        $this->cancelled = true;
        $this->exception = new CancelledException('Task cancelled');
        $this->done = true;

        if ($this->fiber->isSuspended()) {
            $this->fiber->throw($this->exception);
        }
    }

    /**
     * Internal: called by the scheduler to run/resume this task.
     * @internal
     */
    public function run(): void
    {
        if ($this->done) {
            return;
        }

        // If the fiber has already finished, collect result/exception.
        if ($this->fiber->isTerminated()) {
            $this->done = true;
            if ($this->fiber->isThrowed()) {
                $this->exception = $this->fiber->getThrowed();
            } else {
                $this->result = $this->fiber->getReturn();
            }
            return;
        }

        // Resume the fiber (start it if it hasn't been started yet).
        // Fiber::resume() on a non‑started fiber is equivalent to start().
        if ($this->fiber->isSuspended()) {
            $this->fiber->resume();
        }
    }

    /**
     * @internal
     */
    public function isSuspended(): bool
    {
        return $this->fiber->isSuspended();
    }
}

/**
 * Structured concurrency scheduler.
 *
 * All methods are static – you can run multiple independent schedulers
 * because the state is stored in a thread‑local (fiber‑local) manner.
 */
final class Concurrent
{
    // Use Fiber‑local storage via an array keyed by the current fiber.
    // This allows multiple independent scheduler instances in different fibers.
    private static array $storage = [];

    /**
     * Initialise or get the state for the current scheduler context.
     * Each root fiber (the one that calls Concurrent::run()) gets its own
     * queue, task map, etc.
     */
    private static function getState(): array
    {
        $current = Fiber::getCurrent();
        $key = spl_object_id($current);
        if (!isset(self::$storage[$key])) {
            self::$storage[$key] = [
                'tasks'      => new SplQueue(),   // runnable tasks
                'taskMap'    => [],               // id => Task
                'nextId'     => 1,
                'waiting'    => [],               // taskId => [waitingFiber, ...]
                'suspended'  => [],               // fibers waiting for await/all/any
            ];
        }
        return self::$storage[$key];
    }

    /**
     * Spawn a new task that runs the given callback.
     * The task is automatically queued and will run when the scheduler yields.
     */
    public static function spawn(callable $callback): Task
    {
        $state = &self::$storage[spl_object_id(Fiber::getCurrent())];
        $fiber = new Fiber($callback);
        $id = $state['nextId']++;
        $task = new Task($fiber, $id);
        $state['taskMap'][$id] = $task;
        $state['tasks']->enqueue($task);
        return $task;
    }

    /**
     * Voluntarily yield control to other tasks.
     * Call this inside long‑running loops or after I/O (if you have non‑blocking I/O).
     */
    public static function yield(): void
    {
        if (Fiber::getCurrent() === null) {
            return; // not inside a fiber, nothing to yield
        }
        Fiber::suspend();
    }

    /**
     * Non‑blocking sleep. Uses yield() to avoid blocking the whole scheduler.
     */
    public static function sleep(float $seconds): void
    {
        $start = microtime(true);
        while (microtime(true) - $start < $seconds) {
            self::yield();
        }
    }

    /**
     * Wait for a single task to complete. Returns its result.
     * If the task throws, the exception is re‑thrown.
     */
    public static function await(Task $task): mixed
    {
        if ($task->isDone()) {
            return $task->getResult();
        }

        $current = Fiber::getCurrent();
        $state = &self::$storage[spl_object_id($current)];
        $taskId = $task->getId();

        // Register this fiber as waiting for the task.
        $state['waiting'][$taskId][] = $current;
        // Suspend until the task completes.
        Fiber::suspend();

        // When we are resumed, the task must be done.
        return $task->getResult();
    }

    /**
     * Wait for all given tasks to complete. Returns an array of results
     * in the same order as the input tasks.
     *
     * @param Task[] $tasks
     * @return mixed[]
     */
    public static function all(array $tasks): array
    {
        $pending = array_filter($tasks, fn($t) => !$t->isDone());
        if (empty($pending)) {
            return array_map(fn($t) => $t->getResult(), $tasks);
        }

        $current = Fiber::getCurrent();
        $state = &self::$storage[spl_object_id($current)];

        // Wait for all pending tasks.
        foreach ($pending as $task) {
            $state['waiting'][$task->getId()][] = $current;
        }

        Fiber::suspend(); // will be resumed when any of the tasks finishes

        // After resume, we may not be done yet – keep waiting.
        // This loops until all are done.
        while (count(array_filter($tasks, fn($t) => !$t->isDone())) > 0) {
            // Re‑register for any tasks that are still pending.
            foreach ($tasks as $task) {
                if (!$task->isDone()) {
                    $state['waiting'][$task->getId()][] = $current;
                }
            }
            Fiber::suspend();
        }

        return array_map(fn($t) => $t->getResult(), $tasks);
    }

    /**
     * Wait for the first task to complete. Returns that task.
     * The other tasks are cancelled.
     *
     * @param Task[] $tasks
     */
    public static function any(array $tasks): Task
    {
        // If any is already done, cancel the rest and return it.
        foreach ($tasks as $task) {
            if ($task->isDone()) {
                foreach ($tasks as $t) {
                    if ($t !== $task && !$t->isDone()) {
                        $t->cancel();
                    }
                }
                return $task;
            }
        }

        $current = Fiber::getCurrent();
        $state = &self::$storage[spl_object_id($current)];

        // Wait for any of the tasks.
        foreach ($tasks as $task) {
            $state['waiting'][$task->getId()][] = $current;
        }

        Fiber::suspend();

        // Find the first completed task.
        foreach ($tasks as $task) {
            if ($task->isDone()) {
                // Cancel all others that are still pending.
                foreach ($tasks as $t) {
                    if ($t !== $task && !$t->isDone()) {
                        $t->cancel();
                    }
                }
                return $task;
            }
        }

        throw new RuntimeException('No task completed (should never happen)');
    }

    /**
     * Wait for all tasks, but throw TimeoutException if they don't complete
     * within the given number of seconds.
     *
     * @param float  $seconds
     * @param Task[] $tasks
     * @return mixed[]
     * @throws TimeoutException
     */
    public static function withTimeout(float $seconds, array $tasks): array
    {
        $timeoutTask = self::spawn(function() use ($seconds) {
            $start = microtime(true);
            while (microtime(true) - $start < $seconds) {
                self::yield();
            }
            throw new TimeoutException('Operation timed out');
        });

        $allTasks = array_merge($tasks, [$timeoutTask]);

        try {
            $first = self::any($allTasks);
            if ($first === $timeoutTask) {
                // Timeout occurred – cancel all original tasks.
                foreach ($tasks as $t) {
                    if (!$t->isDone()) {
                        $t->cancel();
                    }
                }
                // This will throw the TimeoutException.
                return $timeoutTask->getResult();
            } else {
                // Cancel the timeout task.
                $timeoutTask->cancel();
                // Wait for all original tasks to finish.
                return self::all($tasks);
            }
        } finally {
            if (!$timeoutTask->isDone()) {
                $timeoutTask->cancel();
            }
        }
    }

    /**
     * Explicitly cancel a task.
     */
    public static function cancel(Task $task): void
    {
        $task->cancel();

        // Find the state that owns this task.
        foreach (self::$storage as &$state) {
            if (isset($state['taskMap'][$task->getId()])) {
                // Resume any fibers waiting for this task so they can react.
                if (isset($state['waiting'][$task->getId()])) {
                    foreach ($state['waiting'][$task->getId()] as $fiber) {
                        if ($fiber->isSuspended()) {
                            $fiber->resume();
                        }
                    }
                    unset($state['waiting'][$task->getId()]);
                }
                break;
            }
        }
    }

    /**
     * Run the scheduler – this is the main event loop.
     * It will continue until no runnable tasks remain.
     *
     * Call this from your root fiber (usually your script's main body).
     */
    public static function run(): void
    {
        $current = Fiber::getCurrent();
        if ($current === null) {
            // Not in a fiber – we must wrap the whole script in a fiber.
            // This is transparent to the user.
            $main = new Fiber(function(): void {
                self::runScheduler();
            });
            $main->start();
            return;
        }

        $key = spl_object_id($current);
        if (!isset(self::$storage[$key])) {
            self::$storage[$key] = [
                'tasks'      => new SplQueue(),
                'taskMap'    => [],
                'nextId'     => 1,
                'waiting'    => [],
                'suspended'  => [],
            ];
        }

        self::runScheduler();
    }

    private static function runScheduler(): void
    {
        $state = &self::$storage[spl_object_id(Fiber::getCurrent())];

        while (!$state['tasks']->isEmpty()) {
            // Dequeue the next runnable task.
            $task = $state['tasks']->dequeue();

            // Run the task (resume its fiber).
            $task->run();

            // If the task has finished, notify all fibers waiting for it.
            if ($task->isDone()) {
                $id = $task->getId();
                if (isset($state['waiting'][$id])) {
                    foreach ($state['waiting'][$id] as $waitingFiber) {
                        if ($waitingFiber->isSuspended()) {
                            $waitingFiber->resume();
                        }
                    }
                    unset($state['waiting'][$id]);
                }
            }

            // If the task is still suspended (i.e., it yielded), re‑queue it.
            if ($task->isSuspended()) {
                $state['tasks']->enqueue($task);
            }

            // After each task, give other fibers a chance (cooperative).
            // This also allows the scheduler to suspend itself if there's nothing else.
            if (!$state['tasks']->isEmpty()) {
                Fiber::suspend(); // will be resumed when a new task is enqueued or a waiting fiber is resumed
            }
        }

        // No more tasks – clean up this scheduler's state.
        unset(self::$storage[spl_object_id(Fiber::getCurrent())]);
    }
}