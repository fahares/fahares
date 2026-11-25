<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the site's DNS records are proxied by Cloudflare (a host outage or planned maintenance, backlog item 136,
 * step 14.406), requests reach Traefik from Cloudflare's edge, so $request->ip() is Cloudflare's address. The
 * visitor's address is then in CF-Connecting-IP. It is taken only when the request did come from a Cloudflare range:
 * a request sent straight to the host with a forged header keeps its own address.
 * Ranges: https://www.cloudflare.com/ips/ (checked 25 Nov 2026).
 */
class CloudflareClientIp
{
    public const RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $visitor = $request->headers->get('CF-Connecting-IP');
        if ($visitor && filter_var($visitor, FILTER_VALIDATE_IP) && IpUtils::checkIp((string) $request->ip(), self::RANGES)) {
            // The trusted proxy (Traefik) is REMOTE_ADDR; X-Forwarded-For is what ip() reads past it
            $request->headers->set('X-Forwarded-For', $visitor);
        }

        return $next($request);
    }
}
