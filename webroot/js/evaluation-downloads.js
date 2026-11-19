(function () {
    'use strict';

    const form = document.getElementById('evaluation-download-form');
    if (!form) return;
    const checkboxes = Array.from(form.querySelectorAll('input[name="student_ids[]"]'));
    const submit = form.querySelector('[data-download-selected]');
    const next = form.querySelector('[data-select-next]');
    const clear = form.querySelector('[data-clear-selection]');
    const retry = form.querySelector('[data-retry-completion]');
    const error = document.querySelector('[data-evaluation-error]');
    const status = document.querySelector('[data-evaluation-status]');
    const counter = form.querySelector('[data-selection-count]');
    const preview = document.querySelector('[data-evaluation-preview]');
    const progressText = preview.querySelector('[data-evaluation-progress-text]');
    const progress = preview.querySelector('[data-evaluation-progress]');
    const files = preview.querySelector('[data-evaluation-files]');
    const fileRows = new Map();
    let pendingToken = null;
    let busy = false;

    function showError(message) {
        error.textContent = message;
        error.hidden = false;
    }

    function update() {
        const selected = checkboxes.filter(input => input.checked).length;
        counter.textContent = selected + ' of 10 selected';
        checkboxes.forEach(input => {
            input.disabled = busy || pendingToken !== null || input.dataset.unavailable === '1'
                || (selected >= 10 && !input.checked);
        });
        submit.disabled = busy || pendingToken !== null || selected === 0;
        next.disabled = busy || pendingToken !== null;
        clear.disabled = busy || pendingToken !== null;
        retry.hidden = pendingToken === null;
        retry.disabled = busy;
    }

    async function requireSuccess(response) {
        if (response.ok && !response.redirected) return;
        let message = 'Download unavailable. Refresh this page and check that you are still signed in.';
        if ((response.headers.get('Content-Type') || '').includes('application/json')) {
            const data = await response.json();
            if (typeof data.error === 'string') message = data.error;
        }
        throw new Error(message);
    }

    function startPreview() {
        files.replaceChildren();
        fileRows.clear();
        const selected = checkboxes.filter(input => input.checked);
        selected.forEach(input => {
            const row = document.createElement('li');
            row.className = 'list-group-item d-flex flex-wrap justify-content-between gap-2';
            const details = document.createElement('div');
            const name = document.createElement('strong');
            name.textContent = input.dataset.studentName;
            const path = document.createElement('small');
            path.className = 'd-block text-muted text-break';
            path.textContent = 'evaluations.pdf';
            const state = document.createElement('span');
            state.textContent = 'Waiting';
            details.append(name, path);
            row.append(details, state);
            files.appendChild(row);
            fileRows.set(input.value, {path, state});
        });
        preview.hidden = false;
        progress.max = selected.length;
        progress.value = 0;
        progressText.textContent = 'Preparing 0 of ' + selected.length + ' PDFs...';
    }

    async function readProgress(response) {
        if (!(response.headers.get('Content-Type') || '').includes('application/x-ndjson') || !response.body) {
            throw new Error('The server did not return live download progress. Please refresh this page.');
        }
        let ready = null;
        const handle = function (line) {
            if (!line.trim()) return;
            let event;
            try {
                event = JSON.parse(line);
            } catch (failure) {
                throw new Error('The live download progress was interrupted. No students were marked complete.');
            }
            if (event.type === 'error') throw new Error(event.message);
            if (event.type === 'file') {
                const row = fileRows.get(String(event.student_id));
                if (!row || !['preparing', 'ready'].includes(event.status)) {
                    throw new Error('The server returned progress for an unexpected file.');
                }
                row.path.textContent = event.file;
                row.state.textContent = event.status === 'ready' ? 'PDF ready' : 'Preparing PDF...';
                row.state.className = event.status === 'ready' ? 'text-success' : 'text-primary';
                progress.value = event.completed;
                progressText.textContent = 'Preparing ' + event.completed + ' of ' + event.total + ' PDFs...';
            } else if (event.type === 'packing') {
                progress.removeAttribute('value');
                progressText.textContent = 'All PDFs ready. Creating ZIP...';
            } else if (event.type === 'ready') {
                if (!/^[a-f0-9]{64}$/.test(event.batch_token)
                    || typeof event.download_url !== 'string'
                    || new URL(event.download_url, location.href).origin !== location.origin
                    || !Number.isSafeInteger(event.bytes) || event.bytes < 1) {
                    throw new Error('The server did not return a valid ZIP download.');
                }
                ready = event;
            }
        };
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let ended = false;
        try {
            while (true) {
                const chunk = await reader.read();
                if (chunk.done) {
                    ended = true;
                    break;
                }
                buffer += decoder.decode(chunk.value, {stream: true});
                let newline;
                while ((newline = buffer.indexOf('\n')) !== -1) {
                    handle(buffer.slice(0, newline));
                    buffer = buffer.slice(newline + 1);
                }
            }
            handle(buffer + decoder.decode());
        } finally {
            if (!ended) await reader.cancel();
            reader.releaseLock();
        }
        if (ready === null) {
            throw new Error('The connection ended before the ZIP was ready. No students were marked complete.');
        }
        return ready;
    }

    async function receiveZip(ready) {
        progress.max = ready.bytes;
        progress.value = 0;
        progressText.textContent = 'Downloading ZIP...';
        const response = await fetch(ready.download_url, {credentials: 'same-origin'});
        await requireSuccess(response);
        if (!(response.headers.get('Content-Type') || '').includes('application/zip') || !response.body) {
            throw new Error('The server did not return an evaluation ZIP. No students were marked complete.');
        }
        const reader = response.body.getReader();
        const chunks = [];
        let received = 0;
        try {
            while (true) {
                const chunk = await reader.read();
                if (chunk.done) break;
                chunks.push(chunk.value);
                received += chunk.value.length;
                progress.value = received;
                progressText.textContent = 'Downloading ZIP: ' + Math.round(received / 1024)
                    + ' / ' + Math.round(ready.bytes / 1024) + ' KB';
            }
        } finally {
            reader.releaseLock();
        }
        if (received !== ready.bytes) {
            throw new Error('The ZIP download was incomplete. No students were marked complete.');
        }
        return new Blob(chunks, {type: 'application/zip'});
    }

    async function recordCompletion() {
        const body = new FormData();
        body.append('token', pendingToken);
        body.append('_csrfToken', form.querySelector('input[name="_csrfToken"]').value);
        const response = await fetch(form.dataset.completeUrl, {
            method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}
        });
        await requireSuccess(response);
        const data = await response.json();
        if (!Number.isInteger(data.completed) || data.completed < 1 || data.completed > 10) {
            throw new Error('The server did not confirm download completion. Please retry recording completion.');
        }
        status.textContent = 'Download complete. Selecting the next students...';
        window.location.reload();
    }

    checkboxes.forEach(input => input.addEventListener('change', update));
    next.addEventListener('click', function () {
        let selected = 0;
        checkboxes.forEach(input => {
            input.checked = input.dataset.unavailable !== '1' && input.dataset.completed !== '1' && selected < 10;
            if (input.checked) selected++;
        });
        update();
    });
    clear.addEventListener('click', function () {
        checkboxes.forEach(input => { input.checked = false; });
        update();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy || pendingToken !== null) return;
        const body = new FormData(form);
        const selected = body.getAll('student_ids[]').length;
        if (selected < 1 || selected > 10) {
            showError('Select between 1 and 10 students.');
            return;
        }
        busy = true;
        error.hidden = true;
        status.textContent = 'Preparing evaluations for ' + selected + ' student(s). Please keep this page open...';
        startPreview();
        update();
        try {
            const response = await fetch(form.action, {method: 'POST', body, credentials: 'same-origin'});
            await requireSuccess(response);
            const ready = await readProgress(response);
            const blob = await receiveZip(ready);
            const signature = new Uint8Array(await blob.slice(0, 4).arrayBuffer());
            if (signature[0] !== 80 || signature[1] !== 75 || signature[2] !== 3 || signature[3] !== 4) {
                throw new Error('The evaluation ZIP is invalid. No students were marked complete.');
            }
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'judges-evaluations-' + selected + '-students.zip';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
            pendingToken = ready.batch_token;
            fileRows.forEach(row => {
                row.state.textContent = 'Download started';
                row.state.className = 'text-success';
            });
            progressText.textContent = 'ZIP received. Download started for ' + selected + ' student(s).';
            status.textContent = 'Download started. Recording completion...';
            await recordCompletion();
        } catch (failure) {
            if (pendingToken === null) {
                fileRows.forEach(row => {
                    row.state.textContent = 'Batch not downloaded';
                    row.state.className = 'text-danger';
                });
                progress.removeAttribute('value');
                progressText.textContent = 'Download stopped.';
            }
            status.textContent = pendingToken === null ? 'Download not completed.' : 'ZIP received; completion has not been recorded.';
            showError(failure.message);
        } finally {
            busy = false;
            update();
        }
    });

    retry.addEventListener('click', async function () {
        if (busy || pendingToken === null) return;
        busy = true;
        error.hidden = true;
        update();
        try {
            await recordCompletion();
        } catch (failure) {
            showError(failure.message);
        } finally {
            busy = false;
            update();
        }
    });
    update();
}());
