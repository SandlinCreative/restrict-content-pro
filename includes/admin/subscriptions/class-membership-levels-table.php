<?php
/**
 * Membership Levels List Table
 *
 * @package   restrict-content-pro
 * @copyright Copyright (c) 2019, Restrict Content Pro
 * @license   GPL2+
 * @since     3.1
 */

namespace RCP\Admin;

/**
 * Class Membership_Levels_Table
 *
 * @since   3.1
 * @package RCP\Admin
 */
class Membership_Levels_Table extends List_Table {

	/**
	 * Constructor.
	 *
	 * @since 3.1
	 * @see   WP_List_Table::__construct()
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => __( 'Membership Level', 'rcp' ),
			'plural'   => __( 'Membership Levels', 'rcp' ),
			'ajax'     => false,
		) );

		$this->process_bulk_action();
		$this->get_counts();
	}

	/**
	 * Get the base URL for the membership levels list table.
	 *
	 * @return string Base URL.
	 * @since 3.1
	 */
	public function get_base_url() {

		$args = array(
			'page' => 'rcp-member-levels'
		);

		$levels_page = add_query_arg( $args, admin_url( 'admin.php' ) );

		return $levels_page;

	}

	/**
	 * Retrieve the table columns.
	 *
	 * @return array
	 * @since 3.1
	 */
	public function get_columns() {
		$columns = array(
			'cb'           => '<input type="checkbox" />',
			'name'         => __( 'Name', 'rcp' ),
			'description'  => __( 'Description', 'rcp' ),
			'status'       => __( 'Status', 'rcp' ),
			'access_level' => __( 'Access Level', 'rcp' ),
			'duration'     => __( 'Duration', 'rcp' ),
			'price'        => __( 'Price', 'rcp' ),
			'memberships'  => __( 'Memberships', 'rcp' )
		);

		/*
		 * Backwards compatibility: add an "extra" column if someone is hooking into the old action to add
		 * their own column. Everything gets bundled into one column because this is the only way we can realistically
		 * do it.
		 */
		if ( has_action( 'rcp_levels_page_table_header' ) ) {
			$columns['custom'] = __( 'Extra', 'rcp' );
		}

		// Now add "order" in, because we want that to be last.
		$columns['order'] = __( 'Order', 'rcp' );

		/**
		 * Filters the table columns.
		 *
		 * @param array $columns
		 *
		 * @since 3.1
		 */
		$columns = apply_filters( 'rcp_membership_levels_list_table_columns', $columns );

		return $columns;
	}

	/**
	 * Retrieve the sortable columns.
	 *
	 * @return array
	 * @since 3.1
	 */
	public function get_sortable_columns() {
		return array();

		// @todo At some point we'll use the following:
		return array(
			'name'         => array( 'name', false ),
			'status'       => array( 'status', false ),
			'access_level' => array( 'access_level', false ),
			'price'        => array( 'price', false )
		);
	}

	/**
	 * Gets the name of the primary column.
	 *
	 * @return string
	 * @since 3.1
	 */
	protected function get_primary_column_name() {
		return 'name';
	}

