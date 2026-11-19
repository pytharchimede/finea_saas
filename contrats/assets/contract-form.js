(function () {
    const start = document.querySelector('input[name="date_debut"]');
    const duration = document.querySelector('input[name="delai_jours"]');
    const end = document.querySelector('input[name="date_fin"]');
    if (!start || !duration || !end) return;

    end.readOnly = true;
    end.setAttribute('aria-readonly', 'true');
    end.title = 'Calculée automatiquement à partir du début prévu et du délai';

    const calculate = () => {
        const days = Number.parseInt(duration.value, 10);
        if (!start.value || !Number.isInteger(days) || days < 1) {
            end.value = '';
            return;
        }
        const date = new Date(start.value + 'T12:00:00');
        date.setDate(date.getDate() + days);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        end.value = `${year}-${month}-${day}`;
    };

    start.addEventListener('change', calculate);
    duration.addEventListener('input', calculate);
    calculate();
})();
