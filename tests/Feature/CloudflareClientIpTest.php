<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Backlog item 136, step 14.406: the visitor's address while Cloudflare proxies the site. */
class CloudflareClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/__test-ip', fn (Request $request) => $request->ip());
    }

    private function ipFor(string $forwardedFor, ?string $cfIp): string
    {
        $headers = ['X-Forwarded-For' => $forwardedFor];
        if ($cfIp !== null) {
            $headers['CF-Connecting-IP'] = $cfIp;
        }

        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.5'])->get('/__test-ip', $headers)->getContent();
    }

    public function test_a_request_from_cloudflare_takes_the_visitors_address(): void
    {
        $this->assertSame('5.160.1.2', $this->ipFor('162.158.10.20', '5.160.1.2'));
        $this->assertSame('2a01:5ec0::1', $this->ipFor('2606:4700:10::1', '2a01:5ec0::1'));
    }

    public function test_a_direct_request_keeps_its_own_address_whatever_its_headers_say(): void
    {
        $this->assertSame('5.160.1.2', $this->ipFor('5.160.1.2', '1.1.1.1'));
        $this->assertSame('5.160.1.2', $this->ipFor('5.160.1.2', null));
    }

    public function test_an_invalid_header_is_ignored(): void
    {
        $this->assertSame('162.158.10.20', $this->ipFor('162.158.10.20', 'not-an-ip'));
    }
}
