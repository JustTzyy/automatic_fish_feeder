<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FeedScheduleController extends Controller
{
    protected $pythonPath;
    protected $pythonScript;

    public function __construct()
    {
        // Allow override via .env: PYTHON_PATH=C:\\Python39\\python.exe (Windows) or /usr/bin/python3 (Linux)
        $this->pythonPath = env('PYTHON_PATH', 'python');
        // Build an absolute path to the bundled python script
        $this->pythonScript = base_path('python/arduino_serial.py');
    }

    protected function resolvePythonCommand(): array
    {
        $candidates = [];
        // If user set explicit path, try that first
        if (!empty($this->pythonPath)) {
            $candidates[] = [$this->pythonPath];
        }
        // Windows Python launcher
        $candidates[] = ['py', '-3'];
        // Common names
        $candidates[] = ['python'];
        $candidates[] = ['python3'];

        foreach ($candidates as $cmd) {
            try {
                $test = new Process(array_merge($cmd, ['--version']));
                $test->setTimeout(5);
                $test->run();
                if ($test->isSuccessful()) {
                    Log::info('Using Python command', ['command' => $cmd, 'version' => trim($test->getOutput() ?: $test->getErrorOutput())]);
                    return $cmd;
                }
            } catch (\Throwable $e) {
                // ignore and try next
            }
        }

        // Fallback to provided value even if not validated
        Log::warning('Falling back to configured python path without validation', ['pythonPath' => $this->pythonPath]);
        return [$this->pythonPath];
    }

    // Show dashboard
    public function index()
    {
        // Prefer seconds if present; fall back to minutes for backward compatibility
        $intervalSeconds = (int) session('interval_seconds', 0);
        if ($intervalSeconds <= 0) {
            $intervalMinutesLegacy = (int) session('interval_minutes', 60); // default 60 minutes
            $intervalSeconds = $intervalMinutesLegacy * 60;
        }

        $lastSetAtMs = (int) session('last_set_at_ms', 0);

        $intervalMs = $intervalSeconds * 1000;
        $nowMs = (int) round(microtime(true) * 1000);
        $elapsedMs = $lastSetAtMs > 0 ? max(0, $nowMs - $lastSetAtMs) : 0;
        $remainingSeconds = (int) max(0, floor(($intervalMs - $elapsedMs) / 1000));

        // Break into D/H/M/S for default form values
        $d = intdiv($intervalSeconds, 86400);
        $h = intdiv($intervalSeconds % 86400, 3600);
        $m = intdiv($intervalSeconds % 3600, 60);
        $s = 0; // seconds not used in UI

        // For badges
        $intervalMinutes = (int) round($intervalSeconds / 60);

        return view('schedule', compact('intervalMinutes', 'remainingSeconds', 'd', 'h', 'm', 's'));
    }

    // Update feeding schedule (timer-based D/H/M/S)
    public function update(Request $request)
    {
        $request->validate([
            'days' => 'nullable|integer|min:0',
            'hours' => 'nullable|integer|min:0|max:23',
            'minutes' => 'nullable|integer|min:0|max:59',
        ]);

        $days = (int) $request->input('days', 0);
        $hours = (int) $request->input('hours', 0);
        $minutes = (int) $request->input('minutes', 0);
        $totalSeconds = ($days * 86400) + ($hours * 3600) + ($minutes * 60);

        // Require at least 1 second, maximum 7 days
        if ($totalSeconds < 1 || $totalSeconds > 7 * 86400) {
            return redirect()->route('schedule.index')->with('error', 'Please choose between 1 minute and 7 days.');
        }

        // Persist seconds (and legacy minutes for compatibility)
        session(['interval_seconds' => $totalSeconds]);
        session(['interval_minutes' => (int) round($totalSeconds / 60)]);

        $intervalMs = $totalSeconds * 1000;

        // Ensure script exists
        if (!file_exists($this->pythonScript)) {
            Log::error('Python script not found at path: ' . $this->pythonScript);
            return redirect()->route('schedule.index')->with('error', 'Python script not found.');
        }

        // Send "SET_INTERVAL" → Arduino will auto-feed 1x when this timer elapses
        $pythonCmd = $this->resolvePythonCommand();
        $process = new Process(array_merge($pythonCmd, [$this->pythonScript, "SET_INTERVAL:$intervalMs"]));
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error('Failed to run python process', [
                'error' => $process->getErrorOutput(),
                'output' => $process->getOutput(),
            ]);
            return redirect()->route('schedule.index')->with('error', 'Failed to update feeding schedule (see logs).');
        }

        // Track when we applied the timer to show a live countdown in the UI
        session(['last_set_at_ms' => (int) round(microtime(true) * 1000)]);

        return redirect()->route('schedule.index')->with('success', 'Timer updated!');
    }

    // Manual feed
    public function nextFeed()
    {
        $requestId = uniqid('feed_', true);
        Log::info('=== nextFeed() method called ===', ['request_id' => $requestId]);
        
        // Prevent double submission using session
        $lastFeedTime = session('last_feed_time', 0);
        $currentTime = time();
        
        if ($currentTime - $lastFeedTime < 5) {
            Log::warning('Feed request blocked - too soon after last feed', [
                'request_id' => $requestId,
                'last_feed_time' => $lastFeedTime,
                'current_time' => $currentTime,
                'time_diff' => $currentTime - $lastFeedTime
            ]);
            return response()->json(['success' => false, 'message' => 'Please wait before feeding again.'], 429);
        }
        
        session(['last_feed_time' => $currentTime]);
        
        if (!file_exists($this->pythonScript)) {
            Log::error('Python script not found at path: ' . $this->pythonScript);
            return response()->json(['success' => false, 'message' => 'Python script not found.'], 500);
        }

        $pythonCmd = $this->resolvePythonCommand();

        // Send "FEED_NOW" → Arduino will always feed 1x (hardcoded in sketch)
        Log::info('Sending FEED_NOW command to Arduino', ['request_id' => $requestId]);
        $process = new Process(array_merge($pythonCmd, [$this->pythonScript, 'FEED_NOW']));
        $process->run();
        
        Log::info('Python process completed', [
            'request_id' => $requestId,
            'exit_code' => $process->getExitCode(),
            'is_successful' => $process->isSuccessful()
        ]);
        
        Log::info('Python process output', [
            'request_id' => $requestId,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'success' => $process->isSuccessful()
        ]);

        if (!$process->isSuccessful()) {
            Log::error('Failed to run python process', [
                'error' => $process->getErrorOutput(),
                'output' => $process->getOutput(),
            ]);
            return response()->json(['success' => false, 'message' => 'Manual feed failed (see logs).'], 500);
        }

        return response()->json(['success' => true, 'message' => 'Arduino fed manually (1x)!']);
    }

    // Automatic feed (2x)
    public function autoFeed()
    {
        if (!file_exists($this->pythonScript)) {
            Log::error('Python script not found at path: ' . $this->pythonScript);
            return redirect()->route('schedule.index')->with('error', 'Python script not found.');
        }

        $pythonCmd = $this->resolvePythonCommand();

        // Send "AUTO_FEED" → Arduino will feed 1x (automatic timer)
        $process = new Process(array_merge($pythonCmd, [$this->pythonScript, 'AUTO_FEED']));
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error('Failed to run python process', [
                'error' => $process->getErrorOutput(),
                'output' => $process->getOutput(),
            ]);
            return redirect()->route('schedule.index')->with('error', 'Automatic feed failed (see logs).');
        }

        return redirect()->route('schedule.index')->with('success', 'Arduino fed automatically (1x)!');
    }

    public function diagnose(Request $request)
    {
        $pythonCmd = $this->resolvePythonCommand();

        $version = null;
        try {
            $v = new Process(array_merge($pythonCmd, ['--version']));
            $v->setTimeout(5);
            $v->run();
            $version = trim($v->getOutput() ?: $v->getErrorOutput());
        } catch (\Throwable $e) {
            $version = 'Error: ' . $e->getMessage();
        }

        $scriptExists = file_exists($this->pythonScript);

        $testResult = [
            'ok' => false,
            'stdout' => '',
            'stderr' => '',
        ];

        if ($scriptExists) {
            try {
                // Test with "FEED_NOW" (1x feed)
                $p = new Process(array_merge($pythonCmd, [$this->pythonScript, 'FEED_NOW']));
                $p->setTimeout(10);
                $p->run();
                $testResult['ok'] = $p->isSuccessful();
                $testResult['stdout'] = $p->getOutput();
                $testResult['stderr'] = $p->getErrorOutput();
            } catch (\Throwable $e) {
                $testResult['ok'] = false;
                $testResult['stderr'] = 'Exception: ' . $e->getMessage();
            }
        }

        return response()->json([
            'python_command' => $pythonCmd,
            'python_version' => $version,
            'script_path' => $this->pythonScript,
            'script_exists' => $scriptExists,
            'test_run' => $testResult,
        ]);
    }
}
