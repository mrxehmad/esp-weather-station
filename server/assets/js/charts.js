/**
 * Chart.js helpers for temperature dashboard
 * WordPress 2020 style charts
 */

// WordPress color palette
const WP_COLORS = {
    primary: '#0073aa',
    primaryHover: '#006799',
    success: '#46b450',
    warning: '#ffb900',
    danger: '#dc3232',
    gray: '#8c8f94',
    lightGray: '#f0f0f1'
};

/**
 * Create a temperature line chart
 * @param {string} canvasId - Canvas element ID
 * @param {Array} labels - Time labels
 * @param {Array} data - Temperature values
 */
function createTempChart(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Temperature (°C)',
                data: data,
                borderColor: WP_COLORS.primary,
                backgroundColor: 'rgba(0, 115, 170, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y.toFixed(2) + ' °C';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                },
                y: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    beginAtZero: false
                }
            }
        }
    });
}

/**
 * Create an RSSI line chart
 * @param {string} canvasId - Canvas element ID
 * @param {Array} labels - Time labels
 * @param {Array} data - RSSI values
 */
function createRssiChart(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'RSSI (dBm)',
                data: data,
                borderColor: WP_COLORS.gray,
                backgroundColor: 'rgba(140, 143, 148, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 2,
                pointHoverRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + ' dBm';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                y: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    min: -100,
                    max: 0
                }
            }
        }
    });
}

/**
 * Create a heap memory chart
 * @param {string} canvasId - Canvas element ID
 * @param {Array} labels - Time labels
 * @param {Array} data - Heap values in bytes
 */
function createHeapChart(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    
    // Convert to KB for display
    const dataKB = data.map(v => Math.round(v / 1024));
    
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Free Heap (KB)',
                data: dataKB,
                borderColor: WP_COLORS.success,
                backgroundColor: 'rgba(70, 180, 80, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 2,
                pointHoverRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + ' KB';
                        }
                    }
                },
                annotation: {
                    annotations: {
                        lowMemory: {
                            type: 'line',
                            yMin: 10, // 10KB threshold
                            yMax: 10,
                            borderColor: WP_COLORS.danger,
                            borderWidth: 2,
                            borderDash: [5, 5],
                            label: {
                                content: 'Low Memory Threshold',
                                enabled: true,
                                position: 'start'
                            }
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                y: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    beginAtZero: false
                }
            }
        }
    });
}

/**
 * Format timestamp for chart labels
 * @param {string} datetime - MySQL datetime string
 * @param {boolean} showDate - Include date in label
 * @returns {string} Formatted label
 */
function formatChartLabel(datetime, showDate = false) {
    const date = new Date(datetime + 'Z'); // Treat as UTC
    
    if (showDate) {
        return date.toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    return date.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Prepare data from API response for charts
 * @param {Array} readings - Array of reading objects
 * @param {string} field - Field name to extract
 * @returns {{labels: Array, data: Array}}
 */
function prepareChartData(readings, field) {
    const labels = [];
    const data = [];
    
    readings.forEach(r => {
        labels.push(formatChartLabel(r.ts));
        data.push(r[field]);
    });
    
    return { labels, data };
}

/**
 * Destroy existing chart before creating new one
 * @param {Chart|null} chart - Chart instance to destroy
 * @returns {null}
 */
function destroyChart(chart) {
    if (chart) {
        chart.destroy();
    }
    return null;
}