	/**
	 * This function renders any other columns in the list table.
	 *
	 * @param object $membership_level Membership level object.
	 * @param string $column_name      The name of the column
	 *
	 * @return string Column Name
	 * @since 3.1
	 */
	public function column_default( $membership_level, $column_name ) {

		$value = '';

		switch ( $column_name ) {

			case 'description' :
				$value = stripslashes( $membership_level->description );
				break;

			case 'status' :
				$value = ucwords( $membership_level->status );
				break;

			case 'access_level' :
				$value = ! empty( $membership_level->level ) ? $membership_level->level : __( 'none', 'rcp' );
				break;

			case 'duration' :
				if ( $membership_level->duration > 0 ) {
					$value = $membership_level->duration . ' ' . rcp_filter_duration_unit( $membership_level->duration_unit, $membership_level->duration );
				} else {
					$value = __( 'unlimited', 'rcp' );
				}
				break;

			case 'price' :
				$price = rcp_get_subscription_price( $membership_level->id );
				if ( ! $price ) {
					$value = __( 'Free', 'rcp' );
				} else {
					$value = rcp_currency_filter( $price );
				}
				break;

			case 'memberships' :
				$memberships_page = rcp_get_memberships_admin_page( array( 'object_id' => urlencode( $membership_level->id ) ) );
				$membership_count = rcp_count_memberships( array(
					'status__in' => array( 'active', 'cancelled' ),
					'object_id'  => absint( $membership_level->id )
				) );

				$value = '<a href="' . esc_url( $memberships_page ) . '">' . $membership_count . '</a>';
				break;

			case 'order' :
				$value = '<a href="#" class="rcp-drag-handle"></a>';
				break;

		}

		/*
		 * Backwards compatibility: show content of custom columns from old action hook.
		 */
		if ( 'custom' == $column_name && has_action( 'rcp_levels_page_table_column' ) ) {
			ob_start();
			do_action( 'rcp_levels_page_table_column', $membership_level->id );
			$column_content = ob_get_clean();

			$value = wp_strip_all_tags( $column_content );
		}

		/**
		 * Filters the column value.
		 *
		 * @param string $value            Column value.
		 * @param object $membership_level Membership level object.
		 *
		 * @since 3.1
		 */
		$value = apply_filters( 'rcp_membership_levels_list_table_column_' . $column_name, $value, $membership_level );

		return $value;

	}

	/**
	 * Render the checkbox column.
	 *
	 * @param object $membership_level
	 *
	 * @return string
	 * @since 3.1
	 */
	public function column_cb( $membership_level ) {
		return sprintf(
			'<input type="checkbox" name="%1$s[]" value="%2$s" />',
			'membership_level_id',
			$membership_level->id
		);
	}

