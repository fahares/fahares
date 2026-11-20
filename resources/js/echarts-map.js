/**
 * The parts of ECharts the map of the statistics needs (backlog item 137, step 14.275), as a chunk of its own: it is
 * loaded only by the map, when the map comes into view (insights-map.js), never with the rest of the site's script.
 */
import * as echarts from 'echarts/core';
import { ScatterChart, CustomChart } from 'echarts/charts';
import { GeoComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { LabelLayout } from 'echarts/features';

echarts.use([ScatterChart, CustomChart, GeoComponent, TooltipComponent, CanvasRenderer, LabelLayout]);

export default echarts;
