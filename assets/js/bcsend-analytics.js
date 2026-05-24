/**
 * Beacon Campaign Sender - Analytics Page JavaScript
 *
 * Uses server-rendered stats and inline bcsendAnalyticsData for Chart.js charts
 * (email performance, audience growth, push stats). Stats cards are
 * server-rendered; charts are initialized from the inline JSON data.
 *
 * @package Bcsend_Plugin
 * @since   1.0.0
 */

(function($) {
    'use strict';

    var Analytics = {

        /**
         * Chart.js instances for cleanup.
         * @type {Object}
         */
        charts: {},

        /**
         * Initialize the analytics page.
         */
        init: function() {
            // Stats cards are server-rendered; just hide loading spinners and init charts.
            this.hideChartLoading();

            if (typeof bcsendAnalyticsData !== 'undefined') {
                this.renderEmailChart(bcsendAnalyticsData.dailyStats || []);
                this.renderAudienceChart(bcsendAnalyticsData.audienceGrowth || []);
                this.renderPushChart(bcsendAnalyticsData.pushPerCampaign || []);
            } else {
                this.showEmptyState();
            }
        },

        /**
         * Hide chart loading indicators.
         */
        hideChartLoading: function() {
            $('.bcsend-chart-loading').hide();
        },

        /* ============================================================
           Email Performance Chart
           ============================================================ */

        /**
         * Render the email performance line chart.
         *
         * @param {Array} dailyStats Array of {date, open_rate, click_rate} objects from server.
         */
        renderEmailChart: function(dailyStats) {
            var ctx = document.getElementById('bcsend-email-performance-chart');
            if (!ctx) {
                return;
            }

            if (!dailyStats || !dailyStats.length) {
                this.showChartEmpty($(ctx).closest('.bcsend-chart-container'));
                return;
            }

            var labels = [];
            var openRates = [];
            var clickRates = [];

            $.each(dailyStats, function(i, row) {
                labels.push(row.date || row.send_date || '');
                openRates.push(parseFloat(row.open_rate || 0));
                clickRates.push(parseFloat(row.click_rate || 0));
            });

            this.charts.email = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Open Rate %',
                            data: openRates,
                            borderColor: '#2271b1',
                            backgroundColor: 'rgba(34, 113, 177, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        },
                        {
                            label: 'Click Rate %',
                            data: clickRates,
                            borderColor: '#00a32a',
                            backgroundColor: 'rgba(0, 163, 42, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        }
                    ]
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
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: { size: 12 }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                },
                                font: { size: 11 }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 11 },
                                maxRotation: 45
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        },

        /* ============================================================
           Audience Growth Chart
           ============================================================ */

        /**
         * Render the audience growth line chart.
         *
         * @param {Array} audienceGrowth Array of {date, count} objects from server.
         */
        renderAudienceChart: function(audienceGrowth) {
            var ctx = document.getElementById('bcsend-audience-growth-chart');
            if (!ctx) {
                return;
            }

            if (!audienceGrowth || !audienceGrowth.length) {
                this.showChartEmpty($(ctx).closest('.bcsend-chart-container'));
                return;
            }

            var labels = [];
            var counts = [];

            $.each(audienceGrowth, function(i, row) {
                labels.push(row.date || row.month || '');
                counts.push(parseInt(row.count || 0, 10));
            });

            this.charts.audience = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Subscribers',
                            data: counts,
                            borderColor: '#6b3fa0',
                            backgroundColor: 'rgba(107, 63, 160, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: { size: 12 }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                font: { size: 11 },
                                precision: 0
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 11 },
                                maxRotation: 45
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        },

        /* ============================================================
           Push Stats Chart
           ============================================================ */

        /**
         * Render the push notification stats bar chart.
         *
         * @param {Array} pushPerCampaign Array of {name, count} objects from server.
         */
        renderPushChart: function(pushPerCampaign) {
            var ctx = document.getElementById('bcsend-push-stats-chart');
            if (!ctx) {
                return;
            }

            if (!pushPerCampaign || !pushPerCampaign.length) {
                this.showChartEmpty($(ctx).closest('.bcsend-chart-container'));
                return;
            }

            var labels = [];
            var deliveries = [];

            $.each(pushPerCampaign, function(i, row) {
                labels.push(row.name || row.campaign || '');
                deliveries.push(parseInt(row.count || row.delivered || 0, 10));
            });

            this.charts.push = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Deliveries',
                            data: deliveries,
                            backgroundColor: 'rgba(34, 113, 177, 0.7)',
                            borderColor: '#2271b1',
                            borderWidth: 1,
                            borderRadius: 3,
                            maxBarThickness: 50
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: { size: 12 }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                font: { size: 11 },
                                precision: 0
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 11 },
                                maxRotation: 45
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        },

        /* ============================================================
           Empty States
           ============================================================ */

        /**
         * Show an empty state message inside a chart container.
         *
         * @param {jQuery} $container The chart container element.
         */
        showChartEmpty: function($container) {
            $container.find('canvas').hide();
            $container.find('.bcsend-chart-loading').hide();
            $container.append(
                '<div class="bcsend-chart-empty">' +
                '<span class="dashicons dashicons-chart-line"></span>' +
                '<p>No data available yet.</p>' +
                '</div>'
            );
        },

        /**
         * Show a global empty state when no analytics data exists.
         */
        showEmptyState: function() {
            this.showChartEmpty($('#bcsend-email-performance-chart').closest('.bcsend-chart-container'));
            this.showChartEmpty($('#bcsend-audience-growth-chart').closest('.bcsend-chart-container'));
            this.showChartEmpty($('#bcsend-push-stats-chart').closest('.bcsend-chart-container'));
        }
    };

    $(document).ready(function() {
        Analytics.init();
    });

})(jQuery);
