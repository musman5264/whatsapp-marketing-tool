<?php

namespace App\Modules\Integrations\Services\External;

/** DNS lookup behind a class so tests can answer without a network. */
class HostResolver
{
    /** @return list<string> every A and AAAA address of the host (an IP literal answers itself) */
    public function ips(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ipv6'];
        }

        return $ips;
    }
}
