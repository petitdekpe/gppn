/*
 * Lecture souple des noms de fichiers de l'import en masse.
 *
 * La convention reste INTERVENANT-LANGUE-FORMAT, mais le nom n'a pas à la
 * suivre à la lettre : il est découpé en mots (tirets, espaces, points,
 * soulignés), dans n'importe quel ordre, et chaque mot est rapproché :
 * - du format (TV, MOBILE, AUDIO et leurs équivalents : HD, VERTICAL, MP3…) ;
 * - d'une langue, même mal orthographiée (BAATONU → Baatonou) ;
 * - d'un intervenant par son code (MCCMFAS), son sigle ou son nom de famille.
 * Chaque mot ne sert qu'une fois : les rapprochements les plus sûrs passent
 * en premier. Un rapprochement approximatif est signalé (`guesses`) pour
 * que l'éditeur le vérifie.
 */

export const normalize = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '');

const FORMAT_SYNONYMS = {
    TV: ['TV', 'TELE', 'TELEVISION', 'HD', '1080', '1080P', 'FHD', 'HORIZONTAL', 'HORIZONTALE', 'PAYSAGE', 'LANDSCAPE', '169', '16X9'],
    MOBILE: ['MOBILE', 'MOB', 'VERTICAL', 'VERTICALE', 'PORTRAIT', 'STORY', 'STORIES', 'REEL', 'REELS', 'STATUT', 'WHATSAPP', 'TIKTOK', '916', '9X16'],
    AUDIO: ['AUDIO', 'SON', 'MP3', 'RADIO', 'PODCAST', 'VOIX'],
};

/**
 * Autres noms d'une même langue (graphie ancienne ou voisine), trop
 * éloignés pour être rapprochés par ressemblance.
 */
const LANGUAGE_ALIASES = {
    IDATCHA: 'IDAASHA',
    BARIBA: 'BAATONOU',
};

