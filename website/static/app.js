function divide(value, division, units) {
    if (division == undefined) {
        division = 1000;
    }

    if (units == undefined) {
        units = ["", "k", "M", "G", "B", "T", "P", "E", "Z", "Y"];
    }

    var mult = 1;
    if (value < 0) {
        mult = -1;
        value *= -1;
    }

    var i = 0;
    while (value >= division && i < units.length-1) {
        value /= division;
        ++i;
    }

    value *= mult;

    return [value, units[i]];
}

var formatters = {
    "raw": function(val, axis) {
        return (val != null)?val.toFixed(axis.tickDecimals):val;
    },

    "si": function(val, axis) {
        var val = divide(val);
        return val[0].toFixed(axis.tickDecimals) + " " + val[1];
    },

    "time": function(val, axis) {
        return val.toFixed(axis.tickDecimals) + " s";
    },

    "bytes": function(val, axis) {
        var val = divide(val, 1024, ["B", "kB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"]);
        return val[0].toFixed(axis.tickDecimals) + " " + val[1];
    },

    "Bps": function(val, axis) {
        var val = divide(val, 1024, ["B/s", "kB/s", "MB/s", "GB/s", "TB/s", "PB/s", "EB/s", "ZB/s", "YB/s"]);
        return val[0].toFixed(axis.tickDecimals) + " " + val[1];
    },

    "bps": function(val, axis) {
        var val = divide(val * 8, 1024, ["b/s", "kb/s", "Mb/s", "Gb/s", "Tb/s", "Pb/s", "Eb/s", "Zb/s", "Yb/s"]);
        return val[0].toFixed(axis.tickDecimals) + " " + val[1];
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

    container = $(container);
    var legend_container = null;
    if (container.data("legend")) {
        legend_container = container.data("legend");
    }

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
            position: "nw",
            container: legend_container
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
            var legend_container = chart_data.legend_container;

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

                // Fill in legend.
                if (legend_container) {
                    for (var reading_id in data.statistics) {
                        if (!data.statistics.hasOwnProperty(reading_id)) {
                            continue;
                        }

                        var stats = data.statistics[reading_id];
                        var reading = data.readings[reading_id];

                        var formatter = formatters[reading.data_type];

                        $(legend_container).append("<tr>"
                            + "<td>" + data.readings[reading_id].label + "</td>"
                            + "<td class=\"text-right text-nowrap\">" + formatter(stats["cur"], { tickDecimals: reading.precision }) + "</td>"
                            + "<td class=\"text-right text-nowrap\">" + formatter(stats["min"], { tickDecimals: reading.precision }) + "</td>"
                            + "<td class=\"text-right text-nowrap\">" + formatter(stats["max"], { tickDecimals: reading.precision }) + "</td>"
                            + "<td class=\"text-right text-nowrap\">" + formatter(stats["avg"], { tickDecimals: reading.precision }) + "</td>"
                            + "</tr>");
                    }
                }
            }
        })(charts[c]));
    }
}

function show_help(topic) {
    if ($("#help").hasClass("hidden")) {
        $("#main")
            .addClass("col-lg-9");

        $("#help")
            .addClass("col-lg-3")
            .removeClass("hidden");

        $("#helpTopicTitle")
            .text("Help");

        $("#helpTopicText")
            .html("<div style=\"text-align: center\"><span class=\"fa fa-4x fa-spinner\"></span><br /><br /> Loading help topic.</div>");
    }

    $.getJSON(URL_GET_HELP_TOPIC.replace("-topic-", topic), function(data){
        $("#helpTopicTitle")
            .text(data.topic.name);
        $("#helpTopicText")
            .html(data.topic.text);

        // TODO: Scroll only on mobile.
        //window.location.hash = "#help";
    });
}

$(function(){
    $("#helpPanelClose").click(function(){
        $("#main")
            .removeClass("col-lg-9");
        $("#help")
            .removeClass("col-lg-3")
            .addClass("hidden");
    })
});
