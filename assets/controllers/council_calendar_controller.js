import { Controller } from '@hotwired/stimulus';

const MONTHS = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
const MONTHS_LONG = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const YEARS_PER_PAGE = 12;

/*
 * Calendrier des conseils des ministres : une grille de mois (ou d'années)
 * où seuls les mois ayant un conseil sont actifs. Un mois à plusieurs
 * conseils affiche ses dates à choisir. Choisir un conseil :
 * - liste des contenus : n'affiche que son panneau (cibles `panel`) ;
 * - formulaire de contenu : ne propose que ses sujets (cible `subject`,
 *   options portant data-council-id). Si la liste porte
 *   data-create-url-template (avec __ID__), une dernière option
 *   « + Nouveau sujet pour ce conseil » ouvre la création d'un sujet du
 *   conseil choisi.
 * Une cible `field` (champ caché d'un formulaire) reçoit l'id du conseil
 * choisi, pour le retrouver au rechargement (espace média).
 */
export default class extends Controller {
    static targets = ['title', 'prev', 'next', 'grid', 'dates', 'panel', 'subject', 'field'];
    static values = {
        sessions: Array,
        selected: Number,
        syncUrl: Boolean,
        // Seul le conseil affiché est rendu côté serveur : en choisir un autre recharge la page.
        navigate: Boolean,
        unit: { type: String, default: 'contenu' },
    };

    connect() {
        // sessionsValue : [{id, date: 'YYYY-MM-DD', count}], du plus récent au plus ancien.
        this.sessions = this.sessionsValue.map((s) => ({
            ...s,
            year: Number(s.date.slice(0, 4)),
            month: Number(s.date.slice(5, 7)) - 1,
            day: Number(s.date.slice(8, 10)),
        }));
        if (this.sessions.length === 0) {
            return;
        }

        const initialId = this.hasSubjectTarget
            ? Number(this.subjectTarget.selectedOptions[0]?.dataset.councilId)
            : this.selectedValue;
        const initial = this.sessions.find((s) => s.id === initialId) ?? this.sessions[0];
        this.years = [...new Set(this.sessions.map((s) => s.year))].sort((a, b) => a - b);
        if (this.hasSubjectTarget && this.subjectTarget.dataset.createUrlTemplate) {
            this.addCreateOption();
        }
        this.view = 'months';
        this.select(initial);
    }

    // ---- Actions -------------------------------------------------------

    prev() {
        this.shift(-1);
    }

    next() {
        this.shift(1);
    }

    toggleView() {
        this.view = this.view === 'months' ? 'years' : 'months';
        this.yearPageStart = this.pageStartFor(this.year);
        this.render();
    }

    pickYear(event) {
        this.year = Number(event.currentTarget.dataset.year);
        this.view = 'months';
        this.render();
    }

    pickMonth(event) {
        const month = Number(event.currentTarget.dataset.month);
        const inMonth = this.sessionsIn(this.year, month);
        // Le conseil le plus récent du mois ; les autres restent accessibles via les dates.
        this.select(inMonth[0]);
    }

    pickSession(event) {
        this.select(this.sessions.find((s) => s.id === Number(event.currentTarget.dataset.id)));
    }

    // ---- État ----------------------------------------------------------

    select(session) {
        // Listes chargées conseil par conseil : un conseil sans panneau dans
        // la page s'ouvre en chargeant la sienne.
        if (this.navigateValue && !this.panelTargets.some((panel) => Number(panel.dataset.sessionId) === session.id)) {
            const url = new URL(window.location.href);
            url.searchParams.set('conseil', String(session.id));
            url.searchParams.delete('page');
            if (window.Turbo) {
                window.Turbo.visit(url.toString());
            } else {
                window.location.assign(url.toString());
            }

            return;
        }
        this.selected = session;
        this.year = session.year;
        this.panelTargets.forEach((panel) => {
            panel.hidden = Number(panel.dataset.sessionId) !== session.id;
        });
        this.fieldTargets.forEach((field) => {
            field.value = String(session.id);
        });
        if (this.hasSubjectTarget) {
            this.filterSubjects(session.id);
        }
        if (this.syncUrlValue) {
            const url = new URL(window.location.href);
            url.searchParams.set('conseil', String(session.id));
            // Garde l'état posé par Turbo pour ne pas casser le bouton « Retour ».
            history.replaceState(history.state, '', url);
        }
        this.render();
    }

    disconnect() {
        if (this.hasSubjectTarget) {
            this.subjectTarget.removeEventListener('change', this.onSubjectChange);
        }
    }

    addCreateOption() {
        const option = new Option('+ Nouveau sujet pour ce conseil…', '');
        option.dataset.create = 'true';
        option.className = 'subject-create-option';
        this.subjectTarget.append(option);
        this.previousSubject = this.subjectTarget.value;
        this.subjectTarget.addEventListener('change', this.onSubjectChange);
    }

