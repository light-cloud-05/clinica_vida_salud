'use strict';
(() => {
    const source = document.getElementById('report-chart-data');
    if (!source || typeof Chart !== 'function') return;
    let data;
    try { data = JSON.parse(source.dataset.report); } catch (_) { return; }
    const colors = ['#2474a5', '#4e60b3', '#087f87', '#8a6b8e', '#b97822'];
    const metrics = [['horas_programadas_periodo', 'Programadas', '#2474a5'], ['horas_ocupadas', 'Ocupadas', '#4e60b3'], ['horas_atendidas', 'Atendidas (incluidas en ocupadas)', '#087f87'], ['horas_disponibles', 'Libres futuras', '#49a88d']];
    document.querySelectorAll('[data-report-chart]').forEach((canvas) => {
        const key = canvas.dataset.reportChart, series = data[key];
        if (!series || !series.labels.length || (key === 'states' && !series.values.some(Number))) {
            canvas.parentElement.classList.add('report-canvas-empty');
            canvas.parentElement.append(document.createTextNode('Sin datos para graficar con estos filtros.'));
            canvas.hidden = true;
            return;
        }
        const state = key === 'states';
        const options = {responsive: true, maintainAspectRatio: false, animation: false, plugins: {legend: {position: 'bottom', labels: {boxWidth: 10, font: {size: 11}}}}};
        if (!state) options.scales = {y: {beginAtZero: true, title: {display: true, text: 'Horas'}}};
        new Chart(canvas, {type: state ? 'doughnut' : 'bar', data: {labels: series.labels, datasets: state ? [{data: series.values, backgroundColor: colors, borderWidth: 2}] : metrics.map(([field, label, color]) => ({label, data: series.rows.map(row => Number(row[field] || 0)), backgroundColor: color, borderRadius: 3}))}, options});
    });
    const links = document.querySelectorAll('.report-sidebar a[href^="#"]');
    links.forEach(link => link.addEventListener('click', () => {links.forEach(item => item.removeAttribute('aria-current')); link.setAttribute('aria-current', 'location');}));
})();
