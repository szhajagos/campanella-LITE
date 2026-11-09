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
    const attachments = document.getElementById('attachments');
    const attachmentList = attachments.querySelector('ul');
    const fileInput = document.getElementById('file-input');
    let historyIndex = -1;

    // Files dropped on the command box or chosen with "Attach files". They are
    // uploaded when the batch runs, before its commands; the response gives
    // their ids, which later commands can use.
    let pending = [];

    // Outcomes after which the batch has done its work: the box is emptied,
    // so pressing Run again cannot repeat it. After a dry run or an error the
    // text stays, to be run for real or corrected. Alt+↑ brings it back.
    const finishedOutcomes = ['applied', 'unchanged'];

    function historyEntries() {
        return Array.from(historyBox.querySelectorAll('[data-batch]')).map((item) => item.dataset.batch);
    }

    function renderAttachments() {
        attachmentList.replaceChildren(...pending.map((file, index) => {
            const item = document.createElement('li');
            item.className = 'list-inline-item attachment';
            item.textContent = file.name;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = '×';
            remove.title = attachmentList.dataset.removeLabel;
            remove.setAttribute('aria-label', attachmentList.dataset.removeLabel + ': ' + file.name);
            remove.addEventListener('click', () => {
                pending.splice(index, 1);
                renderAttachments();
            });

            item.append(remove);
            return item;
        }));
        attachments.hidden = pending.length === 0;
    }

    function attach(files) {
        pending = pending.concat(Array.from(files));
        renderAttachments();
    }

    async function post(url, options) {
        const reply = await fetch(url, { method: 'POST', credentials: 'same-origin', ...options });

        if (reply.status === 401 || reply.status === 403) {
            window.location.reload();
            return null;
        }

        return reply.json();
    }

    async function run() {
        if ((commands.value.trim() === '' && pending.length === 0) || runButton.disabled) {
            return;
        }

        const sent = commands.value;
        const files = pending;
        let html = '';
        runButton.disabled = true;
        response.setAttribute('aria-busy', 'true');

        try {
            if (files.length > 0) {
                const form = new FormData();
                files.forEach((file) => form.append('files[]', file, file.name));
                const uploaded = await post('/admin/upload', { headers: { 'X-CSRF-Token': csrf }, body: form });

                if (uploaded === null) {
                    return;
                }

                html += uploaded.html;
                document.getElementById('status').innerHTML = uploaded.status_html;
                document.getElementById('files').innerHTML = uploaded.files_html;
                updateProposals(uploaded.proposals_html);
                pending = pending.filter((file) => !files.includes(file));
                renderAttachments();
            }

            if (sent.trim() !== '') {
                const data = await post('/admin/run', {
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ commands: sent, dry_run: dryRun.checked }),
                });

                if (data === null) {
                    return;
                }

                html += data.html;
                document.getElementById('status').innerHTML = data.status_html;
                document.getElementById('pages').innerHTML = data.pages_html;
                document.getElementById('files').innerHTML = data.files_html;
                updateProposals(data.proposals_html);
                historyBox.innerHTML = data.history_html;
                document.getElementById('site-name').textContent = data.site_name;
                historyIndex = -1;

                // Leave the box alone if something new was typed while the batch ran.
                if (finishedOutcomes.includes(data.outcome) && commands.value === sent) {
                    commands.value = '';
                }
            }

            response.innerHTML = html;
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

    document.getElementById('attach').addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        attach(fileInput.files);
        fileInput.value = '';
        commands.focus();
    });

    commands.addEventListener('dragover', (event) => {
        if (event.dataTransfer && Array.from(event.dataTransfer.types).includes('Files')) {
            event.preventDefault();
            commands.classList.add('dragover');
        }
    });
    commands.addEventListener('dragleave', () => commands.classList.remove('dragover'));
    commands.addEventListener('drop', (event) => {
        commands.classList.remove('dragover');

        if (event.dataTransfer && event.dataTransfer.files.length > 0) {
            event.preventDefault();
            attach(event.dataTransfer.files);
            commands.focus();
        }
    });

    document.getElementById('clear').addEventListener('click', () => {
        commands.value = '';
        pending = [];
        renderAttachments();
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

    function updateProposals(html) {
        const box = document.getElementById('proposals');

        if (box && typeof html === 'string') {
            box.innerHTML = html;
        }
    }

    // Approving or rejecting a batch an AI user proposed
    document.getElementById('proposals')?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-proposal]');

        if (!button || button.disabled) {
            return;
        }

        button.closest('.proposal').querySelectorAll('button').forEach((b) => { b.disabled = true; });
        response.setAttribute('aria-busy', 'true');

        try {
            const data = await post('/admin/proposals/' + button.dataset.proposal + '/' + button.dataset.action, {
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: '{}',
            });

            if (data === null) {
                return;
            }

            response.innerHTML = data.html;
            document.getElementById('status').innerHTML = data.status_html;
            document.getElementById('pages').innerHTML = data.pages_html;
            document.getElementById('files').innerHTML = data.files_html;
            historyBox.innerHTML = data.history_html;
            updateProposals(data.proposals_html);
            document.getElementById('site-name').textContent = data.site_name;
        } catch (error) {
            response.textContent = String(error);
        } finally {
            response.removeAttribute('aria-busy');
        }
    });

    // A click on a file puts its reference ("![](file:12)") where the cursor is.
    document.getElementById('files').addEventListener('click', (event) => {
        const tile = event.target.closest('[data-reference]');

        if (!tile) {
            return;
        }

        const start = commands.selectionStart ?? commands.value.length;
        const end = commands.selectionEnd ?? start;
        commands.setRangeText(tile.dataset.reference, start, end, 'end');
        commands.focus();
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
