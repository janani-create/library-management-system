/**
 * Library Management System - Client Side Application Logic
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Mobile Sidebar Toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.app-sidebar');
    const backdrop = document.querySelector('.sidebar-backdrop');

    if (sidebarToggle && sidebar && backdrop) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('show');
            backdrop.classList.toggle('show');
        });

        backdrop.addEventListener('click', () => {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
        });
    }

    // 2. Auto Dismiss Flash Alerts
    document.querySelectorAll('.alert-dismissible-auto').forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // 3. Quick Due Date Preset Buttons
    document.querySelectorAll('.btn-due-preset').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const days = parseInt(btn.dataset.days || 14);
            const targetInput = document.querySelector(btn.dataset.target || '#due_date');
            if (targetInput) {
                const targetDate = new Date();
                targetDate.setDate(targetDate.getDate() + days);
                targetInput.value = targetDate.toISOString().split('T')[0];
            }
        });
    });

    // 4. Client-side Table Filter / Search
    const tableSearchInput = document.getElementById('tableSearchInput');
    if (tableSearchInput) {
        tableSearchInput.addEventListener('keyup', function() {
            const query = this.value.toLowerCase().trim();
            const targetTable = document.querySelector(this.dataset.targetTable || '.table-modern tbody');
            if (!targetTable) return;

            const rows = targetTable.querySelectorAll('tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }

    // 5. Dynamic Book Availability Stock Checker in Issue Book Form
    const bookSelect = document.getElementById('issue_book_select');
    const stockBadge = document.getElementById('issue_book_stock_badge');
    if (bookSelect && stockBadge) {
        bookSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const available = parseInt(selectedOption.dataset.available || 0);
            if (this.value === '') {
                stockBadge.innerHTML = '';
            } else if (available > 0) {
                stockBadge.innerHTML = `<span class="badge bg-success">${available} copies available in stock</span>`;
            } else {
                stockBadge.innerHTML = `<span class="badge bg-danger">Out of stock! Cannot issue.</span>`;
            }
        });
    }
});

/**
 * Utility function to export an HTML table to CSV
 * @param {string} tableSelector - CSS selector of the table
 * @param {string} filename - Name of downloaded CSV file
 */
function exportTableToCSV(tableSelector, filename = 'library-report.csv') {
    const table = document.querySelector(tableSelector);
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');

    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('th, td');

        for (let j = 0; j < cols.length; j++) {
            // Ignore action buttons column in export
            if (cols[j].classList.contains('no-export')) continue;
            let text = cols[j].innerText.replace(/"/g, '""').trim();
            row.push(`"${text}"`);
        }
        if (row.length > 0) csv.push(row.join(','));
    }

    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const downloadLink = document.createElement('a');
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

/**
 * Initialize Dashboard Chart.js Widgets
 */
function initDashboardCharts(monthlyTrendsData, categoryDistributionData) {
    const palette = ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#f43f5e', '#8b5cf6', '#06b6d4'];

    // 1. Monthly Borrowing Trend Line Chart
    const trendCtx = document.getElementById('borrowingTrendsChart');
    if (trendCtx && monthlyTrendsData) {
        const ctx = trendCtx.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 260);
        gradient.addColorStop(0, 'rgba(79, 70, 229, 0.25)');
        gradient.addColorStop(1, 'rgba(79, 70, 229, 0.0)');

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: monthlyTrendsData.labels || ['3 Months Ago', '2 Months Ago', 'Last Month', 'This Month'],
                datasets: [{
                    label: 'Books Borrowed',
                    data: monthlyTrendsData.data || [8, 14, 18, 22],
                    borderColor: '#4f46e5',
                    borderWidth: 2.5,
                    backgroundColor: gradient,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#4f46e5',
                    pointBorderWidth: 2.5,
                    pointRadius: 4.5,
                    pointHoverRadius: 6.5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 10,
                        titleFont: { family: 'Plus Jakarta Sans', size: 12 },
                        bodyFont: { family: 'Plus Jakarta Sans', size: 13, weight: 'bold' },
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: (context) => ` ${context.raw} Books Borrowed`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 5, color: '#94a3b8' },
                        grid: { color: '#f1f5f9', drawBorder: false }
                    },
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { display: false, drawBorder: false }
                    }
                }
            }
        });
    }

    // 2. Category Distribution Doughnut Chart
    const catCtx = document.getElementById('categoryDistributionChart');
    if (catCtx && categoryDistributionData) {
        new Chart(catCtx, {
            type: 'doughnut',
            data: {
                labels: categoryDistributionData.labels || ['IT & CS', 'Fiction', 'Science', 'Business', 'History'],
                datasets: [{
                    data: categoryDistributionData.data || [5, 4, 3, 2, 2],
                    backgroundColor: palette.slice(0, categoryDistributionData.labels.length),
                    borderWidth: 0,
                    borderRadius: 6,
                    spacing: 4,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false // We use custom HTML list for cleaner, responsive look
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 10,
                        titleFont: { family: 'Plus Jakarta Sans', size: 12 },
                        bodyFont: { family: 'Plus Jakarta Sans', size: 13, weight: 'bold' },
                        cornerRadius: 8,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((context.raw / total) * 100) : 0;
                                return ` ${context.label}: ${context.raw} books (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '74%'
            }
        });
    }
}
