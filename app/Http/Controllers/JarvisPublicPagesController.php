<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class JarvisPublicPagesController extends Controller
{
    public function home(Request $request): View
    {
        return $this->page($request, 'home');
    }

    public function privacy(Request $request): View
    {
        return $this->page($request, 'privacy');
    }

    public function terms(Request $request): View
    {
        return $this->page($request, 'terms');
    }

    private function page(Request $request, string $page): View
    {
        $hosts = collect((array) config('evergrove.hosts', []))
            ->map(fn (mixed $host): string => strtolower(trim(preg_replace('#^https?://#', '', (string) $host), './ ')))
            ->filter()
            ->all();

        abort_unless(in_array(strtolower($request->getHost()), $hosts, true), 404);

        return view('evergrove.jarvis.'.$page, [
            'content' => (array) config('evergrove', []),
            'page' => $page,
        ]);
    }
}
