/**
 * The tint of a region of the dynasties, the same on the map of the statistics and on the chart of the dynasties
 * (steps 14.275 and 14.278): by the place of the region in the list the page sends.
 */
const REGION_COLORS = ['#B5651D', '#3F7F6B', '#5B6BA8', '#A8843F', '#8C4A6E', '#4F8FA8', '#7A8A3A', '#A2553F', '#6E5BA8', '#3F6F8F'];

export function regionColor(regions, region) {
    const i = regions.indexOf(region);

    return REGION_COLORS[(i < 0 ? regions.length : i) % REGION_COLORS.length];
}
