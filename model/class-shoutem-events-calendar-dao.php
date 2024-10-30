<?php
/**
 * This class is designed to work only with The Events Calendar by Modern Tribe Wordpress plugin.
 */
class ShoutemEventsCalendarDao extends ShoutemDao {
	
	public static function available() {
		return class_exists('Tribe__Events__Main');
	}
	
	public function categories() {
		return array('data' => array(
			array(
				'category_id' => 'ec',
				'name' => 'events',
				'allowed' => true
			)		
			),
			'paging' => array(
			)
		);
	}
	
	/**
	 * get event.
	 * Required params event_id
	 */
	public function get($params) {
		if (!class_exists('Tribe__Events__Main')) {			
			return false;
		}
		
		$event = tribe_get_events( array(
			'ID' =>($params['event_id'])
		));
			
		if ($event && is_array($event) && count($event) > 0) {
			return $this->convert_to_se_event($event[0]);
		}
		
		return false;			 
	}
	
	public function find($params) {
		if (!class_exists('Tribe__Events__Main')) {			
			return false;
		}
		$events = tribe_get_events( array(
			'posts_per_page' => ((int)$params['offset'] + (int)$params['limit'] + 1),
			'start_date' => current_time( 'Y-m-d' )));
		$events = array_slice($events, $params['offset']);
		
		$results = array();
		foreach($events as $event) {
			$results []= $this->convert_to_se_event($event);
		}
		
		return $this->add_paging_info($results, $params);
	}
			
	private function get_event_time($dateTime) {
		$splitDateTime= explode(" ", $dateTime);
		
		$splitDate = explode("-", $splitDateTime[0]);
		$month = $splitDate[1];
		$day = $splitDate[2];
		$year = $splitDate[0];
		
		$split_time = explode(":", $splitDateTime[1]);
		
		if (count($split_time) > 1) {
			$hour = $split_time[0];
			$minute = $split_time[1];
			return date(DATE_RSS, mktime($hour, $minute, 0, $month, $day, $year));
		} else {
			return date(DATE_RSS, mktime(0, 0, 0, $month, $day, $year));
		}
	}
		
	/**
	 * Converts from events calendar event to event as defined by 
	 * ShoutEm Data Exchange Protocol: @link http://fiveminutes.jira.com/wiki/display/SE/Data+Exchange+Protocol 
	 */
	private function convert_to_se_event($event) {
				
		$remaped_event = array(
			'post_id' => $event->ID,
			'start_time' => $this->get_event_time($event->EventStartDate),
			'end_time' => $this->get_event_time($event->EventEndDate),
			'name' => $event->post_title,
			'description' => wpautop($event->post_content),
			'image_url' => ''
		);
		$remaped_event['owner'] = array(
			'id' => null,
			'name' => null
		);
		if(tribe_get_venue($event->ID)){
			$location = tribe_get_coordinates($event->ID);
			
			if ($location['lat']!=0 && $location['lng']!=0){
				$venue = array(		
					'name' => tribe_get_venue($event->ID),
					'street' => tribe_get_address($event->ID),
					'city' => tribe_get_city($event->ID),
					'state' => '',
					'country' => tribe_get_country($event->ID),
					'latitude' => ($location['lat']),
					'longitude' => ($location['lng'])
				);
				$remaped_event['place'] = $venue;		
			}
		}
		
		$striped_attachments = array();
		
		$remaped_event['description'] = sanitize_html($remaped_event['description'], $striped_attachments);
		if (property_exists($event, 'ID')) {
			$this->include_leading_image_in_attachments($striped_attachments, $event->ID);
		}
		$remaped_event['body'] = $remaped_event['description'];
		$remaped_event['summary'] = html_to_text($remaped_event['description']);
		$remaped_event['attachments'] = $striped_attachments;
		
		return $remaped_event;	
	}
} 
?>