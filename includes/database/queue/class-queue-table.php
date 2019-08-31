<?php
/**
 * Queue Table.
 *
 * @package     RCP
 * @subpackage  Database\Tables
 * @copyright   Copyright (c) 2018, Easy Digital Downloads, LLC
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.0
 */

namespace RCP\Database\Tables;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

use RCP\Database\Table;

/**
 * Setup the "rcp_queue" database table
 *
 * @since 3.0
 */
final class Queue extends Table {

	/**
	 * @var string Table name
	 */
	protected $name = 'queue';

	/**
	 * @var string Database version
	 */
	protected $version = 201908091;

	/**
	 * @var array Array of upgrade versions and methods
	 */
	protected $upgrades = array(
		'201905111' => 201905111,
		'201908091' => 201908091,
		'201908092' => 201908092
	);

	/**
	 * Queue constructor.
	 *
	 * @access public
	 * @since  3.0
	 * @return void
	 */
	public function __construct() {

		parent::__construct();

	}

	/**
	 * Setup the database schema
	 *
	 * @access protected
	 * @since  3.0
	 * @return void
	 */
	protected function set_schema() {
		$this->schema = "id int unsigned NOT NULL AUTO_INCREMENT,
			queue varchar(20) NOT NULL DEFAULT '',
			name varchar(50) NOT NULL DEFAULT '',
			description varchar(256) NOT NULL DEFAULT '',
			callback varchar(512) NOT NULL DEFAULT '',
			total_count bigint(20) unsigned NOT NULL DEFAULT 0,
			current_count bigint(20) unsigned NOT NULL DEFAULT 0,
			step bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'incomplete',
			data longtext NOT NULL DEFAULT '',
			date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			date_completed datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			uuid varchar(100) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			UNIQUE KEY name_queue (name,queue),
			KEY queue (queue),
			KEY status (status)";
	}

	/**
	 * Upgrade to version 201905111
	 * - Add `data` column for serialized data.
	 */
	protected function __201905111() {

		// Look for column
		$result = $this->column_exists( 'data' );

		// Maybe add column
		if ( false === $result ) {
			$result = $this->get_db()->query( "
				ALTER TABLE {$this->table_name} ADD COLUMN `data` longtext NOT NULL DEFAULT '' AFTER `status`;
			" );
		}

		// Return success/fail
		return $this->is_success( $result );

	}

	/**
	 * Upgrade to version 201908091
	 *
	 *      - Add `date_created` column.
	 *
	 * @since 3.1.2
	 * @return bool
	 */
	protected function __201908091() {

		// Look for column
		$result = $this->column_exists( 'date_created' );

		// Maybe add column
		if ( false === $result ) {
			$result = $this->get_db()->query( "
				ALTER TABLE {$this->table_name} ADD COLUMN `date_created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER `data`;
			" );
		}

		$success = $this->is_success( $result );

		rcp_log( sprintf( '%s upgrade to version 201908091 result: %s', $this->table_name, var_export( $success, true ) ), true );

		// Return success/fail
		return $success;

	}

	/**
	 * Upgrade to version 201908092
	 *
	 *      - Add `date_completed` column.
	 *
	 * @since 3.1.2
	 * @return bool
	 */
	protected function __201908092() {

		// Look for column
		$result = $this->column_exists( 'date_completed' );

		// Maybe add column
		if ( false === $result ) {
			$result = $this->get_db()->query( "
				ALTER TABLE {$this->table_name} ADD COLUMN `date_completed` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER `date_created`;
			" );
		}

		$success = $this->is_success( $result );

		rcp_log( sprintf( '%s upgrade to version 201908092 result: %s', $this->table_name, var_export( $success, true ) ), true );

		// Return success/fail
		return $success;

	}


}
