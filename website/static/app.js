var formatters = {
    "raw": function(val, axis) {
        return val.toFixed(axis.tickDecimals);
    },

    "time": function(val, axis) {
        return val.toFixed(axis.tickDecimals) + " s";
    },

    "bytes": function(val, axis) {
        var i = 0;
        val = Math.abs(val);
        while (val >= 1024) {
            val /= 1024;
            ++i;
        }

        return val.toFixed(axis.tickDecimals) + " " + ["B", "kB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"][i];
    },

    "percent": function(val, axis) {
        return val.toFixed(axis.tickDecimals) + "%";
    }
}

function chart(container, series, granularity, formatter, precision) {
    var now = new Date().getTime();

    var ranges = {
        "daily": {
            "min": now - 86400 * 1000,
            "max": now
        },
        "weekly": {
            "min": now - (7 * 86400) * 1000,
            "max": now
        },
        "monthly": {
            "min": now - (30 * 86400) * 1000,
            "max": now
        },
        "yearly": {
            "min": now - (365 * 86400) * 1000,
            "max": now
        }
    };

    var colors = ["#00cc00", "#0066b3", "#ff8000", "#ffcc00", "#330099", "#990099", "#ccff00", "#ff0000", "#808080"];

    $.plot(container, series, {
        xaxis: {
            mode: "time",
            min: ranges[granularity].min,
            max: ranges[granularity].max
        },
        yaxis: {
            tickFormatter: formatter,
            tickDecimals: precision
        },
        grid: {
            borderWidth: 0
        },
        series: {
            lines: {
                lineWidth: 1
            },
            shadowSize: 0
        },
        legend: {
            show: true,
        },
        colors: colors
    });
}

function charts_multiple(charts, url_base) {
    for (var c = 0; c < charts.length; ++c) {
        var chart_id = charts[c].id;
        var gran = charts[c].granularity;
        var check_id = charts[c].check_id;

        $.getJSON(url_base.replace("-chart_id-", chart_id).replace("-check-id-", check_id) + "?granularity=" + gran, (function(chart_data){
            var chart_id = chart_data.id;
            var chart_container = chart_data.container;
            var gran = chart_data.granularity;

            return function(data){
                var series = [];
                var precision = 0;
                var formatter = "raw";

                for (var reading_id in data.series) {
                    var ser = data.series[reading_id];
                    var reading = data.readings[reading_id];

                    if (reading.precision > precision) {
                        precision = reading.precision;
                    }

                    formatter = reading.data_type;

                    for (var s = 0; s < ser.length; ++s) {
                        ser[s][0] *= 1000;
                    }

                    var serie = {
                        label: reading.label,
                        data: ser
                    };

                    if (reading.color) {
                        serie["color"] = reading.color;
                    }

                    if (reading.line_type == 'area') {
                        serie["lines"] = {
                            "fill": 0.5
                        };
                    }

                    series.push(serie);
                }

                chart(chart_container, series, gran, formatters[formatter], precision);
            }
        })(charts[c]));
    }
}
