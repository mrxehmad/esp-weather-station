function tsLabel(s) {
    if (!s) {
        return '';
    }

    var d = new Date(String(s).replace(' ', 'T') + 'Z');

    if (isNaN(d)) {
        return s;
    }

    return d.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function makeChart(id, labels, datasets, yTitle) {
    var el = document.getElementById(id);

    if (!el || typeof Chart === 'undefined') {
        return;
    }

    datasets.forEach(function (ds) {
        ds.spanGaps = true;
        ds.pointRadius = 1;
        ds.borderWidth = 2;
    });

    new Chart(el.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels.map(tsLabel),
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    labels: {
                        boxWidth: 12
                    }
                }
            },
            scales: {
                y: {
                    title: {
                        display: !!yTitle,
                        text: yTitle || ''
                    }
                },
                x: {
                    ticks: {
                        maxTicksLimit: 10
                    }
                }
            }
        }
    });
}
