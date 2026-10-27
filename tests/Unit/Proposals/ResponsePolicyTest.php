<?php

namespace Tests\Unit\Proposals;

use App\Proposals\ResponsePolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** No application providers, environment files, databases or network adapters are booted. */
final class ResponsePolicyTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository(['service_proposals' => ['enabled' => true]]));
        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function directives(Response $response): array
    {
        $directives = [];
        foreach (explode(';', $response->headers->get('Content-Security-Policy')) as $entry) {
            $parts = preg_split('/\s+/', trim($entry));
            $directives[array_shift($parts)] = $parts;
        }

        return $directives;
    }

    public function test_generic_proposals_allow_only_the_fixed_same_origin_artwork_and_keep_existing_security_boundaries(): void
    {
        $original = new Response('Unchanged private proposal body');
        $response = (new ResponsePolicy)->handle(Request::create('https://proposal.example.invalid:8443/service-proposals/synthetic?image=https://blocked.example.invalid/other'), fn () => $original);
        $this->assertSame($original, $response);
        $this->assertSame('Unchanged private proposal body', $response->getContent());
        $directives = $this->directives($response);
        $this->assertSame(['https://proposal.example.invalid:8443/images/proposals/evergrove-mountain-corner-v2.svg', 'https://proposal.example.invalid:8443/brand/evergrove-logo.png'], $directives['img-src']);
        $this->assertSame(["'none'"], $directives['default-src']);
        $this->assertSame(["'unsafe-inline'"], $directives['style-src']);
        $this->assertSame(["'self'"], $directives['form-action']);
        $this->assertSame(["'none'"], $directives['base-uri']);
        $this->assertSame(["'none'"], $directives['frame-ancestors']);
        // Omitted network/script/frame directives continue to inherit default-src none.
        foreach (['script-src', 'connect-src', 'frame-src', 'object-src', 'font-src', 'media-src'] as $name) {
            $this->assertArrayNotHasKey($name, $directives);
        }
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function test_trusted_campus_review_keeps_its_existing_nonce_policy_byte_exact(): void
    {
        $request = Request::create('/service-proposals/synthetic/campus/home');
        $request->attributes->set('service_proposal_campus_review', true);
        $policy = "default-src 'none'; img-src 'self' data:; script-src 'nonce-synthetic-reviewed-nonce'; connect-src 'self'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";
        $original = new Response('Reviewed campus', 200, ['Content-Security-Policy' => $policy]);
        $response = (new ResponsePolicy)->handle($request, fn () => $original);
        $this->assertSame($original, $response);
        $this->assertSame($policy, $response->headers->get('Content-Security-Policy'));
    }

    public function test_only_the_exact_rendered_local_menu_script_receives_a_hash_exception(): void
    {
        $script = file_get_contents(resource_path('js/service-proposals/mobile-navigation.js'));
        $html = '<script data-proposal-navigation>'.$script.'</script>';
        $request = Request::create('https://proposal.example.invalid/service-proposals/synthetic');
        $response = (new ResponsePolicy)->handle($request, fn () => new Response($html));
        $directives = $this->directives($response);
        $this->assertSame(["'sha256-".base64_encode(hash('sha256', $script, true))."'"], $directives['script-src']);
        $this->assertSame(["'none'"], $directives['script-src-attr']);
        $this->assertSame(["'none'"], $directives['default-src']);
        $this->assertSame(["'self'"], $directives['form-action']);
        $this->assertSame(["'none'"], $directives['base-uri']);
        $this->assertSame(["'none'"], $directives['frame-ancestors']);
        $this->assertSame($html, $response->getContent());
        foreach (['connect-src', 'frame-src', 'object-src', 'font-src', 'media-src'] as $name) {
            $this->assertArrayNotHasKey($name, $directives);
        }
        foreach (['<script data-proposal-navigation>'.$script.'/* altered */</script>', '<script>'.$script.'</script>', '<p>No section menu</p>'] as $other) {
            $unapproved = (new ResponsePolicy)->handle($request, fn () => new Response($other));
            $this->assertArrayNotHasKey('script-src', $this->directives($unapproved));
            $this->assertSame($other, $unapproved->getContent());
        }
    }

    public function test_an_untrusted_existing_policy_cannot_broaden_generic_access(): void
    {
        $original = new Response('Proposal', 200, ['Content-Security-Policy' => 'default-src *; img-src *; script-src *; connect-src *']);
        $response = (new ResponsePolicy)->handle(Request::create('/service-proposals/synthetic'), fn () => $original);
        $directives = $this->directives($response);
        $this->assertSame(["'none'"], $directives['default-src']);
        $this->assertSame(['http://localhost/images/proposals/evergrove-mountain-corner-v2.svg', 'http://localhost/brand/evergrove-logo.png'], $directives['img-src']);
        $this->assertArrayNotHasKey('script-src', $directives);
        $this->assertArrayNotHasKey('connect-src', $directives);
    }

    public function test_campus_marker_without_a_policy_gets_the_restricted_fallback(): void
    {
        $request = Request::create('/service-proposals/synthetic/campus/home');
        $request->attributes->set('service_proposal_campus_review', true);
        $response = (new ResponsePolicy)->handle($request, fn () => new Response('Proposal'));
        $directives = $this->directives($response);
        $this->assertSame(['http://localhost/images/proposals/evergrove-mountain-corner-v2.svg', 'http://localhost/brand/evergrove-logo.png'], $directives['img-src']);
        $this->assertSame(["'none'"], $directives['default-src']);
        $this->assertArrayNotHasKey('script-src', $directives);
    }

    public function test_disabled_proposals_fail_before_invoking_the_response(): void
    {
        config(['service_proposals.enabled' => false]);
        $called = false;
        try {
            (new ResponsePolicy)->handle(Request::create('/service-proposals/synthetic'), function () use (&$called) {
                $called = true;

                return new Response('Must not render');
            });
            $this->fail('Disabled proposal response must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertFalse($called);
    }
}
