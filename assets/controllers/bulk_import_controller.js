import { Controller } from '@hotwired/stimulus';

// Même règle que la barre d'upload du formulaire : les octets couvrent 95 %,
// 100 % n'arrive qu'avec la confirmation du serveur.
const UPLOAD_SHARE = 0.95;
const PARALLEL_CONTENTS = 2;

const normalize = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '');

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

const formatSize = (bytes) => (bytes >= 1073741824
    ? `${(bytes / 1073741824).toFixed(1).replace('.', ',')} Go`
    : `${Math.max(1, Math.round(bytes / 1048576))} Mo`);

/*
 * Import en masse des fichiers d'un sujet (voir SubjectImportController).
 * 1. Les fichiers déposés sont analysés : nom INTERVENANT-LANGUE-FORMAT,
 *    orientation et durée lues dans le fichier.
 * 2. Le tableau permet de corriger ; l'envoi reste bloqué tant qu'une ligne
 *    est à compléter, en doublon, ou remplacerait un fichier sans accord.
 * 3. Chaque fichier part dans sa propre requête ; les fichiers d'un même
 *    contenu partent l'un après l'autre (le premier crée le contenu).
 */
export default class extends Controller {
    static targets = ['input', 'dropzone', 'table', 'rows', 'summary', 'start', 'retry', 'report'];
    static values = { config: Object };

