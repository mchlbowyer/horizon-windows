# Horizon for Windows

An unofficial fork of [Laravel Horizon](https://github.com/laravel/horizon) that also runs on native Windows PHP, without the `pcntl` or `posix` extensions.

Horizon's dashboard and configuration are unchanged. On Linux and macOS this fork behaves exactly like upstream Horizon. The Windows support is only used when PHP runs on Windows.

## Why this exists

Horizon controls its master, supervisor and worker processes with POSIX signals, which Windows doesn't have. Upstream has [no plans to support Windows](https://github.com/laravel/horizon/issues/170#issuecomment-726074717) and recommends WSL2, and the Laravel team [suggested publishing a fork](https://github.com/laravel/horizon/issues/170#issuecomment-730242788) instead. This is that fork, for people who want to run Horizon in native Windows PHP, for example with Laravel Herd.

This is not an official Laravel package, and the Laravel team doesn't support it. Report problems [here](https://github.com/mchlbowyer/horizon-windows/issues), not on laravel/horizon.

## Installation

The fork isn't on Packagist yet, so install it from this repository. In your application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/mchlbowyer/horizon-windows"
        }
    ]
}
```

Then swap the packages:

```shell
composer remove laravel/horizon
composer require mchlbowyer/horizon-windows:5.x-dev
```

The package [replaces](https://getcomposer.org/doc/04-schema.md#replace) `laravel/horizon`, so packages that depend on Horizon still resolve. The namespace (`Laravel\Horizon`), service provider, config file, commands and dashboard are all the same, so nothing else in your application changes. Follow the [Horizon documentation](https://laravel.com/docs/horizon) as normal.

### Requirements

- The same PHP and Laravel versions as upstream Horizon 5.x (currently PHP 8.2+ and Laravel 12 or 13).
- Redis, as for upstream Horizon. On Windows that can be Herd's Redis service, Memurai or another Redis-compatible server.
- On Windows, PowerShell must be available, because it's used to list processes. It's built into Windows.
- `pcntl` and `posix` are still required on Linux and macOS (Composer lists them as suggestions so the package installs on Windows).

## How it works

Upstream Horizon's process handling is routed through a `ProcessControl` contract. `PosixProcessControl` keeps the upstream behaviour. `WindowsProcessControl` is chosen at runtime when `PHP_OS_FAMILY` is `Windows`:

- **Signals:** each Horizon process has a Redis list (a "mailbox") keyed by its process ID. Pause, continue, restart and terminate are pushed onto it and read once per loop, which is when the signal flags would have been checked on Linux anyway. A busy worker still finishes its current job first.
- **Child processes:** Symfony starts child processes on Windows through `cmd.exe`, so the ID a parent sees belongs to that shell, not to PHP. Each launched command therefore carries a random `HORIZON_PROCESS_ID`, which the parent and the child both use to find the same mailbox.
- **Workers:** a `Looping` listener reads the worker's mailbox, so Laravel's queue worker needs no changes.
- **Ctrl+C** is handled with `sapi_windows_set_ctrl_handler()` and shuts everything down gracefully.
- **Job timeouts:** without `pcntl_alarm`, a worker records a deadline in Redis when a job starts, and its supervisor kills the worker's process tree once the deadline passes.
- **Process lookups:** `pgrep` and `ps` are replaced by `Get-CimInstance Win32_Process`, and `tasklist` checks whether a parent process is still running.

## Known limitations on Windows

- A timed-out job stays reserved until the queue's `retry_after` expires, instead of being marked failed straight away.
- Timeouts are enforced from the supervisor's loop, so they can overrun by about 1–3 seconds.
- An idle worker can take up to its `--sleep` value to react to a signal.
- Mailboxes are keyed by process ID. If Windows reuses an ID within the mailbox's 5-minute expiry, a stale signal could reach the new process. Workers are protected by their random launch ID, and every process clears its own mailbox on start, so the risk is small.
- Flushing Horizon's Redis database also discards any signals that haven't been delivered yet.
- `fast_termination` hasn't been tested on Windows.
- Windows can't replace a file that another process has open. If Laravel is rebuilding `bootstrap/cache/services.php` (for example right after `composer update`) while several workers start at once, a worker can fail to boot. Its supervisor starts it again, but running `php artisan optimize:clear` or starting Horizon once before scaling up avoids it.

## Testing

- **Linux:** upstream's own test workflow runs unchanged on every push and pull request, across PHP 8.2–8.5 and Laravel 12 and 13, plus Redis Cluster. That's what shows the Linux and macOS behaviour is unchanged.
- **Windows:** the `windows tests` workflow runs the full suite on native Windows PHP 8.4 and 8.5 with Laravel 12 and 13. It first checks that `pcntl` and `posix` aren't loaded, and it uses Memurai for Redis.

## Keeping up with upstream

The `sync upstream` workflow runs daily and can also be started by hand. It merges laravel/horizon's `5.x` into this fork's `5.x`, using a merge rather than a rebase, so history is never rewritten.

- **Tests first:** it runs the Linux and Windows suites against the merged code, and pushes the merge only if both pass.
- **Problems:** if the merge conflicts or a test fails, it opens or updates an "Upstream sync needs attention" issue instead.
- **Workflow file changes:** GitHub's built-in token can't push changes to files in `.github/workflows`. If an upstream release changes one, the push fails and the issue says so. The merge then has to be done by hand, unless a `SYNC_TOKEN` secret holds a token with `contents` and `workflows` write access to this repository.

This fork follows upstream's `5.x` branch, not its tagged releases. `5.x-dev` is the latest code on that branch that has passed both test suites, plus the Windows support.

## Credits and license

Laravel Horizon is created by [Taylor Otwell](https://github.com/taylorotwell) and the Laravel team. All credit for Horizon itself goes to them. This fork is maintained by [Mike Bowyer](https://github.com/mchlbowyer).

Like upstream, it's open-source software licensed under the [MIT license](LICENSE.md).
