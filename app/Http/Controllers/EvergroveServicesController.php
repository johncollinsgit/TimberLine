<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EvergroveServicesController extends Controller
{
    public function home(): View
    {
        return view('evergrove.home', [
            'content' => (array) config('evergrove', []),
            'tools' => (array) config('evergrove.tools', []),
        ]);
    }

    public function projectEstimate(): View
    {
        return $this->tool('project_estimate');
    }

    public function aiRoi(): View
    {
        return $this->tool('ai_roi');
    }

    public function automationSavings(): View
    {
        return $this->tool('automation_savings');
    }

    public function lander(Request $request): RedirectResponse
    {
        return redirect()->to('/', 301);
    }

    protected function tool(string $key): View
    {
        $tools = (array) config('evergrove.tools', []);
        abort_unless(isset($tools[$key]) && is_array($tools[$key]), 404);

        return view('evergrove.tool', [
            'content' => (array) config('evergrove', []),
            'toolKey' => $key,
            'tool' => (array) $tools[$key],
        ]);
    }
}
