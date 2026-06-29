<?php

/**
 * KyteShipyardUpdate
 *
 * Tracks a request to update the install's Kyte Shipyard dashboard to the latest
 * published build, and its outcome. The dashboard enqueues a row (status=pending)
 * via KyteShipyardUpdateController; the ShipyardUpdateWorker cron job claims it
 * (pending -> running), does the heavy download/extract/upload/invalidate work
 * out-of-band (so a Cloudflare/ALB request timeout can't kill it), and writes the
 * terminal status (complete/failed). See KYTE-#201.
 */

$KyteShipyardUpdate = [
	'name' => 'KyteShipyardUpdate',
	'struct' => [
		// Version the dashboard reported running when the update was requested.
		'current_version' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
		],

		// Version we intend to deploy (latest from the CDN changelog at request time).
		'requested_version' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
		],

		// Version last successfully published by the worker.
		'deployed_version' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
		],

		// pending | running | complete | failed
		'status' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'default'	=> 'pending',
		],

		// Human-readable result or error detail surfaced to the dashboard.
		'message' => [
			'type'		=> 't',
			'required'	=> false,
		],

		'files_uploaded' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
		],

		'files_failed' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
		],

		'cloudfront_invalidated' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
		],

		// Unix timestamps bracketing the worker run.
		'started_at' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
		],

		'finished_at' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
		],

		// Framework attributes
		'kyte_account' => [
			'type'		=> 'i',
			'required'	=> true,
			'unsigned'	=> true,
			'fk'		=> [
				'model'	=> 'KyteAccount',
				'field'	=> 'id',
			],
		],

		// audit attributes
		'created_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_created'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'modified_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_modified'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'deleted_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_deleted'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'deleted'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],
	],
];
