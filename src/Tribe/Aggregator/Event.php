<?php
// Don't load directly
defined( 'WPINC' ) or die;

class Tribe__Events__Aggregator__Event {

	/**
	 * Slug used to mark Event Orgin on `_EventOrigin` meta
	 *
	 * @var string
	 */
	public static $event_origin = 'event-aggregator';

	/**
	 * Key of the Meta to store the Event origin inside of Aggregator
	 *
	 * @var string
	 */
	public static $origin_key = '_tribe_aggregator_origin';

	/**
	 * Key of the Meta to store the Record that imported this Event
	 *
	 * @var string
	 */
	public static $record_key = '_tribe_aggregator_record';

	/**
	 * Key of the Meta to store the Record's source
	 *
	 * @var string
	 */
	public static $source_key = '_tribe_aggregator_source';

	/**
	 * Key of the Meta to store the Post Global ID
	 *
	 * @var string
	 */
	public static $global_id_key = '_tribe_aggregator_global_id';

	/**
	 * Key of the Meta to store the Post Global ID lineage
	 *
	 * @var string
	 */
	public static $global_id_lineage_key = '_tribe_aggregator_global_id_lineage';

	/**
	 * Key of the Meta to store the Record's last import date
	 *
	 * @var string
	 */
	public static $updated_key = '_tribe_aggregator_updated';

	public $data;

	public function __construct( $data = array() ) {
		// maybe translate service data to an Event array
		if ( is_object( $data ) && ! empty( $item->title ) ) {
			$data = self::translate_service_data( $data );
		}

		$this->data = $data;
	}

	public static function translate_service_data( $item ) {
		$event = array();
		$item = (object) $item;

		$field_map = array(
			'title'              => 'post_title',
			'description'        => 'post_content',
			'excerpt'            => 'post_excerpt',
			'start_date'         => 'EventStartDate',
			'start_hour'         => 'EventStartHour',
			'start_minute'       => 'EventStartMinute',
			'start_meridian'     => 'EventStartMeridian',
			'end_date'           => 'EventEndDate',
			'end_hour'           => 'EventEndHour',
			'end_minute'         => 'EventEndMinute',
			'end_meridian'       => 'EventEndMeridian',
			'timezone'           => 'EventTimezone',
			'url'                => 'EventURL',
			'all_day'            => 'EventAllDay',
			'image'              => 'image',
			'facebook_id'        => 'EventFacebookID',
			'meetup_id'          => 'EventMeetupID',
			'uid'                => 'uid',
			'parent_uid'         => 'parent_uid',
			'recurrence'         => 'recurrence',
			'categories'         => 'categories',
			'tags'               => 'tags',
			'id'                 => 'EventOriginalID',
			'currency_symbol'    => 'EventCurrencySymbol',
			'currency_position'  => 'EventCurrencyPosition',
			'cost'               => 'EventCost',
			'show_map'           => 'show_map',
			'show_map_link'      => 'show_map_link',
			'hide_from_listings' => 'hide_from_listings',
			'sticky'             => 'sticky',
			'featured'           => 'feature_event',
		);

		$venue_field_map = array(
			'facebook_id' => 'VenueFacebookID',
			'meetup_id' => 'VenueMeetupID',
			'venue' => 'Venue',
			'address' => 'Address',
			'city' => 'City',
			'country' => 'Country',
			'state' => 'State',
			'stateprovince' => 'Province',
			'zip' => 'Zip',
			'phone' => 'Phone',
			'website' => 'URL'
		);

		$organizer_field_map = array(
			'facebook_id' => 'OrganizerFacebookID',
			'meetup_id' => 'OrganizerMeetupID',
			'organizer' => 'Organizer',
			'phone' => 'Phone',
			'email' => 'Email',
			'website' => 'Website',
		);

		foreach ( $field_map as $origin_field => $target_field ) {
			if ( ! isset( $item->$origin_field ) ) {
				continue;
			}

			$event[ $target_field ] = $item->$origin_field;
		}

		if ( ! empty( $item->venue ) ) {
			$event['Venue'] = array();
			foreach ( $venue_field_map as $origin_field => $target_field ) {
				if ( ! isset( $item->venue->$origin_field ) ) {
					continue;
				}

				$event['Venue'][ $target_field ] = $item->venue->$origin_field;
			}
		}

		if ( ! empty( $item->organizer ) ) {
			$event['Organizer'] = array();
			foreach ( $organizer_field_map as $origin_field => $target_field ) {
				if ( ! isset( $item->organizer->$origin_field ) ) {
					continue;
				}

				$event['Organizer'][ $target_field ] = $item->organizer->$origin_field;
			}
		}

		/**
		 * Filter the translation of service data to Event data
		 *
		 * @param array $event EA Service data converted to Event API fields
		 * @param object $item EA Service item being being translated
		 */
		$event = apply_filters( 'tribe_aggregator_translate_service_data', $event, $item );

		return $event;
	}

