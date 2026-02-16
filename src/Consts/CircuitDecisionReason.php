<?php

namespace Gohany\Circuitbreaker\Consts;

final class CircuitDecisionReason
{
    public const CLOSED = 'closed';
    public const OK = 'ok';

    public const OPEN = 'open';
    public const OPEN_EXPIRED_PROBE = 'open_expired_probe';

    public const HALF_OPEN_PROBE = 'half_open_probe';
    public const HALF_OPEN_NO_SLOTS = 'half_open_no_slots';

    public const FRAUD_LOCKOUT = 'fraud_lockout';

    public const OVERRIDE_FORCE_ALLOW = 'override:force_allow';
    public const OVERRIDE_FORCE_DENY = 'override:force_deny';
    public const OVERRIDE_FORCED_OPEN = 'override:forced_open';
    public const OVERRIDE_FORCED_CLOSED = 'override:forced_closed';
    public const OVERRIDE_FORCED_HALF_OPEN = 'override:forced_half_open';

    public const UNKNOWN = 'unknown';

    private function __construct() {}
}
