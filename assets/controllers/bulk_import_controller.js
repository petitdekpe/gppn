import { Controller } from '@hotwired/stimulus';
import { matchFileName } from '../lib/filename_matcher.js';

// Même règle que la barre d'upload du formulaire : les octets couvrent 95 %,
// 100 % n'arrive qu'avec la confirmation du serveur.
const UPLOAD_SHARE = 0.95;
const PARALLEL_CONTENTS = 2;
// Un morceau de 8 Mo sur une connexion lente (≈ 0,5 Mbit/s) prend environ 2 minutes.
const CHUNK_TIMEOUT = 180000;
// Attentes (en secondes) avant chaque nouvel essai : environ 5 minutes au total.
const RETRY_DELAYS = [1, 2, 4, 8, 15, 30, 30, 60, 60, 90];
const RETRYABLE_STATUSES = [0, 408, 429, 500, 502, 503, 504];

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
 * 3. Chaque fichier part en morceaux de quelques Mo, renvoyés d'eux-mêmes en
 *    cas de coupure ; un fichier redéposé après un rechargement reprend là
 *    où il s'était arrêté. Les fichiers d'un même contenu partent l'un après
 *    l'autre (le premier crée le contenu).
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
     * Intervenant, langue et format devinés dans le nom, sans exiger l'ordre
     * ni l'orthographe exacte (voir assets/lib/filename_matcher.js). Les
     * lectures approchées restent signalées « À vérifier » dans le tableau.
     */
    parseName(file) {
        return matchFileName(file.name, {
            speakers: this.configValue.speakers,
            languages: this.configValue.languages,
            formats: this.configValue.formats,
            preferredGovernmentId: this.configValue.preferredGovernmentId,
            subjectSpeakerIds: this.subjectSpeakerIds,
            isAudio: file.type.startsWith('audio/'),
        });
    }

    /** Lit l'orientation dans le fichier (sans l'envoyer). */
    probe(row) {
        if (!row.file.type.startsWith('video/')) {
            return;
        }
        const media = document.createElement('video');
        const url = URL.createObjectURL(row.file);
        media.preload = 'metadata';
        media.onloadedmetadata = () => {
            if (media.videoWidth && media.videoHeight) {
                row.orientation = media.videoHeight > media.videoWidth ? 'portrait' : 'landscape';
                // Aucun format dans le nom : l'orientation de la vidéo le donne.
                if (!row.format && row.status === 'pending') {
                    row.format = row.orientation === 'portrait' ? 'MOBILE' : 'TV';
                    row.guesses.format = `format déduit de la vidéo, ${row.orientation === 'portrait' ? 'verticale' : 'horizontale'} (${row.format})`;
                }
            }
            URL.revokeObjectURL(url);
            // Tout le tableau : un format ajouté peut créer ou lever un doublon.
            this.render();
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
            // Choisi à la main : plus rien à vérifier sur ce champ.
            delete row.guesses[field];
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

        // Lectures approchées du nom de fichier : non bloquantes, mais à relire.
        const warnings = [this.warningFor(row), ...Object.values(row.guesses)].filter(Boolean);
        return warnings.length > 0
            ? { kind: 'warning', blocked: false, label: `À vérifier : ${warnings.join(' ; ')}` }
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
            row.errorDetail = null;
            row.conflict = false;
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

    /**
     * Envoi d'un fichier en trois temps : position déjà reçue par le serveur
     * (reprise), morceaux un par un, puis enregistrement dans le contenu.
     */
    async upload(row) {
        row.status = 'uploading';
        row.progress = 0;
        this.renderRow(row);

        try {
            const size = row.file.size;
            const uploadId = this.uploadIdFor(row.file);
            const status = await this.withRetry(row, () => this.request('GET', `${this.configValue.statusUrl}?upload=${uploadId}`));
            let offset = Math.min(status.body.offset, size);
            if (offset > 0) {
                this.setProgress(row, (offset / size) * UPLOAD_SHARE);
                this.setStateLabel(row, `Reprise à ${Math.floor((offset / size) * 100)} %…`);
            }

            while (offset < size) {
                const end = Math.min(offset + this.configValue.chunkSize, size);
                const start = offset;
                const url = `${this.configValue.chunkUrl}?upload=${uploadId}&offset=${start}&total=${size}`;
                const response = await this.withRetry(row, () => this.request('POST', url, row.file.slice(start, end), {
                    headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': this.configValue.csrfToken },
                    timeout: CHUNK_TIMEOUT,
                    onProgress: (loaded) => this.setProgress(row, ((start + loaded) / size) * UPLOAD_SHARE),
                }), (r) => r.xhr.status === 409 && Number.isInteger(r.body?.offset));
                // 409 : morceau déjà reçu (réponse perdue) ou position décalée, on se recale.
                offset = response.body.offset;
                this.setProgress(row, (offset / size) * UPLOAD_SHARE);
            }

            this.setProgress(row, UPLOAD_SHARE);
            this.setStateLabel(row, 'Enregistrement…');
            const data = new FormData();
            data.append('_token', this.configValue.csrfToken);
            data.append('upload', uploadId);
            data.append('name', row.file.name);
            data.append('size', String(size));
            data.append('speaker', row.speakerId);
            data.append('language', row.languageId);
            data.append('format', row.format);
            data.append('replace', row.replace ? '1' : '0');
            // Pas de renvoi automatique ici : si la réponse s'est perdue,
            // le fichier a pu être rangé, et l'éditeur doit le vérifier.
            const { xhr, body } = await this.request('POST', this.configValue.uploadUrl, data);
            if (xhr.status >= 200 && xhr.status < 300 && body?.videoId) {
                this.markDone(row, body);
            } else if (xhr.status === 0) {
                this.markFailed(row, { error: 'Connexion coupée pendant l’enregistrement final. Le fichier a pu être rangé malgré tout : vérifiez la liste des contenus avant de relancer l’envoi.' });
            } else {
                this.markFailed(row, this.describeFailure(xhr, body, row.file.size, this.configValue.uploadUrl));
            }
        } catch (failure) {
            this.markFailed(row, failure instanceof Error ? { error: `Erreur dans le navigateur : ${failure.message}. Rechargez la page puis relancez l’envoi.` } : failure);
        }
    }

    /**
     * Renvoie la requête tant que l'échec est passager (réseau coupé, délai
     * dépassé, serveur momentanément indisponible), avec une attente
     * croissante affichée dans la ligne. Toute autre réponse d'erreur est
     * définitive et levée comme message d'échec.
     */
    async withRetry(row, send, accept = () => false) {
        for (let attempt = 0; ; attempt++) {
            const response = await send();
            const { xhr, body, url } = response;
            // Une redirection (vers la connexion) répond 200 en HTML : ce n'est pas un succès.
            const redirected = xhr.responseURL && new URL(xhr.responseURL).pathname !== new URL(url, window.location.href).pathname;
            if ((xhr.status >= 200 && xhr.status < 300 && body !== null && !redirected) || accept(response)) {
                return response;
            }

            if (!RETRYABLE_STATUSES.includes(xhr.status)) {
                throw this.describeFailure(xhr, body, this.configValue.chunkSize, url);
            }
            if (attempt >= RETRY_DELAYS.length) {
                const failure = this.describeFailure(xhr, body, this.configValue.chunkSize, url);
                throw {
                    ...failure,
                    error: `Envoi abandonné après ${RETRY_DELAYS.length} nouveaux essais. ${failure.error} `
                        + 'La partie déjà reçue reste sur le serveur : « Réessayer les échecs » reprendra à partir de là.',
                };
            }

            await this.waitBeforeRetry(row, RETRY_DELAYS[attempt], attempt + 1, xhr.status);
        }
    }

    async waitBeforeRetry(row, seconds, attempt, status) {
        const reason = status === 0 ? 'Connexion perdue' : `Serveur indisponible (${status})`;
        for (let left = seconds; left > 0; left--) {
            this.setStateLabel(row, `${reason} : nouvel essai dans ${left} s (essai ${attempt}/${RETRY_DELAYS.length})`);
            await new Promise((resolve) => { setTimeout(resolve, 1000); });
        }
        // Hors ligne : inutile d'insister, on attend le retour du réseau.
        if (!navigator.onLine) {
            this.setStateLabel(row, 'Hors ligne : l’envoi reprendra dès le retour de la connexion');
            await new Promise((resolve) => { window.addEventListener('online', resolve, { once: true }); });
        }
        this.setStateLabel(row, 'Reprise de l’envoi…');
    }

    /** Requête XHR qui ne rejette jamais : statut 0 pour une coupure ou un délai dépassé. */
    request(method, url, body = null, { headers = {}, timeout = 0, onProgress = null } = {}) {
        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            const done = () => resolve({ xhr, url, body: this.parseJson(xhr.responseText) });
            xhr.open(method, url);
            xhr.timeout = timeout;
            xhr.setRequestHeader('Accept', 'application/json');
            Object.entries(headers).forEach(([name, value]) => xhr.setRequestHeader(name, value));
            if (onProgress) {
                xhr.upload.addEventListener('progress', (e) => onProgress(e.loaded));
            }
            ['load', 'error', 'timeout', 'abort'].forEach((type) => xhr.addEventListener(type, done));
            xhr.send(body);
        });
    }

    /**
     * Même fichier (nom, taille, date de modification) = même identifiant :
     * redéposé après une coupure ou un rechargement, il reprend où il en était.
     */
    uploadIdFor(file) {
        let hash = 0x811c9dc5;
        for (let i = 0; i < file.name.length; i++) {
            hash = Math.imul(hash ^ file.name.charCodeAt(i), 0x01000193);
        }
        return `f-${file.size.toString(36)}-${file.lastModified.toString(36)}-${(hash >>> 0).toString(36)}`;
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

    /**
     * Message d'échec lisible. Le serveur explique lui-même les erreurs qu'il
     * sait traiter (`error`, plus `detail` et `reference` Sentry pour une
     * erreur imprévue) ; le reste vient d'un intermédiaire (serveur web,
     * proxy) ou d'un plantage PHP et se déduit du code HTTP.
     */
    describeFailure(xhr, body, bytes, url) {
        if (body?.error) {
            return body;
        }

        // Session expirée : le pare-feu redirige vers la page de connexion,
        // que la requête suit sans le dire.
        const target = new URL(url, window.location.href).pathname;
        if (xhr.responseURL && new URL(xhr.responseURL).pathname !== target) {
            return { error: 'Vous avez été déconnecté : rouvrez l’administration dans un autre onglet pour vous reconnecter, puis revenez ici et cliquez sur « Réessayer les échecs ».' };
        }

        const size = formatSize(bytes);
        const detail = body?.detail && body.detail !== body?.title ? body.detail : this.htmlTitle(xhr.responseText);
        const messages = {
            0: 'Connexion au serveur perdue (réseau coupé ou délai dépassé).',
            401: 'Vous avez été déconnecté : reconnectez-vous dans un autre onglet, puis réessayez.',
            403: 'Accès refusé : votre compte n’a pas (ou plus) le droit d’importer des fichiers.',
            404: 'Adresse d’envoi introuvable : le sujet a peut-être été supprimé. Rechargez la page.',
            408: 'Le serveur a abandonné la réception, trop lente. Relancez l’envoi, si possible sur une connexion plus stable.',
            413: `Envoi refusé par le serveur web : ${size} en une requête dépasse la taille maximale qu’il accepte (réglage client_max_body_size sous Nginx, LimitRequestBody sous Apache). À signaler à l’administrateur du serveur.`,
            500: `Erreur interne du serveur pendant l’enregistrement (${size}). Causes fréquentes : délai d’exécution ou mémoire de PHP dépassés. Relancez l’envoi ; si l’échec se répète, signalez-le avec le nom du fichier.`,
            502: 'Le serveur web n’a pas obtenu de réponse de PHP (PHP arrêté ou planté pendant l’enregistrement). Relancez l’envoi ; si l’échec se répète, signalez-le.',
            503: 'Serveur momentanément indisponible (maintenance ou surcharge). Réessayez dans quelques minutes.',
            504: `Délai dépassé : le serveur a mis trop de temps à enregistrer ce fichier de ${size}. Il a pu être enregistré malgré tout : vérifiez le contenu avant de relancer l’envoi.`,
        };

        return {
            error: messages[xhr.status] ?? `Réponse inattendue du serveur (code HTTP ${xhr.status}).`,
            detail: detail ? `Réponse du serveur : ${detail}` : null,
        };
    }

    htmlTitle(text) {
        const match = /<title>([^<]*)<\/title>/i.exec(text ?? '');
        return match ? match[1].trim() : null;
    }

    markFailed(row, failure) {
        row.status = 'error';
        row.error = failure.error;
        row.errorDetail = [failure.detail, failure.reference ? `Référence Sentry : ${failure.reference}` : null].filter(Boolean).join(' · ') || null;
        row.conflict = Boolean(failure.conflict);
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
        const languages = this.configValue.languages.map((l) => ({ ...l, value: l.id }));
        const formats = this.configValue.formats.map((f) => ({ value: f }));
        const disabled = locked ? ' disabled' : '';

        tr.innerHTML = `
            <td class="bulk-import__file">
                <strong>${escapeHtml(row.file.name)}</strong>
                <small>${formatSize(row.file.size)}${row.orientation ? ` · ${row.orientation === 'portrait' ? 'verticale' : 'horizontale'}` : ''}${row.notes.length ? ` · ${escapeHtml(row.notes.join(' · '))}` : ''}</small>
            </td>
            <td><select data-field="speakerId" data-action="bulk-import#edit" aria-label="Intervenant"${disabled}>
                <option value="">— Choisir —</option>${this.speakerOptions(row.speakerId)}
            </select></td>
            <td><select data-field="languageId" data-action="bulk-import#edit" aria-label="Langue"${disabled}>
                <option value="">— Choisir —</option>${options(languages, row.languageId, (l) => l.name)}
            </select></td>
            <td><select data-field="format" data-action="bulk-import#edit" aria-label="Format"${disabled}>
                <option value="">—</option>${options(formats, row.format, (f) => f.value)}
            </select></td>
            <td class="bulk-import__state">
                <span class="bulk-import__label" data-role="label">${escapeHtml(state.label)}</span>
                ${row.status === 'error' && row.errorDetail ? `<small class="bulk-import__detail">${escapeHtml(row.errorDetail)}</small>` : ''}
                ${state.kind === 'replace' || ((row.replace || row.conflict) && !locked) ? `
                    <label class="bulk-import__replace"><input type="checkbox" data-field="replace" data-action="bulk-import#edit"${row.replace ? ' checked' : ''}> Remplacer</label>` : ''}
                ${row.status === 'uploading' || row.status === 'done' ? `
                    <span class="bulk-import__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.floor(row.progress * 100)}">
                        <span data-role="bar" style="width: ${Math.floor(row.progress * 100)}%"></span>
                    </span>` : ''}
                ${row.status === 'done' ? `<a href="${row.result.editUrl}">${this.resultLabel(row.result)} →</a>` : ''}
                ${row.status === 'done' && row.result.warning ? `<small class="bulk-import__detail">${escapeHtml(row.result.warning)}</small>` : ''}
            </td>
            <td>${locked ? '' : `<button type="button" class="bulk-import__remove" data-action="bulk-import#remove" aria-label="Retirer ${escapeHtml(row.file.name)}">✕</button>`}</td>`;

        return tr;
    }

    resultLabel(result) {
        if (result.created) {
            return 'Contenu créé et publié';
        }
        if (result.published) {
            return 'Contenu mis à jour et publié';
        }
        return result.status === 'Publié' ? 'Contenu mis à jour' : `Contenu mis à jour (reste ${result.status.toLowerCase()})`;
    }

    /**
     * Intervenants groupés par gouvernement : celui en place à la date du
     * conseil d'abord, puis les autres, puis ceux hors gouvernement.
     */
    speakerOptions(selected) {
        const { governments = [], preferredGovernmentId } = this.configValue;
        const groups = [
            ...governments.filter((g) => g.id === preferredGovernmentId),
            ...governments.filter((g) => g.id !== preferredGovernmentId),
            { id: null, label: 'Hors gouvernement' },
        ];

        return groups.map((group) => {
            const speakers = this.configValue.speakers
                .filter((s) => (s.governmentId ?? null) === group.id)
                .sort((a, b) => (a.code ?? '~').localeCompare(b.code ?? '~'));
            if (speakers.length === 0) {
                return '';
            }
            const label = group.id === preferredGovernmentId ? `${group.label} (en place à la date du conseil)` : group.label;
            const items = speakers.map((s) => `<option value="${s.id}"${s.id === selected ? ' selected' : ''}>${escapeHtml(s.code ? `${s.code} — ${s.name}` : s.name)}</option>`).join('');

            return `<optgroup label="${escapeHtml(label)}">${items}</optgroup>`;
        }).join('');
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
        const hidden = new Set(done.filter((r) => r.result.status !== 'Publié').map((r) => r.result.videoId)).size;
        const warnings = done.filter((r) => r.result.warning).length;

        this.reportTarget.hidden = false;
        this.reportTarget.classList.toggle('has-errors', failed.length > 0 || warnings > 0);
        this.reportTarget.querySelector('[data-role="text"]').textContent = [
            `${done.length} fichier${done.length > 1 ? 's' : ''} envoyé${done.length > 1 ? 's' : ''}`,
            `${created} contenu${created > 1 ? 's' : ''} créé${created > 1 ? 's' : ''}`,
            `${touched.size - hidden} contenu${touched.size - hidden > 1 ? 's' : ''} en ligne`,
            hidden ? `${hidden} resté${hidden > 1 ? 's' : ''} masqué${hidden > 1 ? 's' : ''}` : null,
            warnings ? `${warnings} avertissement${warnings > 1 ? 's' : ''} (voir le tableau)` : null,
            failed.length ? `${failed.length} échec${failed.length > 1 ? 's' : ''} (voir le tableau)` : null,
        ].filter(Boolean).join(' · ');
    }
}