	/**
	 * Fetch all existing unique IDs from the provided list that exist in meta
	 *
	 * @param string $key Meta key
	 * @param array $values Array of meta values
	 *
	 * @return array
	 */
	public function get_existing_ids( $origin, $values ) {
		global $wpdb;

		$fields = Tribe__Events__Aggregator__Record__Abstract::$unique_id_fields;

		if ( empty( $fields[ $origin ] ) ) {
			return array();
		}

		if ( empty( $values ) ) {
			return array();
		}

		$key = "_{$fields[ $origin ]['target']}";

		$sql = "
			SELECT
				meta_value,
				post_id
			FROM
				{$wpdb->postmeta}
			WHERE
				meta_value IN ( '" . implode( "','", $values ) ."' )
		";

		/**
		 * Allows us to check for legacy meta keys
		 */
		if ( ! empty( $fields[ $origin ]['legacy'] ) ) {
			$keys[] = $key;
			$keys[] = "_{$fields[ $origin ]['legacy']}";

			$sql .= 'AND meta_key IN ( "' . implode( '", "', array_map( 'esc_sql', $keys ) ) .'" )';
		} else {
			$sql .= 'AND meta_key = "' . esc_sql( $key ) . '"';
		}

		return $wpdb->get_results( $sql, OBJECT_K );
	}

	/**
	 * Fetch the Post ID for a given Global ID
	 *
	 * @param array $value The Global ID we are searching for
	 *
	 * @return bool|WP_Post
	 */
	public static function get_post_by_meta( $key = 'global_id', $value = null ) {
		if ( is_null( $value ) ) {
			return false;
		}

		$keys = array(
			'global_id' => self::$global_id_key,
			'global_id_lineage' => self::$global_id_lineage_key,
		);

		if ( ! isset( $keys[ $key ] ) ) {
			return false;
		}

		$key = $keys[ $key ];

		global $wpdb;

		$sql = "
			SELECT
				post_id
			FROM
				{$wpdb->postmeta}
			WHERE
				meta_key = '" . esc_sql( $key ) . "' AND
				meta_value = '" . esc_sql( $value ) . "'
		";
		$id = (int) $wpdb->get_var( $sql );

		if ( ! $id ) {
			return false;
		}

		return get_post( $id );
	}