    onSubjectChange = () => {
        const select = this.subjectTarget;
        if (select.selectedOptions[0]?.dataset.create) {
            // On garde le sujet précédent affiché le temps de quitter la page.
            select.value = this.previousSubject;
            window.location.assign(select.dataset.createUrlTemplate.replace('__ID__', String(this.selected.id)));

            return;
        }
        this.previousSubject = select.value;
    };

    filterSubjects(councilId) {
        const select = this.subjectTarget;
        const options = Array.from(select.options).filter((option) => option.dataset.councilId);
        options.forEach((option) => {
            const visible = Number(option.dataset.councilId) === councilId;
            option.hidden = !visible;
            option.disabled = !visible;
        });
        select.querySelectorAll('optgroup').forEach((group) => {
            group.hidden = !Array.from(group.children).some((option) => !option.hidden);
        });

        if (select.selectedOptions[0]?.hidden !== false) {
            const first = options.find((option) => !option.hidden);
            if (first) {
                select.value = first.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }

    shift(direction) {
        if (this.view === 'years') {
            this.yearPageStart += direction * YEARS_PER_PAGE;
        } else {
            const target = direction < 0
                ? [...this.years].reverse().find((y) => y < this.year)
                : this.years.find((y) => y > this.year);
            if (target === undefined) {
                return;
            }
            this.year = target;
        }
        this.render();
    }

    sessionsIn(year, month) {
        return this.sessions.filter((s) => s.year === year && (month === undefined || s.month === month));
    }

    pageStartFor(year) {
        const first = this.years[0];
        return first + Math.floor((year - first) / YEARS_PER_PAGE) * YEARS_PER_PAGE;
    }

    // ---- Rendu ---------------------------------------------------------

    render() {
        if (this.view === 'years') {
            this.renderYears();
        } else {
            this.renderMonths();
        }
        this.renderDates();
    }

    renderMonths() {
        this.titleTarget.textContent = String(this.year);
        this.titleTarget.setAttribute('aria-label', `Année ${this.year}, choisir une autre année`);
        this.prevTarget.disabled = !this.years.some((y) => y < this.year);
        this.nextTarget.disabled = !this.years.some((y) => y > this.year);

        this.gridTarget.innerHTML = '';
        MONTHS.forEach((label, month) => {
            const count = this.sessionsIn(this.year, month).length;
            const isSelected = this.selected.year === this.year && this.selected.month === month;
            const button = this.cell(label, isSelected, count === 0);
            button.dataset.month = String(month);
            button.dataset.action = 'council-calendar#pickMonth';
            button.setAttribute('aria-label', count
                ? `${MONTHS_LONG[month]} ${this.year}, ${count} conseil${count > 1 ? 's' : ''}`
                : `${MONTHS_LONG[month]} ${this.year}, aucun conseil`);
            if (count > 1) {
                button.insertAdjacentHTML('beforeend', `<span class="council-calendar__badge" aria-hidden="true">${count}</span>`);
            }
            this.gridTarget.append(button);
        });
    }

    renderYears() {
        const start = this.yearPageStart;
        const end = start + YEARS_PER_PAGE - 1;
        this.titleTarget.textContent = `${start}–${end}`;
        this.titleTarget.setAttribute('aria-label', 'Revenir aux mois');
        this.prevTarget.disabled = !this.years.some((y) => y < start);
        this.nextTarget.disabled = !this.years.some((y) => y > end);

        this.gridTarget.innerHTML = '';
        for (let year = start; year <= end; ++year) {
            const button = this.cell(String(year), year === this.selected.year, !this.years.includes(year));
            button.dataset.year = String(year);
            button.dataset.action = 'council-calendar#pickYear';
            this.gridTarget.append(button);
        }
    }

    renderDates() {
        const { year, month } = this.selected;
        const inMonth = this.sessionsIn(year, month);
        this.datesTarget.hidden = this.view !== 'months' || year !== this.year || inMonth.length < 2;
        if (this.datesTarget.hidden) {
            return;
        }

        const list = this.datesTarget.querySelector('[data-role="list"]');
        list.innerHTML = '';
        [...inMonth].sort((a, b) => a.day - b.day).forEach((session) => {
            const button = this.cell(`${session.day} ${MONTHS[month].toLowerCase()}`, session.id === this.selected.id, false);
            button.classList.add('council-calendar__date');
            button.dataset.id = String(session.id);
            button.dataset.action = 'council-calendar#pickSession';
            button.setAttribute('aria-label', `Conseil du ${session.day} ${MONTHS_LONG[month]} ${year}, ${session.count} ${this.unitValue}${session.count > 1 ? 's' : ''}`);
            list.append(button);
        });
    }

    cell(label, isSelected, isDisabled) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'council-calendar__cell';
        button.textContent = label;
        button.disabled = isDisabled;
        button.setAttribute('aria-pressed', String(isSelected));
        button.classList.toggle('is-selected', isSelected);

        return button;
    }
}
