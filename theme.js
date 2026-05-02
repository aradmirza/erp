/**
 * theme.js — Sodai Lagbe ERP Theme Toggle System
 * Manages dark/light mode via localStorage + html[data-theme] attribute.
 * The init snippet in each <head> applies the saved theme instantly
 * to prevent flash of wrong theme (FOWT).
 */

(function () {
    /* ── Apply saved theme immediately (called by inline <head> snippet) ── */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
    }

    /* ── Toggle between dark and light ── */
    function toggleTheme() {
        var current = document.documentElement.getAttribute('data-theme') || 'dark';
        var next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        localStorage.setItem('erpTheme', next);
        updateAllToggleBtns(next);
        updateChartColors(next);
    }

    /* ── Update all theme-toggle buttons on the page ── */
    function updateAllToggleBtns(theme) {
        var isDark = theme === 'dark';
        document.querySelectorAll('.theme-toggle-btn').forEach(function (btn) {
            btn.innerHTML = isDark
                ? '<i class="fas fa-sun"></i>'
                : '<i class="fas fa-moon"></i>';
            btn.title = isDark ? 'Light Mode এ যান' : 'Dark Mode এ যান';
            btn.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        });
    }

    /* ── Update Chart.js defaults when theme changes (dashboard.php) ── */
    function updateChartColors(theme) {
        if (typeof Chart === 'undefined') return;
        var isDark = theme === 'dark';
        Chart.defaults.color = isDark ? '#6e7681' : '#64748b';
        /* Re-render charts if they exist */
        Object.values(Chart.instances || {}).forEach(function (chart) {
            if (chart.options && chart.options.plugins && chart.options.plugins.tooltip) {
                chart.options.plugins.tooltip.backgroundColor = isDark
                    ? 'rgba(22,27,34,.96)'
                    : 'rgba(248,250,252,.98)';
                chart.options.plugins.tooltip.titleColor = isDark ? '#e6edf3' : '#0f172a';
                chart.options.plugins.tooltip.bodyColor  = isDark ? '#8b949e' : '#64748b';
                chart.options.plugins.tooltip.borderColor = isDark
                    ? 'rgba(255,255,255,.08)'
                    : 'rgba(0,0,0,.08)';
            }
            if (chart.options && chart.options.scales) {
                var gridColor = isDark ? 'rgba(255,255,255,.04)' : 'rgba(0,0,0,.05)';
                if (chart.options.scales.y) {
                    chart.options.scales.y.grid.color = gridColor;
                    chart.options.scales.y.ticks.color = isDark ? '#6e7681' : '#94a3b8';
                }
                if (chart.options.scales.x) {
                    chart.options.scales.x.ticks.color = isDark ? '#6e7681' : '#94a3b8';
                }
            }
            chart.update('none');
        });
    }

    /* ── Expose globals ── */
    window.toggleTheme = toggleTheme;
    window._themeApply = applyTheme;
    window._themeUpdateBtns = updateAllToggleBtns;
    window._themeUpdateCharts = updateChartColors;

    /* ── On DOM ready: sync button state ── */
    document.addEventListener('DOMContentLoaded', function () {
        var saved = localStorage.getItem('erpTheme') || 'dark';
        updateAllToggleBtns(saved);
    });
})();
