// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Filter the quiz table by name, drive its select-all checkbox, and mark a preview
 * stale when the settings or the selection change after it.
 *
 * The select-all acts on the rows the filter shows only; hidden rows keep their
 * ticks, and the select-all reflects the shown rows only. The stale bar and the
 * disabled Apply changes button are a convenience: the server refuses an apply
 * whose request, selection or quizzes no longer match the preview's fingerprint.
 *
 * @module     tool_quizbulkedit/quiztable
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {string[]} Names of form controls whose changes do not make a preview stale. */
const UNWATCHED = ['sesskey', 'courseid', 'fingerprint', 'confirmreset'];

/**
 * Whether an element's table row is shown (not hidden by the name filter).
 *
 * @param {HTMLElement} element an element in the table.
 * @returns {boolean}
 */
const shown = element => {
    const row = element.closest('tr');
    return !row || !row.hidden;
};

/**
 * Set up the name filter and the select-all checkbox of the quiz table.
 *
 * @param {HTMLElement} wrapper the quiz table wrapper.
 */
const initTable = wrapper => {
    const selectAll = wrapper.querySelector('[data-region="tool_quizbulkedit-selectall"]');
    const checkboxes = () => Array.from(wrapper.querySelectorAll('[data-region="tool_quizbulkedit-select"]'));
    const shownCheckboxes = () => checkboxes().filter(shown);

    /**
     * Tick the select-all checkbox only when every quiz the filter shows is selected.
     */
    const syncSelectAll = () => {
        if (!selectAll) {
            return;
        }
        const rows = shownCheckboxes();
        selectAll.checked = rows.length > 0 && rows.every(checkbox => checkbox.checked);
    };

    if (selectAll) {
        selectAll.addEventListener('click', e => {
            // Only the rows the filter shows; hidden rows keep their ticks.
            shownCheckboxes().forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
        });
    }
    checkboxes().forEach(checkbox => checkbox.addEventListener('change', syncSelectAll));
    syncSelectAll();

    const filter = wrapper.querySelector('[data-region="tool_quizbulkedit-filter"]');
    const noMatch = wrapper.querySelector('[data-region="tool_quizbulkedit-nomatch"]');
    if (filter) {
        const applyFilter = () => {
            const text = filter.value.trim().toLowerCase();
            let anyShown = false;
            wrapper.querySelectorAll('[data-region="tool_quizbulkedit-quizrow"]').forEach(row => {
                row.hidden = text !== '' && !(row.dataset.name || '').toLowerCase().includes(text);
                anyShown = anyShown || !row.hidden;
            });
            if (noMatch) {
                noMatch.hidden = anyShown;
            }
            syncSelectAll();
        };
        filter.addEventListener('input', applyFilter);
        filter.addEventListener('change', applyFilter);
    }
};

/**
 * Mark the preview stale and disable Apply changes when a watched control changes.
 *
 * @param {HTMLFormElement} form the settings form.
 * @param {string} formid its id.
 */
const initStale = (form, formid) => {
    const preview = form.querySelector('[data-region="tool_quizbulkedit-preview"]');
    if (!preview) {
        return;
    }
    const stalebar = preview.querySelector('[data-region="tool_quizbulkedit-stale"]');
    // The bar sits inside an always-present live region; its text is added only
    // when the preview goes stale, so screen readers announce the change.
    const staletext = stalebar ? stalebar.textContent.trim() : '';
    if (stalebar) {
        stalebar.textContent = '';
    }

    /**
     * The watched controls: the form's own elements plus the quiz checkboxes that submit with it.
     *
     * @returns {HTMLElement[]}
     */
    const controls = () => {
        const all = new Set(Array.from(form.elements));
        document.querySelectorAll('[form="' + formid + '"]').forEach(element => all.add(element));
        return Array.from(all).filter(element => element.name && element.type !== 'submit' &&
            !element.name.startsWith('_qf__') && !UNWATCHED.includes(element.name));
    };

    /**
     * Snapshot the watched controls' values. Keys are the name plus the index among
     * same-name controls, so an advcheckbox's hidden input and its checkbox stay distinct.
     *
     * @returns {Map<string, string|boolean>}
     */
    const snapshot = () => {
        const counts = {};
        const values = new Map();
        controls().forEach(element => {
            const index = counts[element.name] || 0;
            counts[element.name] = index + 1;
            const checkable = element.type === 'checkbox' || element.type === 'radio';
            values.set(element.name + '#' + (checkable ? element.value + '#' : '') + index,
                checkable ? element.checked : element.value);
        });
        return values;
    };

    const initial = snapshot();

    /**
     * Whether the live snapshot differs from the one taken when the preview was shown.
     *
     * @returns {boolean}
     */
    const changed = () => {
        const live = snapshot();
        if (live.size !== initial.size) {
            return true;
        }
        for (const [key, value] of live) {
            if (!initial.has(key) || initial.get(key) !== value) {
                return true;
            }
        }
        return false;
    };

    let isstale = false;
    const compare = () => {
        const stale = changed();
        if (stalebar && stale !== isstale) {
            stalebar.textContent = stale ? staletext : '';
            stalebar.hidden = !stale;
        }
        isstale = stale;
        const apply = document.getElementById('id_apply');
        if (apply) {
            apply.disabled = stale;
        }
    };

    // Deferred so that the select-all handler has ticked its rows first.
    ['change', 'input', 'click'].forEach(type => {
        document.addEventListener(type, () => {
            setTimeout(compare, 0);
        });
    });
};

/**
 * Initialise the quiz table and the settings form's preview.
 *
 * @param {string} formid the id of the settings form.
 */
export const init = formid => {
    const wrapper = document.querySelector('[data-region="tool_quizbulkedit-quiztable"][data-formid="' + formid + '"]');
    if (wrapper) {
        initTable(wrapper);
    }
    const form = document.getElementById(formid);
    if (form) {
        initStale(form, formid);
    }
};
