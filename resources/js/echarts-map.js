/**
 * The parts of ECharts the map of the statistics and the chart of the dynasties need (backlog items 137 and 154, steps
 * 14.275 and 14.278), as a chunk of its own: it is loaded only by those two, when one comes into view (insights-map.js,
 * insights-dynasties.js), never with the rest of the site's script.
 */
import * as echarts from 'echarts/core';
import { ScatterChart, CustomChart } from 'echarts/charts';
import { GeoComponent, TooltipComponent, GridComponent, DataZoomComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { LabelLayout } from 'echarts/features';

echarts.use([ScatterChart, CustomChart, GeoComponent, TooltipComponent, GridComponent, DataZoomComponent, CanvasRenderer, LabelLayout]);

export default echarts;
