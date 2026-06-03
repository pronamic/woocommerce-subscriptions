<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

/**
 * Lightweight snapshot of a renewal order's key properties — id, status,
 * and creation date — as resolved by the Detector's prefetch pipeline.
 */
class RenewalSnapshot {

	/** @var int Renewal order ID. */
	public int $id;

	/** @var string Order status without the `wc-` prefix. */
	public string $status;

	/** @var int Creation timestamp (Unix, GMT). */
	public int $date_gmt;

	/**
	 * @param int    $id       Renewal order ID.
	 * @param string $status   Order status (without `wc-` prefix).
	 * @param int    $date_gmt Creation timestamp (Unix, GMT).
	 */
	public function __construct( int $id, string $status, int $date_gmt ) {
		$this->id       = $id;
		$this->status   = $status;
		$this->date_gmt = $date_gmt;
	}
}
