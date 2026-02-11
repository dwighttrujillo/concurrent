[![Latest Stable Version](https://img.shields.io/github/v/release/dwighttrujillo/concurrent)](https://github.com/dwighttrujillo/concurrent/releases)
Concurrent brings safe, structured concurrency to PHP 8.1+ using native Fibers. It offers a simple, blocking‑style API that any PHP developer can understand – without needing to learn event‑loop patterns, promise chains, or process forking.

🔍 The Problem
PHP applications frequently need to perform multiple I/O operations at once: calling several REST APIs, querying different databases, reading multiple files, or aggregating data from microservices.
Traditional solutions force you to either:

Run tasks sequentially (slow and inefficient).

Use complex async libraries (ReactPHP, Amp) with a completely different programming model.

Fork processes (heavy, often unavailable on shared hosts).

PHP 8.1 introduced Fibers – low‑level primitives for cooperative multitasking – but left a gap for a high‑level, beginner‑friendly API.

✅ The Solution
Concurrent fills that gap. It provides a tiny scheduler that manages Fibers transparently. You write plain, sequential code – Concurrent runs it in parallel.

```php
$userTask = Concurrent::spawn(fn() => $this->db->query('SELECT * FROM users'));
$orderTask = Concurrent::spawn(fn() => $this->api->getOrders());
$emailTask = Concurrent::spawn(fn() => $this->mailer->sendBulk());

// All three run concurrently – wait for all of them
[$users, $orders, $emails] = Concurrent::all([$userTask, $orderTask, $emailTask]);
```
## Key Features

- **`spawn(callable): Task`** – launches a new concurrent task (Fiber)  
- **`await(Task): mixed`** – waits for a specific task and returns its result (re‑throws exceptions)  
- **`all(array $tasks): array`** – waits for **all** given tasks; returns results in original order  
- **`any(array $tasks): Task`** – waits for the **first** task to complete; cancels the others  
- **`withTimeout(float $seconds, array $tasks): array`** – fails fast if tasks don't finish in time  
- **`cancel(Task): void`** – safely cancels a running task  
- **`yield(): void`** – voluntarily yields control to other tasks (cooperative multitasking)  
- **`sleep(float $seconds): void`** – non‑blocking sleep

withTimeout(float $seconds, array $tasks): array – fails fast if tasks don’t finish in time.

cancel(Task): void – safely cancels a running task.

yield(): void – voluntarily yields control to other tasks (cooperative multitasking).

sleep(float $seconds): void – non‑blocking sleep (uses yield).

All methods are static and fully re‑entrant – you can run multiple independent schedulers in different Fibers.

🧠 Why It’s Different
No global event loop – the scheduler runs only when tasks are active.

No promises – you don’t chain .then(), you simply block and get a result.

Zero dependencies – pure PHP, no Composer packages, no PECL extensions.

Structured concurrency – tasks are bound to the scheduler that spawned them; no orphaned fibers.

⚙️ How It Works
Concurrent::run() starts the scheduler (if not already running).

spawn() creates a new Fiber and adds it to the run queue.

The scheduler repeatedly picks the next runnable task and resumes it.

When a task calls yield() or sleep(), it is suspended and re‑queued.

When a task finishes, all Fibers waiting for it (via await/all/any) are resumed.

Exceptions thrown inside a task are captured and re‑thrown when you await() that task.

📦 Use Cases
API gateways – aggregate 10+ microservices in parallel.

CLI tools – scrape websites, process multiple files, batch database inserts.

Job workers – handle several jobs concurrently with lightweight Fibers.

Laravel/Symfony – parallelise event listeners, command bus handlers, or queued jobs.

⚠️ Current Limitations
Cooperative multitasking – tasks must yield periodically (via yield() or sleep()) to avoid blocking the whole scheduler. Blocking I/O functions (file_get_contents, PDO::query, curl_exec) do not yield – use non‑blocking alternatives or wrap them in a custom Fiber‑aware adapter.

No I/O multiplexing yet – future versions may include stream_select() or curl_multi wrappers that yield automatically.

🔮 Future Direction
Adapters for Guzzle, PDO, Redis, and file streams that transparently yield.

Optional coroutine‑style async / await syntactic sugar (requires PHP RFC).

Composer package with PSR‑11 container integration.

Concurrent is a solid foundation for modern, high‑performance PHP. Drop it into any 8.1+ project and start parallelising I/O‑bound work today.
