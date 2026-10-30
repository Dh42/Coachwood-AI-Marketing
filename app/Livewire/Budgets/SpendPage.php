<?php

namespace App\Livewire\Budgets;

use App\Budgets\Budgets;
use App\Budgets\SpendReport;
use App\Models\Brand;
use App\Settings\Settings;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What the AI providers have cost: by person, brand, provider, kind of
 * work and month, and each request's estimate against what it cost.
 */
#[Title('Spend')]
class SpendPage extends Component
{
    #[Url]
    public string $period = 'month';

    #[Url]
    public string $brand = '';

    public function render(SpendReport $report, Budgets $budgets, Settings $settings)
    {
        $this->authorize('budgets.manage');

        if (! isset(SpendReport::PERIODS[$this->period])) {
            $this->period = 'month';
        }
        $brandId = $this->brand !== '' ? (int) $this->brand : null;

        return view('livewire.budgets.spend-page', [
            'brands' => Brand::orderBy('name')->pluck('name', 'id'),
            'company' => $budgets->company(),
            'alertPercent' => (int) $settings->get('budgets.alert_percent'),
            'total' => $report->total($this->period, $brandId),
            'people' => $report->by($this->period, 'user_id', $brandId),
            'byBrand' => $report->by($this->period, 'brand_id', $brandId),
            'providers' => $report->by($this->period, 'provider', $brandId),
            'kinds' => $report->by($this->period, 'kind', $brandId),
            'months' => $report->byMonth($brandId),
            'requests' => $report->requests($this->period, $brandId),
            'budgets' => $budgets,
        ]);
    }
}