    connect() {
        this.rows = [];
        this.nextId = 1;
        this.uploading = false;
        this.contents = this.configValue.contents.map((c) => ({ ...c, slots: { ...c.slots } }));
        this.subjectSpeakerIds = new Set(this.contents.map((c) => c.speakerId).filter(Boolean));
        window.addEventListener('beforeunload', this.warnBeforeLeaving);
        this.render();
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.warnBeforeLeaving);
    }

    warnBeforeLeaving = (event) => {
        if (this.uploading) {
            event.preventDefault();
            event.returnValue = '';
        }
    };

    // ---- Ajout de fichiers ---------------------------------------------

    pick() {
        this.addFiles(this.inputTarget.files);
        this.inputTarget.value = '';
    }

    dragOver(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.add('is-dragover');
    }

    dragLeave() {
        this.dropzoneTarget.classList.remove('is-dragover');
    }

    drop(event) {
        event.preventDefault();
        this.dragLeave();
        this.addFiles(event.dataTransfer.files);
    }

    addFiles(fileList) {
        Array.from(fileList).forEach((file) => {
            const row = { id: this.nextId++, file, status: 'pending', progress: 0, replace: false, ...this.parseName(file) };
            this.rows.push(row);
            this.probe(row);
        });
        this.render();
    }

    /**
     * INTERVENANT-LANGUE-FORMAT : premier segment = sigle, dernier = format,
     * le reste = langue (un nom de langue composé reste possible).
     */
    parseName(file) {
        const base = file.name.replace(/\.[^.]+$/, '');
        const parts = base.split('-').map((part) => part.trim()).filter(Boolean);
        const result = { speakerId: null, languageId: null, format: null, notes: [] };

        if (file.type.startsWith('audio/')) {
            result.format = 'AUDIO';
        }
        if (parts.length < 3) {
            result.notes.push('Nom hors convention');
            return result;
        }

        const format = normalize(parts.at(-1));
        if (this.configValue.formats.includes(format)) {
            result.format = format;
        }

        const language = normalize(parts.slice(1, -1).join(''));
        result.languageId = this.configValue.languages.find((l) => normalize(l.name) === language)?.id ?? null;

        // Code = sigle, précédé de MCC pour un ministre conseiller (MFAS / MCCMFAS).
        // À défaut, le sigle seul reste accepté : il n'est ambigu que s'il est partagé.
        const code = normalize(parts[0]);
        const byCode = this.configValue.speakers.filter((s) => s.code && normalize(s.code) === code);
        const candidates = byCode.length > 0
            ? byCode
            : this.configValue.speakers.filter((s) => s.sigle && normalize(s.sigle) === code);
        if (candidates.length === 1) {
            result.speakerId = candidates[0].id;
        } else if (candidates.length > 1) {
            // Sigle partagé : on retient l'intervenant déjà présent sur ce sujet, s'il est seul dans ce cas.
            const onSubject = candidates.filter((s) => this.subjectSpeakerIds.has(s.id));
            if (onSubject.length === 1) {
                result.speakerId = onSubject[0].id;
            } else {
                result.notes.push(`Sigle ${parts[0]} partagé par plusieurs intervenants`);
            }
        } else {
            result.notes.push(`Sigle ${parts[0]} inconnu`);
        }

        return result;
    }

    /** Lit durée et orientation dans le fichier (sans l'envoyer). */
    probe(row) {
        if (!row.file.type.startsWith('video/') && !row.file.type.startsWith('audio/')) {
            return;
        }
        const media = document.createElement(row.file.type.startsWith('video/') ? 'video' : 'audio');
        const url = URL.createObjectURL(row.file);
        media.preload = 'metadata';
        media.onloadedmetadata = () => {
            row.duration = Number.isFinite(media.duration) ? media.duration : null;
            if (media.videoWidth && media.videoHeight) {
                row.orientation = media.videoHeight > media.videoWidth ? 'portrait' : 'landscape';
            }
            URL.revokeObjectURL(url);
            this.renderRow(row);
        };
        media.onerror = () => URL.revokeObjectURL(url);
        media.src = url;
    }

    // ---- Édition des lignes --------------------------------------------

    edit(event) {
        const row = this.rowFor(event);
        const { field } = event.target.dataset;
        if (field === 'replace') {
            row.replace = event.target.checked;
        } else {
            row[field] = event.target.value === '' ? null : (field === 'format' ? event.target.value : Number(event.target.value));
            row.replace = false;
        }
        this.render();
    }

    remove(event) {
        const row = this.rowFor(event);
        this.rows = this.rows.filter((r) => r !== row);
        this.render();
    }

    clear() {
        this.rows = this.rows.filter((r) => r.status === 'uploading');
        this.reportTarget.hidden = true;
        this.render();
    }

    rowFor(event) {
        const id = Number(event.target.closest('[data-row-id]').dataset.rowId);
        return this.rows.find((r) => r.id === id);
    }

    // ---- État d'une ligne ----------------------------------------------

    contentFor(row) {
        return this.contents.find((c) => c.speakerId === row.speakerId && c.languageId === row.languageId) ?? null;
    }

    /** État avant envoi : bloquant (`blocked`) ou non, avec son libellé. */
    check(row) {
        if (!row.speakerId || !row.languageId || !row.format) {
            const missing = [!row.speakerId && 'intervenant', !row.languageId && 'langue', !row.format && 'format'].filter(Boolean);
            return { kind: 'todo', blocked: true, label: `À compléter : ${missing.join(', ')}` };
        }
        const duplicate = this.rows.some((other) => other !== row && other.status !== 'done'
            && other.speakerId === row.speakerId && other.languageId === row.languageId && other.format === row.format);
        if (duplicate) {
            return { kind: 'todo', blocked: true, label: 'Doublon dans le lot : même intervenant, langue et format' };
        }
        const existing = this.contentFor(row)?.slots[row.format];
        if (existing && !row.replace) {
            return { kind: 'replace', blocked: true, label: `Remplacera « ${existing} »` };
        }

        const warning = this.warningFor(row);
        return warning
            ? { kind: 'warning', blocked: false, label: `À vérifier : ${warning}` }
            : { kind: 'ready', blocked: false, label: existing ? `Prêt (remplacera « ${existing} »)` : 'Prêt' };
    }

    warningFor(row) {
        const isVideo = row.file.type.startsWith('video/');
        if (row.format === 'TV' && row.orientation === 'portrait') {
            return 'la vidéo est verticale alors que le format est TV';
        }
        if (row.format === 'MOBILE' && row.orientation === 'landscape') {
            return 'la vidéo est horizontale alors que le format est MOBILE';
        }
        if (row.format === 'AUDIO' && isVideo) {
            return 'un fichier vidéo est rangé en AUDIO';
        }
        if (row.format !== 'AUDIO' && row.file.type.startsWith('audio/')) {
            return 'un fichier audio est rangé en vidéo';
        }
        return null;
    }

    // ---- Envoi ---------------------------------------------------------

    async start() {
        const queue = this.rows.filter((r) => r.status === 'pending' || r.status === 'error');
        if (this.uploading || queue.length === 0 || queue.some((r) => this.check(r).blocked)) {
            return;
        }
        this.uploading = true;
        this.reportTarget.hidden = true;
        queue.forEach((row) => {
            row.status = 'queued';
            row.error = null;
        });
        this.render();

        // Un groupe par contenu : ses fichiers partent l'un après l'autre.
        const groups = new Map();
        queue.forEach((row) => {
            const key = `${row.speakerId}|${row.languageId}`;
            groups.set(key, [...(groups.get(key) ?? []), row]);
        });
        const pending = [...groups.values()];
        const worker = async () => {
            while (pending.length > 0) {
                for (const row of pending.shift()) {
                    await this.upload(row);
                }
            }
        };
        await Promise.all(Array.from({ length: Math.min(PARALLEL_CONTENTS, pending.length) }, worker));

        this.uploading = false;
        this.render();
        this.renderReport();
    }

    retryFailed() {
        this.start();
    }

    upload(row) {
        return new Promise((resolve) => {
            const data = new FormData();
            data.append('_token', this.configValue.csrfToken);
            data.append('speaker', row.speakerId);
            data.append('language', row.languageId);
            data.append('format', row.format);
            data.append('replace', row.replace ? '1' : '0');
            if (row.duration) {
                data.append('duration', String(row.duration));
            }
            data.append('file', row.file);

            const xhr = new XMLHttpRequest();
            row.status = 'uploading';
            this.setProgress(row, 0);
            this.renderRow(row);

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    this.setProgress(row, (e.loaded / e.total) * UPLOAD_SHARE);
                }
            });
            xhr.upload.addEventListener('load', () => {
                this.setProgress(row, UPLOAD_SHARE);
                this.setStateLabel(row, 'Enregistrement…');
            });
            xhr.addEventListener('load', () => {
                const body = this.parseJson(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && body?.videoId) {
                    this.markDone(row, body);
                } else {
                    this.markFailed(row, body?.error ?? `Erreur du serveur (${xhr.status}).`);
                }
                resolve();
            });
            xhr.addEventListener('error', () => {
                this.markFailed(row, 'Envoi interrompu : vérifiez la connexion.');
                resolve();
            });

            xhr.open('POST', this.configValue.uploadUrl);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.send(data);
        });
    }

    markDone(row, body) {
        row.status = 'done';
        row.progress = 1;
        row.result = body;
        let content = this.contentFor(row);
        if (!content) {
            content = { id: body.videoId, speakerId: row.speakerId, languageId: row.languageId, slots: {} };
            this.contents.push(content);
        }
        content.slots[row.format] = row.file.name;
        this.render();
    }

    markFailed(row, message) {
        row.status = 'error';
        row.error = message;
        this.render();
    }

    parseJson(text) {
        try {
            return JSON.parse(text);
        } catch {
            return null;
        }
    }

    // ---- Rendu ---------------------------------------------------------

    render() {
        this.tableTarget.hidden = this.rows.length === 0;
        this.rowsTarget.innerHTML = '';
        this.rows.forEach((row) => this.rowsTarget.append(this.buildRow(row)));
        this.renderSummary();
    }

    renderRow(row) {
        const current = this.rowsTarget.querySelector(`[data-row-id="${row.id}"]`);
        if (current) {
            current.replaceWith(this.buildRow(row));
        }
        this.renderSummary();
    }

    renderSummary() {
        const waiting = this.rows.filter((r) => r.status === 'pending' || r.status === 'error');
        const blocked = waiting.filter((r) => this.check(r).blocked).length;
        const done = this.rows.filter((r) => r.status === 'done').length;
        const failed = this.rows.filter((r) => r.status === 'error').length;

        this.startTarget.disabled = this.uploading || waiting.length === 0 || blocked > 0;
        this.startTarget.textContent = this.uploading
            ? 'Envoi en cours…'
            : `Lancer l’envoi${waiting.length ? ` (${waiting.length} fichier${waiting.length > 1 ? 's' : ''})` : ''}`;
        this.retryTarget.hidden = this.uploading || failed === 0 || blocked > 0;

        const parts = [`${this.rows.length} fichier${this.rows.length > 1 ? 's' : ''}`];
        if (blocked) parts.push(`${blocked} à traiter avant l’envoi`);
        if (done) parts.push(`${done} envoyé${done > 1 ? 's' : ''}`);
        if (failed) parts.push(`${failed} en échec`);
        this.summaryTarget.textContent = parts.join(' · ');
    }

    buildRow(row) {
        const tr = document.createElement('tr');
        tr.dataset.rowId = String(row.id);
        const locked = row.status !== 'pending' && row.status !== 'error';
        const state = this.stateFor(row);
        tr.className = `bulk-import__row is-${state.kind}`;

        const options = (items, selected, label) => items
            .map((item) => `<option value="${item.value}"${item.value === selected ? ' selected' : ''}>${escapeHtml(label(item))}</option>`)
            .join('');
        const speakers = this.configValue.speakers
            .map((s) => ({ ...s, value: s.id }))
            .sort((a, b) => (a.code ?? '~').localeCompare(b.code ?? '~'));
        const languages = this.configValue.languages.map((l) => ({ ...l, value: l.id }));
        const formats = this.configValue.formats.map((f) => ({ value: f }));
        const disabled = locked ? ' disabled' : '';

        tr.innerHTML = `
            <td class="bulk-import__file">
                <strong>${escapeHtml(row.file.name)}</strong>
                <small>${formatSize(row.file.size)}${row.orientation ? ` · ${row.orientation === 'portrait' ? 'verticale' : 'horizontale'}` : ''}${row.notes.length ? ` · ${escapeHtml(row.notes.join(' · '))}` : ''}</small>
            </td>
            <td><select data-field="speakerId" data-action="bulk-import#edit" aria-label="Intervenant"${disabled}>
                <option value="">— Choisir —</option>${options(speakers, row.speakerId, (s) => (s.code ? `${s.code} — ${s.name}` : s.name))}
            </select></td>
            <td><select data-field="languageId" data-action="bulk-import#edit" aria-label="Langue"${disabled}>
                <option value="">— Choisir —</option>${options(languages, row.languageId, (l) => l.name)}
            </select></td>
            <td><select data-field="format" data-action="bulk-import#edit" aria-label="Format"${disabled}>
                <option value="">—</option>${options(formats, row.format, (f) => f.value)}
            </select></td>
            <td class="bulk-import__state">
                <span class="bulk-import__label" data-role="label">${escapeHtml(state.label)}</span>
                ${state.kind === 'replace' || (row.replace && !locked) ? `
                    <label class="bulk-import__replace"><input type="checkbox" data-field="replace" data-action="bulk-import#edit"${row.replace ? ' checked' : ''}> Remplacer</label>` : ''}
                ${row.status === 'uploading' || row.status === 'done' ? `
                    <span class="bulk-import__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.floor(row.progress * 100)}">
                        <span data-role="bar" style="width: ${Math.floor(row.progress * 100)}%"></span>
                    </span>` : ''}
                ${row.status === 'done' ? `<a href="${row.result.editUrl}">${row.result.created ? 'Contenu créé' : 'Contenu mis à jour'} →</a>` : ''}
            </td>
            <td>${locked ? '' : `<button type="button" class="bulk-import__remove" data-action="bulk-import#remove" aria-label="Retirer ${escapeHtml(row.file.name)}">✕</button>`}</td>`;

        return tr;
    }

    stateFor(row) {
        switch (row.status) {
            case 'queued':
                return { kind: 'queued', label: 'En attente d’envoi' };
            case 'uploading':
                return { kind: 'uploading', label: `Envoi… ${Math.floor(row.progress * 100)} %` };
            case 'done':
                return { kind: 'done', label: 'Envoyé ✓' };
            case 'error':
                return { kind: 'error', label: `Échec : ${row.error}` };
            default:
                return this.check(row);
        }
    }

    setProgress(row, ratio) {
        row.progress = ratio;
        const tr = this.rowsTarget.querySelector(`[data-row-id="${row.id}"]`);
        const bar = tr?.querySelector('[data-role="bar"]');
        if (bar) {
            bar.style.width = `${Math.floor(ratio * 100)}%`;
            bar.parentElement.setAttribute('aria-valuenow', String(Math.floor(ratio * 100)));
            this.setStateLabel(row, `Envoi… ${Math.floor(ratio * 100)} %`);
        }
    }

    setStateLabel(row, text) {
        const label = this.rowsTarget.querySelector(`[data-row-id="${row.id}"] [data-role="label"]`);
        if (label) {
            label.textContent = text;
        }
    }

    renderReport() {
        const done = this.rows.filter((r) => r.status === 'done');
        const failed = this.rows.filter((r) => r.status === 'error');
        const created = new Set(done.filter((r) => r.result.created).map((r) => r.result.videoId)).size;
        const touched = new Map(done.map((r) => [r.result.videoId, r.result.editUrl]));

        this.reportTarget.hidden = false;
        this.reportTarget.classList.toggle('has-errors', failed.length > 0);
        this.reportTarget.querySelector('[data-role="text"]').textContent = [
            `${done.length} fichier${done.length > 1 ? 's' : ''} envoyé${done.length > 1 ? 's' : ''}`,
            `${created} contenu${created > 1 ? 's' : ''} créé${created > 1 ? 's' : ''} en brouillon`,
            `${touched.size} contenu${touched.size > 1 ? 's' : ''} concerné${touched.size > 1 ? 's' : ''}`,
            failed.length ? `${failed.length} échec${failed.length > 1 ? 's' : ''} (voir le tableau)` : null,
        ].filter(Boolean).join(' · ');
    }
}
