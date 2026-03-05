<script>
document.addEventListener('DOMContentLoaded', function () {

    <?php if ($reportType === 'weekly'): ?>

    // ── Chart hebdomadaire (barres) ──────────────────────────────────────────
    // On construit les labels sur 7 jours depuis weekStart
    const weekLabels = [];
    for (let i = 0; i < 7; i++) {
        const d = new Date('<?= $weekStart ?>');
        d.setDate(d.getDate() + i);
        weekLabels.push(d.toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: '2-digit' }));
    }

    // Correspondance des données PHP → tableau indexé par jour de semaine
    const dailyEntries = new Array(7).fill(0);
    const dailyExits   = new Array(7).fill(0);
    <?php foreach ($dailyStats as $day): ?>
    (function () {
        const dObj  = new Date('<?= $day['date'] ?>');
        const idx   = (dObj.getDay() + 6) % 7; // lundi=0
        dailyEntries[idx] = <?= (int)$day['entries'] ?>;
        dailyExits[idx]   = <?= (int)$day['exits'] ?>;
    })();
    <?php endforeach; ?>

    new Chart(document.getElementById('weeklyChart'), {
        type: 'bar',
        data: {
            labels: weekLabels,
            datasets: [
                { label: 'Entrées', data: dailyEntries, backgroundColor: '#4ecdc4' },
                { label: 'Sorties', data: dailyExits,   backgroundColor: '#ff6b6b' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
            plugins: { legend: { position: 'bottom' } }
        }
    });

    new Chart(document.getElementById('distributionChart'), {
        type: 'doughnut',
        data: {
            labels: ['Entrées', 'Sorties'],
            datasets: [{ data: [<?= (int)$stats['entries'] ?>, <?= (int)$stats['exits'] ?>], backgroundColor: ['#4ecdc4','#ff6b6b'], borderWidth: 0 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });

    <?php else: /* monthly */ ?>

    // ── Top 5 employés ───────────────────────────────────────────────────────
    const top5 = <?= json_encode(array_map(function($e) {
        return ['name' => mb_substr($e['prenom'].' '.$e['nom'], 0, 18), 'total' => (int)$e['total_pointages']];
    }, array_slice(array_filter($employees, fn($e) => $e['total_pointages'] > 0), 0, 5))) ?>;

    new Chart(document.getElementById('topEmployeesChart'), {
        type: 'bar',
        data: {
            labels: top5.map(e => e.name),
            datasets: [{ label: 'Pointages', data: top5.map(e => e.total), backgroundColor: '#4361ee' }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } },
            plugins: { legend: { display: false } }
        }
    });

    // ── Activité horaire réelle ───────────────────────────────────────────────
    const hourlyLabels = ['6h','7h','8h','9h','10h','11h','12h','13h','14h','15h','16h','17h','18h','19h','20h'];
    const hourlyData   = <?= json_encode($hourlyData ?? array_fill(0, 15, 0)) ?>;

    new Chart(document.getElementById('hoursChart'), {
        type: 'line',
        data: {
            labels: hourlyLabels,
            datasets: [{
                label: 'Pointages', data: hourlyData,
                borderColor: '#f72585', tension: .3, fill: true,
                backgroundColor: 'rgba(247,37,133,.1)'
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
            plugins: { legend: { display: false } }
        }
    });

    <?php endif; ?>
});