	/**
	 * Preserves changed fields by resetting array indexes back to the stored post/meta values
	 *
	 * @param array $data Event array to reset
	 *
	 * @return array
	 */
	public static function preserve_changed_fields( $data ) {
		if ( empty( $data['ID'] ) ) {
			return $data;
		}

		$post       = get_post( $data['ID'] );
		$post_meta  = Tribe__Events__API::get_and_flatten_event_meta( $data['ID'] );
		$post_terms = Tribe__Events__API::get_event_terms( $data['ID'], array( 'fields' => 'ids' ) );
		$modified   = Tribe__Utils__Array::get( $post_meta, Tribe__Tracker::$field_key, array() );
		$tec        = Tribe__Events__Main::instance();

		// Depending on the Post Type we fetch other fields
		if ( Tribe__Events__Main::POSTTYPE === $post->post_type ) {
			$fields = $tec->metaTags;
		} elseif ( Tribe__Events__Venue::POSTTYPE === $post->post_type ) {
			$fields = $tec->venueTags;

			if ( isset( $data['Venue'] ) ) {
				$data['post_title'] = $data['Venue'];
				unset( $data['Venue'] );
			}

			if ( isset( $data['Description'] ) ) {
				$data['post_content'] = $data['Description'];
				unset( $data['Description'] );
			}

			if ( isset( $data['Excerpt'] ) ) {
				$data['post_excerpt'] = $data['Excerpt'];
				unset( $data['Excerpt'] );
			}
		} elseif ( Tribe__Events__Organizer::POSTTYPE === $post->post_type ) {
			$fields = $tec->organizerTags;

			if ( isset( $data['Organizer'] ) ) {
				$data['post_title'] = $data['Organizer'];
				unset( $data['Organizer'] );
			}

			if ( isset( $data['Description'] ) ) {
				$data['post_content'] = $data['Description'];
				unset( $data['Description'] );
			}

			if ( isset( $data['Excerpt'] ) ) {
				$data['post_excerpt'] = $data['Excerpt'];
				unset( $data['Excerpt'] );
			}
		} else {
			$fields = array();
		}

		$post_fields_to_reset = array(
			'post_title',
			'post_content',
			'post_status',
		);

		// reset any modified post fields
		foreach ( $post_fields_to_reset as $field ) {
			// don't bother resetting if the field hasn't been modified
			if ( ! isset( $modified[ $field ] ) ) {
				continue;
			}

			// don't bother resetting if we aren't trying to update the field
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			// don't bother resetting if we don't have a field to reset to
			if ( ! isset( $post->$field ) ) {
				continue;
			}

			$data[ $field ] = $post->$field;
		}

		// reset any modified meta fields
		foreach ( $fields as $field ) {
			// don't bother resetting if the field hasn't been modified
			if ( ! isset( $modified[ $field ] ) ) {
				continue;
			}

			// if we don't have a field to reset to, let's unset the event meta field
			if ( ! isset( $post_meta[ $field ] ) ) {
				unset( $data[ $field ] );
				continue;
			}

			// If the field name contains a leading underscore we need to strip it (or the field will not save)
			$field_name = trim( $field, '_' );
			$event[ $field_name ] = $post_meta[ $field ];
		}

		// The start date needs to be adjusted from a MySQL style datetime string to just the date
		if ( isset( $modified['_EventStartDate'] ) ) {
			$start_datetime = strtotime( $event['EventStartDate'] );
			$event['EventStartDate'] = date( Tribe__Date_Utils::DBDATEFORMAT, $start_datetime );
			$event['EventStartHour'] = date( 'H', $start_datetime );
			$event['EventStartMinute'] = date( 'i', $start_datetime );
		}

		// The end date needs to be adjusted from a MySQL style datetime string to just the date
		if ( isset( $modified['_EventEndDate'] ) ) {
			$end_datetime = strtotime( $event['EventEndDate'] );
			$event['EventEndDate'] = date( Tribe__Date_Utils::DBDATEFORMAT, $end_datetime );
			$event['EventEndHour'] = date( 'H', $end_datetime );
			$event['EventEndMinute'] = date( 'i', $end_datetime );
		}

		// reset any modified taxonomy terms
		$taxonomy_map = array(
			'post_tag'	                  => 'tags',
			Tribe__Events__Main::TAXONOMY => 'categories',
		);

		foreach ( $post_terms as $taxonomy => $terms ) {
			if ( ! isset( $modified[ $taxonomy ] ) ) {
				continue;
			}

			$tax_key = Tribe__Utils__Array::get( $taxonomy_map, $taxonomy, $taxonomy );
			$data[ $tax_key ] = $post_terms[ $taxonomy ];
		}

		return $data;
	}
}
