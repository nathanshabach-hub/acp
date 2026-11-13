(function () {
    'use strict';
    const canvas = document.getElementById('performance-chart');
    if (!canvas) return;
    if (typeof Chart === 'undefined') {
        document.getElementById('report-chart-error').hidden = false;
        return;
    }
    const data = JSON.parse(document.getElementById('report-chart-data').textContent);
    const labels = ['Events entered', '1st place', '2nd place', '3rd place', '4th place', '5th place', '6th place'];
    const colors = ['#1c2452', '#187345', '#a04c00', '#703bb2', '#006c85', '#b5244f', '#625600'];
    const datasets = labels.map(function (label, i) {
        return {
            label: label,
            data: i === 0 ? data.events : data.placements[i],
            borderColor: colors[i],
            backgroundColor: colors[i],
            borderDash: i < 3 ? [] : [i + 1, 3],
            pointStyle: ['circle', 'rect', 'triangle', 'crossRot', 'star', 'rectRot', 'cross'][i],
            spanGaps: false,
            tension: 0
        };
    });
    const chart = new Chart(canvas, {
        type: 'line',
        data: { labels: data.labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { title: { display: true, text: 'Conventions entered (chronological)' } },
                y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Event entries / placements' } }
            }
        }
    });
    const controls = document.getElementById('report-series');
    labels.forEach(function (label, index) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-primary';
        button.textContent = label;
        button.setAttribute('aria-pressed', 'true');
        button.addEventListener('click', function () {
            const visible = !chart.isDatasetVisible(index);
            chart.setDatasetVisibility(index, visible);
            button.setAttribute('aria-pressed', String(visible));
            chart.update();
        });
        controls.appendChild(button);
    });
}());
