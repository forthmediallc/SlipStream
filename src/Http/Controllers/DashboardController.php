<?php

namespace Slipstream\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Slipstream\Slipstream;

class DashboardController extends Controller
{
    /**
     * Section => route name, in nav order. The index lands on the first one
     * that's enabled so `/slipstream` never renders a disabled view.
     */
    protected const SECTIONS = [
        'deployments' => 'slipstream.deployments',
        'servers' => 'slipstream.servers',
        'pipelines' => 'slipstream.pipelines',
        'hooks' => 'slipstream.hooks',
        'webhook' => 'slipstream.webhook',
        'settings' => 'slipstream.settings',
        'environment' => 'slipstream.environment',
    ];

    public function index(): View|RedirectResponse
    {
        if (Slipstream::viewEnabled('deployments')) {
            return view('slipstream::dashboard', ['page' => 'deployments']);
        }

        foreach (self::SECTIONS as $section => $route) {
            if (Slipstream::viewEnabled($section)) {
                return redirect()->route($route);
            }
        }

        abort(404);
    }

    public function deployments(): View
    {
        abort_unless(Slipstream::viewEnabled('deployments'), 404);

        return view('slipstream::dashboard', ['page' => 'deployments']);
    }

    public function deployment(int $deployment): View
    {
        abort_unless(Slipstream::viewEnabled('deployments'), 404);

        return view('slipstream::dashboard', [
            'page' => 'deployment',
            'deploymentId' => $deployment,
        ]);
    }

    public function webhook(): View
    {
        abort_unless(Slipstream::viewEnabled('webhook'), 404);

        return view('slipstream::dashboard', ['page' => 'webhook']);
    }

    public function settings(): View
    {
        abort_unless(Slipstream::viewEnabled('settings'), 404);

        return view('slipstream::dashboard', ['page' => 'settings']);
    }

    public function servers(): View
    {
        abort_unless(Slipstream::viewEnabled('servers'), 404);

        return view('slipstream::dashboard', ['page' => 'servers']);
    }

    public function pipelines(): View
    {
        abort_unless(Slipstream::viewEnabled('pipelines'), 404);

        return view('slipstream::dashboard', ['page' => 'pipelines']);
    }

    public function hooks(): View
    {
        abort_unless(Slipstream::viewEnabled('hooks'), 404);

        return view('slipstream::dashboard', ['page' => 'hooks']);
    }

    public function environment(): View
    {
        abort_unless(Slipstream::viewEnabled('environment'), 404);

        return view('slipstream::dashboard', ['page' => 'environment']);
    }
}
