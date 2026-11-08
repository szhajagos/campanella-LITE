/* Campanella Lite – admin dashboard. No framework; the server renders the HTML. */
(function () {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const commands = document.getElementById('commands');
    const runButton = document.getElementById('run');

    if (!commands || !runButton) {
        return;
    }

    const dryRun = document.getElementById('dry-run');
    const response = document.getElementById('response');
    const historyBox = document.getElementById('history');
    let historyIndex = -1;

    function historyEntries() {
        return Array.from(historyBox.querySelectorAll('[data-batch]')).map((item) => item.dataset.batch);
    }

    async function run() {
        if (commands.value.trim() === '' || runButton.disabled) {
            return;
        }

        runButton.disabled = true;
        response.setAttribute('aria-busy', 'true');

        try {
            const reply = await fetch('/admin/run', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ commands: commands.value, dry_run: dryRun.checked }),
            });

            if (reply.status === 401 || reply.status === 403) {
                window.location.reload();
                return;
            }

            const data = await reply.json();
            response.innerHTML = data.html;
            document.getElementById('status').innerHTML = data.status_html;
            document.getElementById('pages').innerHTML = data.pages_html;
            historyBox.innerHTML = data.history_html;
            document.getElementById('site-name').textContent = data.site_name;
            historyIndex = -1;
        } catch (error) {
            response.textContent = String(error);
        } finally {
            runButton.disabled = false;
            response.removeAttribute('aria-busy');
        }
    }

    function recall(step) {
        const entries = historyEntries();

        if (entries.length === 0) {
            return;
        }

        historyIndex = Math.max(-1, Math.min(entries.length - 1, historyIndex + step));
        commands.value = historyIndex === -1 ? '' : entries[historyIndex];
    }

    runButton.addEventListener('click', run);

    document.getElementById('clear').addEventListener('click', () => {
        commands.value = '';
        historyIndex = -1;
        commands.focus();
    });

    commands.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            run();
        } else if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
            event.preventDefault();
            recall(event.key === 'ArrowUp' ? 1 : -1);
        }
    });

    historyBox.addEventListener('click', (event) => {
        const item = event.target.closest('[data-batch]');

        if (item) {
            commands.value = item.dataset.batch;
            commands.focus();
        }
    });

    commands.focus();
}());
