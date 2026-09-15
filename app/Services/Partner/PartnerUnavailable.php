<?php

namespace App\Services\Partner;

use Exception;

/**
 * The partner could not be reached, or failed in a way that may not recur —
 * timeout, connection refused, 5xx. Worth retrying.
 */
class PartnerUnavailable extends Exception {}