<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
    <title>Fish Feeder</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #111827;
            --accent: #06b6d4;
            --accent-2: #22c55e;
            --text: #e5e7eb;
            --muted: #94a3b8;
            --danger: #ef4444;
        }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background: var(--bg); margin: 0; color: var(--text); }
        .phone { max-width: 420px; margin: 0 auto; min-height: 100dvh; display: flex; flex-direction: column; }
        .appbar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; justify-content: center; height: 56px; padding: 0 16px; background: rgba(17,24,39,0.9); backdrop-filter: blur(8px); border-bottom: 1px solid rgba(255,255,255,0.06); }
        .appbar h1 { font-size: 18px; margin: 0; letter-spacing: 0.3px; }
        .content { flex: 1; padding: 16px; display: grid; gap: 16px; }
        .card { background: var(--card); border-radius: 16px; padding: 16px; border: 1px solid rgba(255,255,255,0.06); }
        .row { display: grid; gap: 12px; }
        .countdown { display: grid; gap: 8px; text-align: center; }
        .time { font-variant-numeric: tabular-nums; font-size: 42px; font-weight: 700; letter-spacing: 1px; }
        .badges { display: flex; gap: 8px; justify-content: center; color: var(--muted); font-size: 12px; }
        .btn { appearance: none; border: none; border-radius: 12px; padding: 14px 16px; font-size: 16px; font-weight: 600; color: white; background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(0,0,0,0.08)); border: 1px solid rgba(255,255,255,0.08); width: 100%; }
        .btn:active { transform: translateY(1px); }
        .btn-primary { background-color: var(--accent); border-color: rgba(255,255,255,0.08); }
        .btn-success { background-color: var(--accent-2); }
        .field label { display: block; color: var(--muted); font-size: 13px; margin-bottom: 6px; }
        .input { width: 100%; background: rgba(255,255,255,0.04); color: var(--text); padding: 14px 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.07); font-size: 16px; }
        .footer { padding: 12px 16px 24px; color: var(--muted); text-align: center; font-size: 12px; }
        .alert { text-align: center; padding: 10px 12px; border-radius: 12px; font-size: 14px; }
        .alert-success { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.35); color: #bbf7d0; }
        .alert-error { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #fecaca; }
    </style>
</head>
<body>
<div class="phone">
    <div class="appbar"><h1>Fish Feeder</h1></div>
    <div class="content">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif

        <div class="card countdown">
            <div>Next feed in</div>
            <div id="countdown" class="time">--:--:--</div>
            <div class="badges">
                <span id="badge-mins">{{ $intervalMinutes }} min interval</span>
            </div>
        </div>

        <div class="card">
            <div class="row">
                {{-- Manual feed → always 1x --}}
                <button class="btn btn-success" type="button" id="feedBtn">Feed Now</button>

                <button id="stopBtn" class="btn btn-danger" type="button">Stop Timer</button>

                {{-- Timer setup --}}
                <form method="POST" action="{{ route('schedule.update') }}">
                    @csrf
                    <div class="row" style="grid-template-columns: repeat(3, 1fr);">
                        <div class="field">
                            <label>Days</label>
                            <input class="input" type="number" name="days" min="0" max="7" value="{{ isset($d) ? $d : 0 }}" required>
                        </div>
                        <div class="field">
                            <label>Hours</label>
                            <input class="input" type="number" name="hours" min="0" max="23" value="{{ isset($h) ? $h : 0 }}" required>
                        </div>
                        <div class="field">
                            <label>Minutes</label>
                            <input class="input" type="number" name="minutes" min="0" max="59" value="{{ isset($m) ? $m : ($intervalMinutes % 60) }}" required>
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit" style="margin-top:12px;">Update Timer</button>
                </form>
            </div>
        </div>

        <div class="footer"></div>
    </div>
</div>
</body>
<script>
(function(){
    var remaining = {{ isset($remainingSeconds) ? (int)$remainingSeconds : 0 }};
    var intervalMinutes = {{ isset($intervalMinutes) ? (int)$intervalMinutes : 60 }};
    var el = document.getElementById('countdown');
    var firing = false;
    var feeding = false;
    var paused = false;
    var stopBtn = document.getElementById('stopBtn');
    var manualFeeding = false;  // Separate flag for manual feeding
    var autoFeeding = false;    // Separate flag for automatic feeding
    var lastUpdateTime = Date.now(); // Track when we last got data from server

    function fmt(n){ return n < 10 ? ('0'+n) : n; }

    function updateFromServer() {
        // Fetch current timer state from server every 30 seconds
        fetch('{{ route('schedule.timer') }}', { 
            method: 'GET', 
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.remainingSeconds !== undefined) {
                var serverRemaining = data.remainingSeconds;
                var difference = Math.abs(serverRemaining - remaining);
                
                // Only sync if there's a significant difference (more than 10 seconds)
                // This prevents the timer from jumping around due to minor timing differences
                if (difference > 10) {
                    remaining = serverRemaining;
                    lastUpdateTime = Date.now();
                    console.log('Timer synced from server:', remaining, 'seconds remaining (diff:', difference, ')');
                } else {
                    console.log('Timer sync skipped - client and server in sync (diff:', difference, ')');
                }
            }
        })
        .catch(function(error) {
            console.log('Failed to update from server:', error);
        });
    }

    function tick(){
        if (!el) return;
        var r = remaining;
        if (r < 0) r = 0;
        if (paused) {
            el.textContent = 'Paused';
            return;
        }
        if (manualFeeding) {
            el.textContent = 'Feeding…';
            return;
        }
        if (feeding) {
            el.textContent = 'Feeding…';
            return;
        }
        var d = Math.floor(r / 86400);
        var h = Math.floor((r % 86400) / 3600);
        var m = Math.floor((r % 3600) / 60);
        var s = Math.floor(r % 60); // Use Math.floor to ensure clean integer seconds
        el.textContent = (d + 'd ') + fmt(h) + ':' + fmt(m) + ':' + fmt(s);

        if (remaining <= 0 && !firing && !manualFeeding && !formSubmitted && !paused) {
            firing = true;
            feeding = true;
            autoFeeding = true;

            // Show 0 for a moment before triggering auto-feed
            setTimeout(function(){
                // Auto timer → Arduino always feeds 1x
                fetch('{{ route('schedule.autoFeed') }}', { method: 'GET', credentials: 'same-origin' })
                    .catch(function(_) {})
                    .finally(function(){
                        // pause ~2s to let servo finish
                        setTimeout(function(){
                            // Reset timer to full interval after auto feed
                            remaining = intervalMinutes * 60;
                            feeding = false;
                            firing = false;
                            autoFeeding = false;
                            lastUpdateTime = Date.now();
                            console.log('Auto feed completed, timer reset to full interval');
                        }, 2000);
                    });
            }, 1000); // Show 0 for 1 second before feeding
        } else if (!manualFeeding && !formSubmitted && !paused) {
            remaining -= 1;
        }
    }

    tick();
    setInterval(tick, 1000);
    // Update from server every 30 seconds to keep timer accurate
    setInterval(updateFromServer, 30000);

    function setPaused(next) {
        paused = !!next;
        if (stopBtn) {
            stopBtn.textContent = paused ? 'Start Timer' : 'Stop Timer';
            stopBtn.className = paused ? 'btn btn-primary' : 'btn btn-danger';
        }
    }

    if (stopBtn) {
        stopBtn.addEventListener('click', function(){
            setPaused(!paused);
        });
    }

    // Manual feed button click handler
    var feedBtn = document.getElementById('feedBtn');
    var formSubmitted = false;
    
    if (feedBtn) {
        feedBtn.addEventListener('click', function(e) {
            console.log('Manual feed button clicked');
            
            if (formSubmitted || feeding || autoFeeding) {
                console.log('Manual feed blocked - already submitted, feeding, or auto feeding in progress');
                return false;
            }
            
            formSubmitted = true;
            manualFeeding = true;
            feedBtn.disabled = true;
            feedBtn.textContent = 'Feeding...';
            
            console.log('Manual feed started, button disabled');
            
            // Use AJAX to send manual feed request without page reload
            fetch('{{ route('schedule.nextFeed') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                credentials: 'same-origin'
            })
            .then(function(response) {
                console.log('Manual feed request completed');
                return response.text();
            })
            .catch(function(error) {
                console.log('Manual feed request error:', error);
            })
            .finally(function() {
                setTimeout(function() {
                    formSubmitted = false;
                    manualFeeding = false;
                    feedBtn.disabled = false;
                    feedBtn.textContent = 'Feed Now';
                    // Refresh timer from server after manual feed
                    updateFromServer();
                    console.log('Manual feed reset, button enabled - timer refreshed from server');
                }, 2000);  // Reduced timeout to 2 seconds for faster response
            });
        });
    }
})();
</script>

</html>
