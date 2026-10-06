<?php

namespace App\Filament\Widgets\Concerns;

use Filament\Support\RawJs;
use Illuminate\Support\Js;

/**
 * One look for every report chart: Kalameh, right-to-left legend and tooltips, recessive grid,
 * thin marks. Colours are the validated two-series pair (blue, orange) and a red–gray–blue
 * diverging scale for satisfaction.
 */
class ChartStyle
{
    /**
     * $options with a click through: the slice, bar or point under the pointer calls the widget's
     * openDetails(dataset, index), which opens the calls behind it; the pointer is a hand over them.
     * The widget asks the server, so the dashboard's current filters always apply.
     */
    public static function clickable(array $options): RawJs
    {
        // no double quotes in here: Filament writes chart options into a double-quoted HTML attribute
        return RawJs::make('(() => {
            const options = '.Js::from($options)->toHtml().';
            // a bar answers anywhere in its column, a point anywhere near it, a slice only on itself
            const hit = (event, chart) => {
                const type = chart.config.type;
                const mode = type === \'bar\' ? \'index\' : \'nearest\';
                // a lying-down bar chart is read row by row, top to bottom; on lines the points of
                // both series share a column, so the one nearest the pointer in both directions
                const axis = chart.options.indexAxis === \'y\' ? \'y\' : (type === \'bar\' ? \'x\' : \'xy\');
                return chart.getElementsAtEventForMode(event.native ?? event, mode, { intersect: type === \'doughnut\' || type === \'pie\', axis: axis }, true)[0];
            };
            options.onHover = (event, elements, chart) => { chart.canvas.style.cursor = hit(event, chart) ? \'pointer\' : \'default\'; };
            options.onClick = (event, elements, chart) => {
                const element = hit(event, chart);
                if (! element) return;
                let host = chart.canvas;
                while (host && ! host.hasAttribute(\'wire:id\')) host = host.parentElement;
                window.Livewire.find(host.getAttribute(\'wire:id\')).call(\'openDetails\', element.datasetIndex, element.index);
            };
            return options;
        })()');
    }

    public const BLUE = '#2a78d6';

    public const ORANGE = '#eb6834';

    /** 1 (very unhappy) … 5 (very happy): red arm, gray midpoint, blue arm. */
    public const SATISFACTION = ['#c93434', '#eb8f8e', '#b0afaa', '#86b6ef', '#2a78d6'];

    public const GRID = 'rgba(128, 128, 128, 0.15)';

    /**
     * Categorical slots in fixed order (validated: adjacent pairs pass colour-blind and normal-vision
     * separation). A category always keeps its slot, whatever its rank. Slots 3–5 sit under 3:1
     * against white, so every donut names each slice with its percentage in the legend.
     */
    public const CATEGORICAL = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];

    /** Donut: no axes, a white gap between slices, legend underneath with names and percentages. */
    public static function doughnutOptions(): array
    {
        $font = ['family' => 'Kalameh, Tahoma, sans-serif', 'size' => 12];

        return [
            'maintainAspectRatio' => false,
            'cutout' => '62%',
            'layout' => ['padding' => 8],
            'plugins' => [
                'legend' => [
                    'rtl' => true,
                    'position' => 'bottom',
                    'labels' => ['font' => $font, 'usePointStyle' => true, 'pointStyle' => 'circle', 'boxWidth' => 8, 'padding' => 14],
                ],
                'tooltip' => [
                    'rtl' => true,
                    'textDirection' => 'rtl',
                    'titleFont' => $font,
                    'bodyFont' => $font,
                    'padding' => 10,
                ],
            ],
        ];
    }

    /** Dataset look for a donut: slices separated by the surface colour. */
    public static function doughnutDataset(array $data, array $colors): array
    {
        return [
            'data' => $data,
            'backgroundColor' => $colors,
            'borderColor' => '#ffffff',
            'borderWidth' => 2,
            'hoverOffset' => 6,
        ];
    }

    public static function options(array $overrides = []): array
    {
        $font = ['family' => 'Kalameh, Tahoma, sans-serif', 'size' => 12];

        return array_replace_recursive([
            'maintainAspectRatio' => false,
            'locale' => 'fa-IR',
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => [
                    'rtl' => true,
                    'position' => 'bottom',
                    'labels' => ['font' => $font, 'usePointStyle' => true, 'boxWidth' => 8, 'padding' => 16],
                ],
                'tooltip' => [
                    'rtl' => true,
                    'textDirection' => 'rtl',
                    'titleFont' => $font,
                    'bodyFont' => $font,
                    'padding' => 10,
                    'boxPadding' => 4,
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'ticks' => ['font' => $font],
                    'border' => ['display' => false],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'grid' => ['color' => self::GRID],
                    'ticks' => ['font' => $font, 'precision' => 0],
                    'border' => ['display' => false],
                ],
            ],
        ], $overrides);
    }
}
