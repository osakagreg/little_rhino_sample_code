<?php
function update_calendar_event($group_id) 
{
    global $wpdb;
    /* Gather all users from specific group */
    $result = $wpdb->get_results("
		SELECT `user_id1`  
		FROM  wp_um_groups_members 
        JOIN wp_usermeta
        ON wp_usermeta.user_id = wp_um_groups_members.user_id1
			WHERE wp_um_groups_members.group_id = $group_id
			AND (wp_um_groups_members.status = 'approved'
			OR wp_um_groups_members.status = 'rejected'
			OR wp_um_groups_members.status = 'pending_member_review'
			OR wp_um_groups_members.status = 'pending_admin_review') 
            AND wp_usermeta.meta_key = 'um_groups_{$group_id}_price'
		GROUP BY `user_id1`; 
	");
	
    /* Primary player intials will be capitalized Secondary player initials will be lowercase */
    $names[] = '';
	$i = 0;
	foreach ( $result as $user )
	{
	   $user_id = $user->user_id1;
	   $user = get_userdata( $user_id );
	   $status = get_user_meta ( $user_id, '_um_groups_'.$group_id.'_status', true );
	   if ($status == 'primary'):
	   $names[] .= strtoupper($user->display_name);
	   else:
	   $names[] .= strtolower($user->display_name);
	   endif;
	   $i++;
	}
	/* If players are not yet assigned to a gig, use question marks */
	$no_players = get_post_meta ($group_id, '_um_groups_event_players', true);
	$count_user = $i;
	if ($no_players > $count_user){
		$names[] .= "? ?";
	}
	
    /* Format names to use first letter of first name and first four letters of last name */
    $initials = implode('/', array_map(function ($name) { 
    $parts = explode(' ', $name);
	if ($parts[0]!=''){
		return $parts[0][0] . substr($parts[1], 0, 4);
	}
	}, $names));
	
    if ($initials == ''):
        $cal_initials = '';
    else:
        $initials = ltrim($initials, '/');
        $cal_initials = "(" . $initials . ")";
    endif;

    /* Retrieve additional data from gig that will be displayed with letters and symbols on the calendar */
    $event_id = get_post_meta($group_id, '_um_groups_cal_event_edit_id', true);
    $start = get_post_meta($group_id, '_um_groups_event_start', true);
    $post_title = get_the_title($group_id);
    $player_initials = $cal_initials;
    if (has_term($term = 'Cancelled', $taxonomy = 'um_group_categories', $post = $group_id)): $player_initials = "(XX)"; endif;
    $description = get_post_meta($group_id, '_um_groups_description');
    $options = array(
        'Shells: Full' => '*',
        'Uplights: Yes' => '^',
        'Projector: Yes' => '>'
    );
    $cal_tick = '';
    foreach ($options as $option => $ticker) {
        if (strpos($description[0], $option) !== false) {
            $cal_tick .= $ticker;
        }
    }
    $cstatus = "";
    $contract = get_post_meta($group_id, '_um_groups_contract', true);
    $deposit = get_post_meta($group_id, '_um_groups_deposit', true);
    $paid = get_post_meta($group_id, '_um_groups_paid_in_full', true);
	$repeat = get_post_meta($group_id, '_um_groups_repeat_gig', true);
	if ($repeat == 'repeat') {
        $cstatus = "R";
    }
    if ($contract == 'pending') {
        $cstatus .= "C";
    }
    if ($deposit == 'pending') {
        $cstatus .= "D";
    }
    if ($paid == 'received') {
        $cstatus .= "'";
    }
    if ($cal_tick != ''):
        $title = $cstatus . $cal_tick . $player_initials . " " . $post_title;
    else:
        $title = $cstatus . $player_initials . " " . $post_title;
    endif;
    $description = get_post_meta($group_id, '_um_groups_description', true);
    $venue = get_post_meta($group_id, '_um_groups_event_venue', true);
    $event_city = get_post_meta($group_id, '_um_groups_event_location', true);
    $event_state = get_post_meta($group_id, '_um_groups_event_state', true);
    $location = $venue . ", " . $event_city . ", " . $event_state;
    $event_rep_email = get_post_meta($group_id, '_um_groups_event_sales_rep_email', true);
    $sales_rep = get_user_by('email', $event_rep_email)->display_name;
	$event_type = get_post_meta($group_id, '_um_groups_event_type', true);

    /* Determine color coding to be used with calendar API */
	if (strpos($event_type, 'Wedding') !== false) {
    	$color = 2;
	} elseif (strpos($event_type, 'Virtual') !== false) {
    	$color = 10;
	} elseif (strpos($event_type, 'Corporate') !== false) {
    	$color = 14;
	} elseif (strpos($event_type, 'Fundraiser') !== false) {
    	$color = 5;
	} elseif (strpos($event_type, 'Public') !== false) {
    	$color = 13;
	} elseif (strpos($event_type, 'Birthday') !== false) {
    	$color = 18;
	} elseif (strpos($event_type, 'Club') !== false) {
    	$color = 20;
	} else {
    	$color = 1;
	}
	
    /* now save the data to External Calendar */ 
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.addevent.com/api/v1/oe/events/save/");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "token={removed for sample code}&event_id=" . $event_id . "&title=" . $title . "&start_date=" . $start . "&description=" . $description . "&location=" . $location . "&organizer=" . $sales_rep . "&organizer_email=" . $event_rep_email . "&all_day_event=true&color=" . $color ."&custom_data=" . $group_id . "");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $server_output = curl_exec($ch);
    curl_close($ch);
	
    if ($event_id =='') {
		delete_post_meta ($group_id, '_um_groups_cal_event_edit_id');
		delete_post_meta ($group_id, '_um_groups_cal_event_id');	
		add_calendar_event( $title, $start, $description, $location, $sales_rep, $event_rep_email, $color, $group_id );
	}
	//echo $cal_initials;
}

function add_calendar_event($title, $start, $description, $location, $organizer, $email, $color, $group_id)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.addevent.com/api/v1/me/calendars/events/create");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "token={removed for sample code}&calendar_id={removed for sample code}&title=" . $title . "&start_date=" . $start . "&description=" . $description . "&location=" . $location . "&organizer=" . $organizer . "&organizer_email=" . $email . "&all_day_event=true&color=" . $color ."&custom_data=" . $group_id . "");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $server_output = curl_exec($ch);
    curl_close($ch);
    $var = json_decode($server_output, true);
    $event_unique = $var["event"]["unique"];
    $event_id = $var["event"]["id"];
    if ($event_id != '') {
        add_post_meta($group_id, '_um_groups_cal_event_edit_id', $event_id);
        add_post_meta($group_id, '_um_groups_cal_event_id', $event_unique);
    }
}
?>