	/**
	 * Render the "Name" column.
	 *
	 * @param object $membership_level
	 *
	 * @return string
	 * @since 3.1
	 */
	public function column_name( $membership_level ) {

		$edit_level_url = add_query_arg( 'edit_subscription', $membership_level->id, $this->get_base_url() );

		$actions = array(
			'edit' => '<a href="' . esc_url( $edit_level_url ) . '">' . __( 'Edit', 'rcp' ) . '</a>',
		);

		if ( 'active' == $membership_level->status ) {
			$actions['deactivate'] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'rcp-action' => 'deactivate_subscription', 'level_id' => $membership_level->id ), $this->get_base_url() ), 'rcp-deactivate-subscription-level' ) ) . '">' . __( 'Deactivate', 'rcp' ) . '</a>';
		} else {
			$actions['activate'] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'rcp-action' => 'activate_subscription', 'level_id' => $membership_level->id ), $this->get_base_url() ), 'rcp-activate-subscription-level' ) ) . '">' . __( 'Activate', 'rcp' ) . '</a>';
		}

		$actions['delete']   = '<span class="trash"><a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'rcp-action' => 'delete_subscription', 'level_id' => $membership_level->id ), $this->get_base_url() ), 'rcp-delete-subscription-level' ) ) . '" class="rcp_delete_subscription">' . __( 'Delete', 'rcp' ) . '</a></span>';
		$actions['level_id'] = '<span class="rcp-sub-id-col rcp-id-col">' . sprintf( __( 'ID: %d', 'rcp' ), $membership_level->id ) . '</span>';

		ob_start();
		/**
		 * @deprecated 3.1 Use `rcp_membership_levels_list_table_row_actions` instead.
		 */
		do_action( 'rcp_membership_level_row_actions', $membership_level );
		$custom_row_actions = ob_get_clean();
		if ( $custom_row_actions ) {
			$actions['custom_row_actions'] = $custom_row_actions;
		}

		/**
		 * Filters the row actions.
		 *
		 * @param array  $actions          Default actions.
		 * @param object $membership_level Membership level object.
		 *
		 * @since 3.1
		 */
		$actions = apply_filters( 'rcp_membership_levels_list_table_row_actions', $actions, $membership_level );

		$final = '<strong><a class="row-title" href="' . esc_url( $edit_level_url ) . '">' . esc_html( $membership_level->name ) . '</a></strong>';

		if ( current_user_can( 'rcp_manage_levels' ) ) {
			$final .= $this->row_actions( $actions );
		}

		return $final;

	}

	/**
	 * Message to be displayed when there are no membership levels.
	 *
	 * @return void
	 * @since 3.1
	 */
	public function no_items() {
		esc_html_e( 'No membership levels found.', 'rcp' );
	}

	/**
	 * Retrieve the bulk actions.
	 *
	 * @return array
	 * @since 3.1
	 */
	public function get_bulk_actions() {
		return array(
			'activate'   => __( 'Activate', 'rcp' ),
			'deactivate' => __( 'Deactivate', 'rcp' ),
			'delete'     => __( 'Permanently Delete', 'rcp' )
		);
	}

	/**
	 * Process bulk actions.
	 *
	 * @return void
	 * @since 3.1
	 */
	public function process_bulk_action() {

		// Bail if a nonce was not supplied.
		if ( ! isset( $_REQUEST['_wpnonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-membershiplevels' ) ) {
			return;
		}

		$ids = wp_parse_id_list( (array) $this->get_request_var( 'membership_level_id', false ) );

		// Bail if no IDs
		if ( empty( $ids ) ) {
			return;
		}

		$levels = new \RCP_Levels();

		foreach ( $ids as $level_id ) {
			switch ( $this->current_action() ) {
				case 'activate':
					$levels->update( $level_id, array( 'status' => 'active' ) );
					break;

				case 'deactivate':
					$levels->update( $level_id, array( 'status' => 'inactive' ) );
					break;

				case 'delete':
					$levels->remove( $level_id );
					break;
			}
		}

		$this->show_admin_notice( $this->current_action() );

	}

	/**
	 * Show admin notice for bulk actions.
	 *
	 * @param string $action The action to show the notice for.
	 *
	 * @access private
	 * @return void
	 * @since  3.1
	 */
	private function show_admin_notice( $action ) {

		$message = '';

		switch ( $action ) {
			case 'activate' :
				$message = __( 'Membership level(s) activated.', 'rcp' );
				break;

			case 'deactivate' :
				$message = __( 'Membership level(s) deactivated.', 'rcp' );
				break;

			case 'delete' :
				$message = __( 'Membership level(s) deleted.', 'rcp' );
				break;
		}

		if ( empty( $message ) ) {
			return;
		}

		echo '<div class="updated"><p>' . $message . '</p></div>';

	}

	/**
	 * Retrieve the membership level counts.
	 *
	 * @return void
	 * @since 3.1
	 */
	public function get_counts() {
		$levels = new \RCP_Levels();

		$this->counts = array(
			'total'    => $levels->count(),
			'active'   => $levels->count( array( 'status' => 'active' ) ),
			'inactive' => $levels->count( array( 'status' => 'inactive' ) )
		);
	}

	/**
	 * Retrieve membership levels data.
	 *
	 * @param bool $count Whether or not to get membership level objects (false) or just count the total number (true).
	 *
	 * @since 3.1
	 * @return array|int
	 */
	public function levels_data( $count = false ) {

		$levels = new \RCP_Levels();

		$args = array(
			'limit'   => $this->per_page,
			'offset'  => $this->get_offset(),
			'orderby' => sanitize_text_field( $this->get_request_var( 'orderby', 'list_order' ) ),
			'order'   => sanitize_text_field( $this->get_request_var( 'order', 'ASC' ) ),
			'status'  => $this->get_status(),
		);

		if ( $count ) {
			return $levels->count( $args );
		}

		return $levels->get_levels( $args );
	}

	/**
	 * Setup the final data for the table.
	 *
	 * @return void
	 * @since 3.1
	 */
	public function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );
		$this->items           = $this->levels_data();

		$total = $this->levels_data( true );

		// Setup pagination
		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $this->per_page,
			'total_pages' => ceil( $total / $this->per_page )
		) );
	}

	/**
	 * Get a list of CSS classes for the WP_List_Table table tag.
	 *
	 * We override this so we can add the "rcp-subscriptions" class for backwards compatibility.
	 *
	 * @since 3.1
	 *
	 * @return array List of CSS classes for the table tag.
	 */
	public function get_table_classes() {
		return array( 'widefat', 'fixed', 'striped', $this->_args['plural'], 'rcp-subscriptions' );
	}

}