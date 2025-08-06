nv.addGraph(function() {
    var chart = nv.models.multiBarHorizontalChart()
        .x(function(d) { return d.label })
        .y(function(d) { return d.value })
        .showValues(true)
        .tooltips(false)
        .showControls(false);

    d3.select('#chart-searches svg')
        .datum(dataSearches)
        .transition().duration(500)
        .call(chart);

    nv.utils.windowResize(chart.update);

    return chart;
});

nv.addGraph(function() {
    var chart = nv.models.multiBarHorizontalChart()
        .x(function(d) { return d.label })
        .y(function(d) { return d.value })
        .showValues(true)
        .tooltips(false)
        .showControls(false);

    d3.select('#chart-no-results svg')
        .datum(dataEmpty)
        .transition().duration(500)
        .call(chart);

    nv.utils.windowResize(chart.update);

    return chart;
});