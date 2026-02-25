<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Signing you in...</title>
    <style>
        :root {
            --bg: #f4f6f8;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --accent: #0f766e;
            --ring: #d1fae5;
            --danger: #b91c1c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: radial-gradient(circle at 20% 20%, #ecfeff, var(--bg));
            color: var(--text);
            font-family: ui-sans-serif, system-ui, sans-serif;
        }
        .card {
            width: min(420px, calc(100vw - 32px));
            background: var(--card);
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
            text-align: center;
        }
        .spinner {
            width: 40px;
            height: 40px;
            margin: 0 auto 16px;
            border-radius: 50%;
            border: 4px solid var(--ring);
            border-top-color: var(--accent);
            animation: spin 0.9s linear infinite;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 18px;
        }
        p {
            margin: 0;
            font-size: 14px;
            color: var(--muted);
        }
        .error {
            margin-top: 12px;
            color: var(--danger);
            font-size: 13px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @media (prefers-reduced-motion: reduce) {
            .spinner { animation: none; }
        }
    </style>
</head>
<body>
<div class="card">
    <div class="spinner" aria-hidden="true"></div>
    <h1>Signing you in</h1>
    <p>Please wait while we complete authentication.</p>
    <p id="error" class="error" hidden></p>
</div>

<script>
    (async function () {
        const errorEl = document.getElementById('error');

        try {
            const response = await fetch(@json(route('auth.callback.finalize')), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                credentials: 'same-origin',
            });

            const payload = await response.json().catch(() => ({}));

            if (! response.ok || ! payload.redirect_url) {
                throw new Error(payload.message || 'Authentication failed.');
            }

            window.location.replace(payload.redirect_url);
        } catch (error) {
            errorEl.hidden = false;
            errorEl.textContent = error?.message || 'Authentication failed. Please try again.';
        }
    })();
</script>
</body>
</html>
