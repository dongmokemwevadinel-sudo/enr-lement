
// ── Graphiques ────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    // Donut : répartition entrées / sorties
    new Chart(document.getElementById('typeChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Entrées', 'Sorties'],
            datasets: [{ data: [<?= (int)$stats['entries'] ?>, <?= (int)$stats['exits'] ?>], backgroundColor: ['#4ecdc4','#ff6b6b'], borderWidth: 0 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });

    // Barres : activité horaire
    const hourlyLabels  = <?= json_encode(array_map(fn($d) => $d['mounth'].'m', $hourlyActivity)) ?>;
    const hourlyEntries = <?= json_encode(array_column($hourlyActivity, 'entries')) ?>;
    const hourlyExits   = <?= json_encode(array_column($hourlyActivity, 'exits')) ?>;

    const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
    new Chart(hourlyCtx, {
        type: 'bar',
        data: {
            labels: hourlyLabels,
            datasets: [
                { label: 'Entrées', data: hourlyEntries, backgroundColor: '#4ecdc4' },
                { label: 'Sorties', data: hourlyExits,   backgroundColor: '#ff6b6b' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
            plugins: { legend: { position: 'bottom' } }
        }
    });
});