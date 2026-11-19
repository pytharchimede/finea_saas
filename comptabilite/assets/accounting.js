'use strict';
document.querySelectorAll('[data-confirm]').forEach(button => {
    button.addEventListener('click', event => {
        if (!window.confirm(button.dataset.confirm)) event.preventDefault();
    });
});
document.getElementById('print-report')?.addEventListener('click', () => window.print());
document.getElementById('exercise-filter')?.addEventListener('change', event => {
    const form = event.target.form;
    // New exercises have their own date boundaries; let the server select them.
    form.querySelectorAll('[name="from"],[name="to"]').forEach(input => { input.disabled = true; });
    form.requestSubmit();
});
const accountSearch = document.getElementById('account-search');
if (accountSearch) {
    const level = document.getElementById('account-level');
    const accountClass = document.getElementById('account-class');
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const rows = Array.from(document.querySelectorAll('#accounts-table tbody tr'), row => ({
        row, code: row.dataset.code, postable: row.dataset.postable === '1', text: normalize(row.textContent)
    }));
    const filterAccounts = () => {
        const terms = normalize(accountSearch.value).trim().split(/\s+/).filter(Boolean);
        let count = 0;
        rows.forEach(({row, code, postable, text}) => {
            const matchesLevel = !level.value
                || (['1', '2', '3'].includes(level.value) && code.length === Number(level.value))
                || (level.value === 'detail' && code.length >= 4)
                || (level.value === 'postable' && postable)
                || (level.value === 'grouping' && !postable);
            row.hidden = !(matchesLevel && (!accountClass.value || code.startsWith(accountClass.value)) && terms.every(term => text.includes(term)));
            if (!row.hidden) count++;
        });
        document.getElementById('account-count').textContent = count + ' résultat' + (count === 1 ? '' : 's') + ' sur ' + rows.length;
        document.getElementById('account-empty').hidden = count !== 0;
    };
    accountSearch.addEventListener('input', filterAccounts);
    level.addEventListener('change', filterAccounts);
    accountClass.addEventListener('change', filterAccounts);
    filterAccounts();
}
const lines = document.getElementById('entry-lines');
if (lines) {
    const lineTemplate = lines.firstElementChild.cloneNode(true);
    const parse = value => {
        value = value.trim().replace(',', '.');
        if (value === '') return 0n;
        if (!/^\d{1,12}(\.\d{1,2})?$/.test(value)) return null;
        const [whole, fraction = ''] = value.split('.');
        return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    };
    const format = value => {
        const negative = value < 0n;
        if (negative) value = -value;
        return (negative ? '-' : '') + (value / 100n).toLocaleString('fr-FR') + ',' + String(value % 100n).padStart(2, '0');
    };
    const recalculate = () => {
        let debit = 0n, credit = 0n, valid = true;
        lines.querySelectorAll('[data-side]').forEach(input => {
            const amount = parse(input.value);
            if (amount === null) valid = false;
            else if (input.dataset.side === 'debit') debit += amount;
            else credit += amount;
        });
        document.getElementById('total-debit').textContent = format(debit);
        document.getElementById('total-credit').textContent = format(credit);
        const state = document.getElementById('balance-check');
        state.className = 'notice ' + (valid && debit === credit && debit > 0n ? 'success' : '');
        state.textContent = !valid ? 'Vérifiez les montants saisis.' : debit === credit && debit > 0n ? 'Écriture équilibrée. Enregistrez le brouillon pour joindre les pièces et valider.' : 'Écart débit − crédit : ' + format(debit - credit) + ' XOF. Le brouillon peut être enregistré, mais sa validation exige l’équilibre.';
        lines.querySelectorAll('.remove-line').forEach(button => { button.disabled = lines.children.length <= 2; });
    };
    const renumber = () => lines.querySelectorAll('tr').forEach((row, index) => {
        row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/lines\[\d+\]/, 'lines[' + index + ']'); });
    });
    document.getElementById('add-line').addEventListener('click', () => {
        if (lines.children.length >= 100) return;
        const row = lineTemplate.cloneNode(true);
        row.querySelectorAll('input,select').forEach(input => { input.value = ''; });
        lines.append(row); renumber(); recalculate();
        window.AccountingControls?.enhance(row);
        row.querySelector('[role="combobox"],select').focus();
    });
    lines.addEventListener('click', event => {
        const button = event.target.closest('.remove-line');
        if (button && lines.children.length > 2) {
            const row = button.closest('tr');
            window.AccountingControls?.destroy(row);
            row.remove(); renumber(); recalculate();
        }
    });
    lines.addEventListener('input', recalculate);
    recalculate();
}

window.AccountingControls?.enhance(document.querySelector('main'));
