<?php

namespace Laravel\Horizon;

use Symfony\Component\Process\Process;

class WindowsProcessTable
{
    /**
     * Get the PHP processes and their command shells running on this machine.
     *
     * @return array<int, array{pid: int, parent: int, name: string, command: string}>
     */
    public function all()
    {
        $names = array_unique([strtolower(basename(PHP_BINARY)), 'php.exe', 'cmd.exe']);

        $filter = implode(' OR ', array_map(fn ($name) => "Name='{$name}'", $names));

        $script = '[Console]::OutputEncoding = [Text.Encoding]::UTF8; '.
            'Get-CimInstance Win32_Process -Filter "'.$filter.'" | '.
            'ForEach-Object { "{0}`t{1}`t{2}`t{3}" -f $_.ProcessId, $_.ParentProcessId, $_.Name, $_.CommandLine }';

        $output = $this->run([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8')),
        ]);

        return collect(preg_split('/\r?\n/', $output))
            ->filter(fn ($line) => substr_count($line, "\t") >= 3)
            ->map(function ($line) {
                [$pid, $parent, $name, $command] = explode("\t", $line, 4);

                return [
                    'pid' => (int) $pid,
                    'parent' => (int) $parent,
                    'name' => strtolower($name),
                    'command' => trim($command),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Get the PHP processes running on this machine.
     *
     * @return array<int, array{pid: int, parent: int, name: string, command: string}>
     */
    public function php()
    {
        return array_values(array_filter(
            $this->all(), fn ($process) => $process['name'] !== 'cmd.exe'
        ));
    }

    /**
     * Determine if the process with the given ID is running.
     *
     * @param  int  $processId
     * @return bool
     */
    public function isRunning($processId)
    {
        $output = $this->run([
            'tasklist.exe', '/FI', 'PID eq '.(int) $processId, '/FO', 'CSV', '/NH',
        ]);

        return str_contains($output, '"'.(int) $processId.'"');
    }

    /**
     * Forcefully stop the process with the given ID and all of its children.
     *
     * @param  int  $processId
     * @return bool
     */
    public function kill($processId)
    {
        $process = new Process(['taskkill.exe', '/F', '/T', '/PID', (string) (int) $processId]);

        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Run the given command and return its output.
     *
     * @param  array  $command
     * @return string
     */
    protected function run(array $command)
    {
        $process = new Process($command);

        $process->setTimeout(30)->run();

        return $process->getOutput();
    }
}
