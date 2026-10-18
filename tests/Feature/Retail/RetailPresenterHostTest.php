<?php

use App\Services\Retail\RetailDemoDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('restricts hosted presenter transport to the explicitly enabled exact staging host', function () {
    $path = storage_path('framework/testing/presenter-'.Str::uuid().'.json');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, json_encode(['email' => ['enabled' => true, 'api_key' => 'synthetic-key']]));
    $service = new class($path) extends RetailDemoDelivery
    {
        public function __construct(private string $path) {}

        protected function settingsPath(): string
        {
            return $this->path;
        }

        public function inspect(): array
        {
            return $this->settings();
        }
    };
    $original = app()->environment();
    try {
        app()->instance('env', 'staging');
        app()->instance('request', Request::create('https://demo.example.test/retail/showroom/api/demo/delivery'));
        config(['retail_showroom.presenter_host' => 'demo.example.test', 'retail_showroom.presenter_transport_enabled' => false]);
        expect($service->inspect())->toBe([]);
        config(['retail_showroom.presenter_transport_enabled' => true]);
        expect(data_get($service->inspect(), 'email.enabled'))->toBeTrue();
        config(['retail_showroom.presenter_host' => 'other.example.test']);
        expect($service->inspect())->toBe([]);
        config(['retail_showroom.presenter_host' => '']);
        expect($service->inspect())->toBe([]);
        config(['retail_showroom.presenter_host' => 'demo.example.test']);
        app()->instance('env', 'production');
        expect($service->inspect())->toBe([]);
    } finally {
        app()->instance('env', $original);
        File::delete($path);
    }
});
