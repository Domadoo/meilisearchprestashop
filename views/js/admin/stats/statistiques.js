nv.addGraph(function() {
    var chartSearches = nv.models.multiBarHorizontalChart()
        .x(function(d) { return d.id })
        .y(function(d) { return d.value })
        .showValues(true)
        .tooltips(true)
        .showControls(false)
        .showLegend(false);

    chartSearches.tooltipContent(function(key, x, y, object) {
        return '<h3>' + object.point.label + '</h3>' +
            '<p>' + y + '</p>';
    });

    d3.select('#chart-searches svg')
        .datum(dataSearches)
        .transition().duration(500)
        .call(chartSearches);

    nv.utils.windowResize(chartSearches.update);

    return chartSearches;
});

nv.addGraph(function() {
    var chartEmpty = nv.models.multiBarHorizontalChart()
        .x(function(d) { return d.id })
        .y(function(d) { return d.value })
        .showValues(true)
        .tooltips(true)
        .showControls(false)
        .showLegend(false);

    chartEmpty.tooltipContent(function(key, x, y, object) {
        return '<h3>' + object.point.label + '</h3>' +
            '<p>' + y + '</p>';
    });

    d3.select('#chart-no-results svg')
        .datum(dataEmpty)
        .transition().duration(500)
        .call(chartEmpty);

    nv.utils.windowResize(chartEmpty.update);

    return chartEmpty;
});

nv.addGraph(function() {
    var chartClick = nv.models.multiBarHorizontalChart()
        .x(function(d) { return d.id })
        .y(function(d) { return d.value })
        .showValues(true)
        .tooltips(true)
        .showControls(false)
        .showXAxis(true)
        .showLegend(false)

    chartClick.tooltipContent(function(key, x, y, object) {
        return '<h3>' + object.point.label + '</h3>' +
            '<p>' + y + '</p>';
    });

    d3.select('#chart-clicks svg')
        .datum(dataClicks)
        .transition().duration(500)
        .call(chartClick);

    nv.utils.windowResize(chartClick.update);

    return chartClick;
});

nv.addGraph(function() {
  var chartCtr = nv.models.pieChart()
      .x(function(d) { return d.label })
      .y(function(d) { return d.value })
      .showLabels(true)     //Display pie labels
      .labelThreshold(.05)  //Configure the minimum slice size for labels to show up
      .labelType("percent") //Configure what type of data to show in the label. Can be "key", "value" or "percent"
      .donut(true)          //Turn on Donut mode. Makes pie chart look tasty!
      .donutRatio(0.35)     //Configure how big you want the donut hole size to be.
      .showLegend(false)
    ;

    chartCtr.pie
        .startAngle(function(d) { return d.startAngle / 2 - Math.PI / 2 })
        .endAngle(function(d) { return d.endAngle / 2 - Math.PI / 2 });

    d3.select("#chart-ctr svg")
        .datum(dataCtr)
        .transition().duration(350)
        .call(chartCtr);

  return chartCtr;
});