/** Distance d'édition (insertions, suppressions, substitutions). */
export function levenshtein(a, b) {
    let previous = Array.from({ length: b.length + 1 }, (_, i) => i);
    for (let i = 1; i <= a.length; i++) {
        const current = [i];
        for (let j = 1; j <= b.length; j++) {
            current[j] = Math.min(previous[j] + 1, current[j - 1] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        }
        previous = current;
    }
    return previous[b.length];
}

/** Ressemblance entre 0 et 1 (1 = identiques). */
export function similarity(a, b) {
    if (a === b) {
        return 1;
    }
    return 1 - levenshtein(a, b) / Math.max(a.length, b.length, 1);
}

/**
 * Seuil selon la longueur du mot visé : un mot court n'est reconnu
 * qu'à l'identique (FON, MS), un mot long tolère une ou deux fautes.
 */
function threshold(length) {
    if (length <= 3) {
        return 1;
    }
    return length <= 5 ? 0.8 : 0.75;
}

const percent = (score) => `${Math.round(score * 100)} %`;

/**
 * @param {string} fileName nom du fichier, extension comprise
 * @param {{speakers: Array, languages: Array, formats: string[], preferredGovernmentId?: number|null, subjectSpeakerIds?: Set<number>, isAudio?: boolean}} config
 * @returns {{speakerId: number|null, languageId: number|null, format: string|null, guesses: Object<string, string>, notes: string[]}}
 */
export function matchFileName(fileName, config) {
    const words = fileName.replace(/\.[^.]+$/, '').split(/[^\p{L}\p{N}]+/u)
        .map((raw) => ({ raw, norm: normalize(raw) }))
        .filter((word) => word.norm !== '');

    // Mots seuls et paires de mots voisins (« MCC-MFAS », « IDAA SHA »).
    const pieces = words.map((word, i) => ({ raw: word.raw, norm: word.norm, used: [i] }));
    for (let i = 0; i + 1 < words.length; i++) {
        pieces.push({ raw: `${words[i].raw}-${words[i + 1].raw}`, norm: words[i].norm + words[i + 1].norm, used: [i, i + 1] });
    }

    const candidates = [
        ...formatCandidates(pieces, config.formats),
        ...languageCandidates(pieces, config.languages),
        ...speakerCandidates(pieces, config.speakers),
    // À score égal, le rapprochement qui couvre le plus de mots l'emporte :
    // « MCC-MFAS » désigne le ministre conseiller, pas le ministre « MFAS ».
    ].sort((a, b) => b.score - a.score || b.used.length - a.used.length || a.priority - b.priority);

    const result = { speakerId: null, languageId: null, format: null, guesses: {}, notes: [] };
    const taken = new Set();
    const assigned = new Set();
    if (config.isAudio) {
        result.format = 'AUDIO';
        assigned.add('format');
    }

    for (const candidate of candidates) {
        if (assigned.has(candidate.field) || candidate.used.some((i) => taken.has(i))) {
            continue;
        }
        assigned.add(candidate.field);
        candidate.used.forEach((i) => taken.add(i));
        if (candidate.field === 'speakerId') {
            resolveSpeaker(candidate, config, result);
        } else {
            result[candidate.field] = candidate.value;
        }
        if (candidate.score < 1 && result[candidate.field] !== null) {
            result.guesses[candidate.field] = `${candidate.what} « ${candidate.raw} » ${candidate.field === 'languageId' ? 'lue' : 'lu'} comme ${candidate.label} (${percent(candidate.score)})`;
        }
    }

    return result;
}

function formatCandidates(pieces, formats) {
    const found = [];
    for (const piece of pieces.filter((p) => p.used.length === 1)) {
        for (const format of formats) {
            for (const synonym of FORMAT_SYNONYMS[format] ?? [format]) {
                const score = similarity(piece.norm, synonym);
                if (score >= threshold(synonym.length)) {
                    found.push({ field: 'format', value: format, label: format, what: 'format', score, priority: 0, raw: piece.raw, used: piece.used });
                }
            }
        }
    }
    return found;
}

function languageCandidates(pieces, languages) {
    const found = [];
    for (const piece of pieces) {
        for (const language of languages) {
            const name = normalize(language.name);
            // Autre nom connu : reconnu, mais signalé comme une lecture approchée.
            const score = LANGUAGE_ALIASES[piece.norm] === name ? 0.99 : similarity(piece.norm, name);
            if (score >= threshold(name.length)) {
                found.push({ field: 'languageId', value: language.id, label: language.name, what: 'langue', score, priority: 2, raw: piece.raw, used: piece.used });
            }
        }
    }
    return found;
}

/**
 * Code de fichier (sigle précédé de MCC pour un ministre conseiller) >
 * sigle seul > nom de famille. Plusieurs intervenants à égalité (sigle
 * partagé, ministre reconduit dans un autre gouvernement) : départagés
 * ensuite par resolveSpeaker.
 */
function speakerCandidates(pieces, speakers) {
    const byPiece = new Map();
    const add = (piece, speaker, score) => {
        const key = piece.norm + piece.used.join(',');
        const entry = byPiece.get(key) ?? { piece, score: 0, speakers: [] };
        if (score > entry.score) {
            entry.score = score;
            entry.speakers = [speaker];
        } else if (score === entry.score && !entry.speakers.includes(speaker)) {
            entry.speakers.push(speaker);
        }
        byPiece.set(key, entry);
    };

    for (const piece of pieces) {
        for (const speaker of speakers) {
            const code = normalize(speaker.code);
            const sigle = normalize(speaker.sigle);
            if (code) {
                const score = similarity(piece.norm, code);
                if (score >= Math.max(0.8, threshold(code.length))) {
                    add(piece, speaker, score);
                }
            }
            if (sigle && sigle !== code) {
                const score = similarity(piece.norm, sigle) * 0.97;
                if (score >= Math.max(0.8, threshold(sigle.length)) * 0.97) {
                    add(piece, speaker, score);
                }
            }
            // Nom de famille (mots de 4 lettres et plus du nom complet).
            for (const part of String(speaker.name ?? '').split(/[^\p{L}]+/u).map(normalize).filter((p) => p.length >= 4)) {
                const score = similarity(piece.norm, part) * 0.95;
                if (score >= 0.8) {
                    add(piece, speaker, score);
                }
            }
        }
    }

    return [...byPiece.values()].map(({ piece, score, speakers: matched }) => ({
        field: 'speakerId',
        value: matched,
        label: matched.length === 1 ? (matched[0].code ?? matched[0].name) : matched.map((s) => s.code ?? s.name).join(' / '),
        what: 'intervenant',
        score,
        priority: 1,
        raw: piece.raw,
        used: piece.used,
    }));
}

function resolveSpeaker(candidate, config, result) {
    let matched = candidate.value;
    if (matched.length > 1 && config.preferredGovernmentId) {
        const inOffice = matched.filter((s) => s.governmentId === config.preferredGovernmentId);
        matched = inOffice.length > 0 ? inOffice : matched;
    }
    if (matched.length > 1 && config.subjectSpeakerIds) {
        const onSubject = matched.filter((s) => config.subjectSpeakerIds.has(s.id));
        matched = onSubject.length > 0 ? onSubject : matched;
    }
    if (matched.length === 1) {
        result.speakerId = matched[0].id;
        candidate.label = matched[0].code ? `${matched[0].code} (${matched[0].name})` : matched[0].name;
    } else {
        result.notes.push(`« ${candidate.raw} » correspond à plusieurs intervenants (${matched.map((s) => s.name).join(', ')}) : choisissez`);
    }
}
