<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PublicLegalController extends Controller
{
    public function privacy(Request $request): View
    {
        return $this->page($request, 'privacy');
    }

    public function terms(Request $request): View
    {
        return $this->page($request, 'terms');
    }

    protected function page(Request $request, string $document): View
    {
        $host = strtolower($request->getHost());
        $evergroveHosts = collect((array) config('evergrove.hosts', []))
            ->map(static fn (mixed $value): string => strtolower(trim((string) $value)))
            ->filter()
            ->all();
        $isEvergrove = in_array($host, $evergroveHosts, true);

        return view('legal.page', [
            'document' => $document,
            'brandName' => $isEvergrove ? 'Evergrove Software' : 'Everbranch',
            'brandAssets' => (array) config($isEvergrove ? 'evergrove.brand_assets' : 'everbranch.brand_assets', []),
            'contactEmail' => (string) config('evergrove.contact_email', 'hello@evergrovesoftware.com'),
            'homeUrl' => $isEvergrove ? 'https://evergrovesoftware.com' : 'https://theeverbranch.com',
        ]);
    }
}
