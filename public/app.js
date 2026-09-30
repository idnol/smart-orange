(function () {
    const form = document.getElementById('import-form');
    const fileInput = document.getElementById('file');
    const startBtn = document.getElementById('start');
    const progress = document.getElementById('progress');
    const barFill = document.getElementById('bar-fill');
    const text = document.getElementById('progress-text');
    const fmt = (n) => n.toLocaleString('uk-UA');

    async function post(url, body, attempts = 3) {
        for (let i = 1; ; i++) {
            try {
                const res = await fetch(url, { method: 'POST', body });
                const data = await res.json().catch(() => ({ error: 'Некоректна відповідь сервера (HTTP ' + res.status + ')' }));
                if (res.ok) return data;
                if (data.fatal || i >= attempts || res.status < 500) throw new Error(data.error);
            } catch (e) {
                if (i >= attempts) throw e;
            }
            await new Promise((r) => setTimeout(r, 1000 * i));
        }
    }

    function show(percent, message) {
        progress.hidden = false;
        barFill.style.width = percent + '%';
        text.textContent = message;
    }

    async function upload(file) {
        const size = window.CHUNK_SIZE;
        let importId = 0;
        for (let offset = 0; offset < file.size; offset += size) {
            const body = new FormData();
            body.append('import_id', importId);
            body.append('filename', file.name);
            body.append('file_size', file.size);
            body.append('offset', offset);
            body.append('chunk', file.slice(offset, offset + size), 'chunk');
            const data = await post('upload.php', body);
            importId = data.import_id;
            show(Math.round((data.uploaded_bytes / file.size) * 30), 'Завантаження файлу: ' + fmt(data.uploaded_bytes) + ' / ' + fmt(file.size) + ' байт');
        }
        return importId;
    }

    async function process(importId) {
        const started = performance.now();
        for (;;) {
            const body = new FormData();
            body.append('import_id', importId);
            const d = await post('process.php', body);
            const share = d.file_size ? d.byte_offset / d.file_size : 1;
            show(30 + Math.round(share * 70), 'Імпортовано рядків: ' + fmt(d.rows_processed) +
                ' (валідних ' + fmt(d.rows_valid) + ', з помилками ' + fmt(d.rows_invalid) + '), крок ' + d.step_seconds + ' с');
            if (d.status === 'done') {
                show(100, 'Готово! Імпортовано ' + fmt(d.rows_processed) + ' рядків (валідних ' + fmt(d.rows_valid) +
                    ', з помилками ' + fmt(d.rows_invalid) + ') за ' + ((performance.now() - started) / 1000).toFixed(1) + ' с');
                return;
            }
            if (d.status !== 'processing') throw new Error(d.error || 'Імпорт зупинено: ' + d.status);
        }
    }

    async function run(task) {
        startBtn.disabled = true;
        try {
            const importId = await task();
            setTimeout(() => (location.href = '?import=' + importId), 1500);
        } catch (e) {
            show(100, 'Помилка: ' + e.message);
            barFill.classList.add('failed');
            startBtn.disabled = false;
        }
    }

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const file = fileInput.files[0];
        if (!file) return;
        barFill.classList.remove('failed');
        run(async () => {
            const id = await upload(file);
            await process(id);
            return id;
        });
    });

    document.querySelectorAll('.resume').forEach((btn) =>
        btn.addEventListener('click', () => {
            const id = Number(btn.dataset.id);
            window.scrollTo(0, 0);
            run(async () => (await process(id), id));
        })
    );
})();
