<?php

namespace App\Services\Partner;

use Exception;

/**
 * The partner understood the request and refused it — 4xx. Retrying sends
 * the same rejected payload again, so this is parked immediately.
 */
class PartnerRejected extends Exception {}