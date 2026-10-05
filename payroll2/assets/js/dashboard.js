// assets/js/dashboard.js
// Reads data injected by dashboard.php as window.PAYROLL_DATA

(function () {
    const d = window.PAYROLL_DATA;
    if (!d || d.n < 2) return;

    const extLabels = [...d.labels, 'Forecast'];
    const extNet    = [...d.netData, null];
    const extPred   = [...Array(d.labels.length).fill(null), d.predicted];
    const allNet    = [...d.netData, d.predicted];

    // Build trend line via least-squares
    const meanX = (allNet.length - 1) / 2;
    const meanY = allNet.reduce((s, v) => s + v, 0) / allNet.length;
    let num = 0, den = 0;
    allNet.forEach((y, i) => { num += (i - meanX) * (y - meanY); den += (i - meanX) ** 2; });
    const slope = den ? num / den : 0;
    const trendLine = allNet.map((_, i) => meanY + slope * (i - meanX));

    new Chart(document.getElementById('forecastChart'), {
        data: {
            labels: extLabels,
            datasets: [
                {
                    type: 'bar', label: 'Gross Pay',
                    data: [...d.groData, null],
                    backgroundColor: 'rgba(59,130,246,.15)',
                    borderColor: '#3b82f6', borderWidth: 1.5, borderRadius: 5,
                },
                {
                    type: 'bar', label: 'Net Pay',
                    data: extNet,
                    backgroundColor: 'rgba(34,197,94,.18)',
                    borderColor: '#22c55e', borderWidth: 1.5, borderRadius: 5,
                },
                {
                    type: 'bar', label: 'Forecast (Net)',
                    data: extPred,
                    backgroundColor: 'rgba(14,165,233,.25)',
                    borderColor: '#0ea5e9', borderWidth: 2, borderRadius: 5,
                },
                {
                    type: 'line', label: 'Trend Line',
                    data: trendLine,
                    borderColor: '#f59e0b', borderWidth: 2,
                    borderDash: [6, 4], pointRadius: 0, fill: false, tension: 0.3,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.parsed.y !== null
                            ? `${ctx.dataset.label}: ₱${ctx.parsed.y.toLocaleString('en', { minimumFractionDigits: 2 })}` : null
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    ticks: { callback: v => '₱' + (v / 1000).toFixed(0) + 'k', font: { size: 11 } },
                    grid: { color: '#f1f5f9' }
                },
                x: { ticks: { font: { size: 11 } }, grid: { display: false } }
            }
        }
    });
})();
