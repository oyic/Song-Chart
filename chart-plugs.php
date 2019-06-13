<?php 
/**
 * @package Boomlabs Plugin to Expose Custom Query REST API for charts
 * @version 1.0
 */
/*
Plugin Name: Boomlabs Charts Plugin 2
Plugin URI: http://boomlabs.tv/wp-plugins
Description: Custom Plugin for Charts and Votes
Author: Boomlabs
Version: 2.0
Author URI: http://boomlabs.tv/
*/


defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
register_activation_hook( __FILE__, 'chart_install' ); 
//register_deactivation_hook( __FILE__, 'chart_deactivation' );

function chart_uninstall(){
	global $wpdb;
	$chart_table = $wpdb->prefix . '_boom_chart';
	$votes_table = $wpdb->prefix . '_boom_votes';
	$old_table = $wpdb->prefix . 'boom_vote_charts';
	$sql = "DROP TABLE IF EXISTS $chart_table,$votes_table,$old_table";
	$wpdb->query($sql);
}

function chart_deactivation()
{
	wp_clear_scheduled_hook('rollOverChart');
}
function chart_install(){
	global $wpdb;

	$chart_table = $wpdb->prefix . '_boom_chart';

	if($wpdb->get_var("show tables like '$chart_table'") != $chart_table ){
		$sql = "CREATE TABLE " . $chart_table . " (
		`id` int UNSIGNED NOT NULL AUTO_INCREMENT,
		`song_id`  int UNSIGNED NOT NULL,
		`date`  int UNSIGNED NOT NULL,
		`period_id` int UNSIGNED NOT NULL,
		`chart_id` int UNSIGNED NOT NULL,
		`ranking` int(3) DEFAULT 999,
		`dj_vote` int UNSIGNED,
		`votes`  int UNSIGNED,
		`total_votes`  int UNSIGNED,
		`last_ranking`  int UNSIGNED,
		`all_time_votes`  int UNSIGNED,
		`num_weeks`  int UNSIGNED,
		`highest_ranking`  int UNSIGNED,
		UNIQUE KEY id (id)
		);";
 		
		dbDelta($sql);
	}
	$votes_table = $wpdb->prefix . '_boom_votes';
	if($wpdb->get_var("show tables like '$votes_table'") != $votes_table ){
		$sql = "CREATE TABLE " . $votes_table . " (
		`id` int UNSIGNED NOT NULL AUTO_INCREMENT,
		`song_id`  int UNSIGNED NOT NULL,
		`chart_id` int UNSIGNED NOT NULL,
		`period_id` int UNSIGNED NOT NULL,
		`user_info` varchar(50),
		`cookie_id` varchar(50),
		`user_id`  int UNSIGNED,
		UNIQUE KEY id (id)
		);";
 		
		dbDelta($sql);
	}
	if (! wp_next_scheduled ( 'my_hourly_event' )) {
		wp_schedule_event(time(), 'hourly', 'rollOverChart');
    }

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');


}

// ROUTES
add_action( 'rest_api_init', function () {
	register_rest_route( 'boomlabs/v2', '/vote', array(
	    'methods' => 'POST',
	    'callback' => 'songVote',
	  ) );
	register_rest_route( 'boomlabs/v2', '/carta', array(
	    'methods' => WP_REST_Server::READABLE,
	    'callback' => 'charts',
	  ) );
	register_rest_route( 'boomlabs/v2', '/carta/(?P<term>[a-zA-Z0-9-]+)', array(
	    'methods' => WP_REST_Server::READABLE,
	    'callback' => 'charts',
	  ) );
	register_rest_route( 'boomlabs/v2', '/djvotes', array(
	    'methods' => 'POST',
	    'callback' => 'djVotes',
	  ) );
	register_rest_route( 'boomlabs/v2', '/ajaxrank', array(
	    'methods' => 'POST',
	    'callback' => 'ajaxRank2',
	  ) );
	register_rest_route( 'boomlabs/v2', '/rollback', array(
	    'methods' => WP_REST_Server::READABLE,
	    'callback' => 'testdata',
	  ) );
});

function rollOverChart()
{

}

add_filter( 'cron_schedules', 'extra_intervals' );

function extra_intervals( $schedules ) {

   $schedules['5sec'] = array( // Provide the programmatic name to be used in code
      'interval' => 5, // Intervals are listed in seconds
      'display' => __('Every 5 Seconds') // Easy to read display name
   );
   return $schedules; // Do not forget to give back the list of schedules!
}


// AUTO POPULATE CHART TABLE 
add_action('save_post', 'save_chart_post', 10,3);

function save_chart_post($post_id, $post, $update){
	if('song'!= $post->post_type) return;

	if(isset($_POST['tax_input']['carta-chart'])){
		$newCharts = ($_POST['tax_input']['carta-chart']);
		$currentCharts = extractCurrentCharts($post_id);
		
		insertSongOnChart(array_diff($newCharts,$currentCharts),$post_id);
		deleteSongOnChart(array_diff($currentCharts,$newCharts),$post_id);
	}

}
add_action( 'trashed_post', 'thrashed_it' );
function thrashed_it( $postid ){
	global $wpdb;
	$chart_table = $wpdb->prefix .'_boom_chart';
	$currentPeriod = absint(date('W').date('Y'));
	$wpdb->delete($chart_table,
				  array('song_id'=>$postid,
						'period_id'=>$currentPeriod,
						),
				  array('%d','%d')
				);
	
}
add_action( 'delete_term_taxonomy', function($tt_id) {

    $taxonomy = 'carta-chart';    
    $term = get_term_by('term_taxonomy_id', $tt_id, $taxonomy); 
    $chart_id = $term->term_taxonomy_id;
    global $wpdb;
	$chart_table = $wpdb->prefix .'_boom_chart';
	$wpdb->delete($chart_table,
				  array('chart_id'=>$chart_id),array('%d')
				);
    

}, 9, 1);

function extractCurrentCharts($post_id)
{
	global $wpdb;
	$chart_table = $wpdb->prefix .'_boom_chart';
	$currentPeriod = absint(date('W').date('Y'));
	return wp_list_pluck( $wpdb->get_results("
		SELECT chart_id	FROM $chart_table
			WHERE period_id = $currentPeriod
			AND song_id = $post_id
			"),
			'chart_id'
	);


}
function deleteSongOnChart($delCharts,$song_id){
	global $wpdb;
	$table_name = $wpdb->prefix . '_boom_chart';
	$period_id = absint(date('W').date('Y'));
	foreach ($delCharts as $chart) {
		if($chart != 0 && songExistOnChart($song_id,$period_id,$chart)>0){
			$wpdb->delete($table_name,
				  array('song_id'=>$song_id,
						'period_id'=>$period_id,
						'chart_id'=>$chart,),
				  array('%d','%d','%d')
				);
			//updateVotesFromTable($song_id,$chart_id,$period_id);
			rankSongsOnChart($chart,$period_id);
			updateChartComponents($chart,$period_id);
		}
	}
}
function insertSongOnChart($newCharts,$song_id){
	global $wpdb;
	$table_name = $wpdb->prefix . '_boom_chart';
	$period_id = absint(date('W').date('Y'));
	foreach ($newCharts as $chart) {
		if($chart != 0 && songExistOnChart($song_id,$period_id,$chart)==0){
			$wpdb->insert($table_name,
				  array('song_id'=>$song_id,
						'period_id'=>$period_id,
						'chart_id'=>$chart,
						'date'=> time()),
				  array('%d','%d','%d','%d')
				);
			//updateVotesFromTable($song_id,$chart_id,$period_id);
			rankSongsOnChart($chart,$period_id);
			updateChartComponents($chart,$period_id);
		}
	}
}

function songsOnChart($carta_id,$week_id,$year_id){
	global $wpdb;
	$chart_table = $wpdb->prefix . '_boom_chart';
	return $wpdb->get_results("SELECT song_id
						FROM $chart_table
						WHERE chart_id = '$carta_id' AND 
							  week_id = $week_id AND 
							  year_id = $year_id
				
					");
}

function songExistOnChart($song_id,$period_id,$carta_id){
	global $wpdb;
	$chart_table = $wpdb->prefix . '_boom_chart';
	return $wpdb->get_var("SELECT COUNT(*)
						FROM $chart_table
						WHERE chart_id = '$carta_id' AND 
							  period_id = $period_id AND 
							  song_id = $song_id
					");
	
}
//204 1497 11
function songVote(){
	global $wpdb;
	$votes_table = $wpdb->prefix.'_boom_votes';

	$song_id = isset($_REQUEST['song'])?$_REQUEST['song']:FALSE;
	$chart_id = isset($_REQUEST['chart'])?$_REQUEST['chart']:FALSE;
	$period_id = isset($_REQUEST['period'])?$_REQUEST['period']:FALSE;

	$periodNow = date('W').date('Y');

	if( ! $song_id )
		return array('success'=>FALSE,
					 'message'=>'Invalid Song',
			   );
	if( ! $chart_id )
		return array('success'=>FALSE,
					 'message'=>'Invalid Carta',
			   );
	if( ! $period_id && $period_id != $periodNow)
		return array('success'=>FALSE,
					 'message'=>'Invalid Range Period.',
			   );
	if($period_id!=$periodNow){
		return array('success'=>FALSE,
					 'message'=>'Period already passed.',
			   );
	}

	$user_id = isset($_REQUEST['user'])?$_REQUEST['user']:NULL;
	
	$cookie_id = isset($_REQUEST['cookie_id'])?$_REQUEST['cookie_id']:FALSE;
	
	
	// song_id
	if( ! get_post($song_id))
		return array('success'=>FALSE,
					 'message'=>'Invalid Song',
	  );
	//chart
	if( ! has_term( $chart_id,'carta-chart', $song_id ) )
		return array('success'=>FALSE,
					 'message'=>'Invalid Carta',
	);

	if(!isSongExistOnCartaPeriod($song_id,$chart_id,$period_id))
		return array('success'=>FALSE,
					 'message'=>'Song is not in Carta',
	 );

	//Defaults

	$user_name = 'Guest';
	
	// check if user exists
	if(!is_null($user_id)){
		$current_user = get_userdata($user_id);
		if($current_user){
			$user_id = $current_user->ID;
			$user_name = $current_user->display_name;
			if(userAlreadyVoted($user_id,$song_id,$chart_id,$period_id)){
				return array('success'=>false,
						 'message'=>"User:$user_name already voted!");
			}
		}
		else{
			return array('success'=>false,
						 'message'=>'User ID is not valid');
		}
	}
	else{
		// coookie
		if(hasCookies($cookie_id)){
			return array('success'=>false,
						 'message'=>'Guest already voted');
		}
	}
	
	$success =  $wpdb->insert( 
							$votes_table, 
							array( 
								'song_id'=>$song_id,
								'user_info'=>$user_name,
								'chart_id'=>$chart_id,
								'user_id'=>$user_id,
								'period_id'=>$period_id,
							),
							array('%d','%s','%d','%d','%d','%d')
						);
			
	$message = 'Error: '. $wpdb->last_error; 
			
	if($success){
		$message = 'Succesfully voted';
	 
	 	rankSongsOnChart($chart_id,$period_id);
	 	updateChartComponents($chart_id,$period_id);
	 	// updateAllTimeVotes($chart_id,$song_id,$period_id);
	 	// updateHighestRanking($chart_id,$song_id,$period_id);
	 	// updateLastRank($chart_id,$song_id,$period_id);
	 	// updateNumWeeks($chart_id,$song_id,$period_id);
	}
	return array('success'=>$success,'message'=>$message);
}
function updateNumWeeks($chart_id,$song_id,$period_id)
{
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';
	$wpdb->update($chart_table,
				 array('num_weeks'=>getNumWeeks($song_id,$chart_id)),
				 array('song_id'=>$song_id,
					   'chart_id'=>$chart_id,
					   'period_id'=>$period_id),
				 array('%d')
				);
}
function updateLastRank($song_id,$chart_id,$period_id)
{
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';
	//break $period_id to get week number;
	$week = intval(substr($period_id, 0,strlen($period_id)<6?1:2 ) );
	$year = intval(substr($period_id,strlen($period_id)<6?1:2 ) );
	$lastPeriod = $week-1;
	$lastPeriod = $lastPeriod.$year;
	if($week == 1){
		$last_year = $year-1;
		$lastPeriod = getIsoWeeksInYear($last_year).$last_year;
	}
	$last_rank = $wpdb->get_var(
					"SELECT ranking FROM $chart_table
					WHERE song_id = $song_id AND chart_id = $chart_id AND period_id =$lastPeriod LIMIT 1
					");
	$wpdb->update(
				$chart_table,
				array('last_ranking'=>$last_rank),
				array('song_id'=>$song_id,'chart_id'=>$chart_id,'period_id'=>$period_id),
				array('%d')
			);
}
function updateHighestRanking($chart_id,$song_id,$period_id)
{
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';
	$highest = $wpdb->get_results("
					 SELECT ranking FROM $chart_table
					 		WHERE chart_id = $chart_id
					 			AND song_id = $song_id
					 			ORDER BY ranking ASC LIMIT 1
					",ARRAY_A);
	foreach ($highest as $key => $value) {
		$rank = $value['ranking'];
	}
	$wpdb->update(
				$chart_table,
				array('highest_ranking'=>$rank),
				array('song_id'=>$song_id,
			          'chart_id'=>$chart_id,
			          'period_id'=>$period_id),
				array('%d')
	);
}

function updateAllTimeVotes($chart_id,$song_id,$period_id)
{
	global $wpdb;
	$votes_table = $wpdb->prefix.'_boom_votes';
	$chart_table = $wpdb->prefix.'_boom_chart';
	$all_votes = $wpdb->get_var("
						SELECT COUNT(*)
							FROM $votes_table
							WHERE song_id = $song_id AND 
								  chart_id = $chart_id
					");
	$wpdb->update(
				$chart_table,
				array('all_time_votes'=>$all_votes),
				array('song_id'=>$song_id,'chart_id'=>$chart_id,'period_id'=>$period_id),
				array('%d')
	);

}
function isSongExistOnCartaPeriod($song_id,$carta_id,$period_id)
{
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';
	return  $wpdb->get_var("
				SELECT COUNT(*)
					FROM $chart_table
					WHERE song_id = $song_id AND 
						  chart_id = $carta_id AND
						  period_id = $period_id
					");
}

function userAlreadyVoted($user_id,$song_id,$carta_id,$period_id){
	global $wpdb;
	$votes_table = $wpdb->prefix.'_boom_votes';
	$isVoted = $wpdb->get_var("SELECT COUNT(*)
						FROM $votes_table
						WHERE song_id = $song_id  AND
							  chart_id =$chart_id AND 
							  period_id =$period_id AND 
							  user_id = $user_id 
					");
	return $isVoted>0?TRUE:FALSE;
}
//checkcookies/<cookie>
function hasCookies($cookie_id){
	// FrontEnd Called 
	global $wpdb;
	$table_name = $wpdb->prefix.'_boom_votes';
	$exists = $wpdb->get_var("
			SELECT COUNT(*)
			FROM $table_name
			WHERE cookie_id LIKE '$cookie_id'
		");
	return $exists>0?TRUE:FALSE;

}
function rankSongsOnChart($chart_id,$period_id){
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';

	$unsorted = $wpdb->get_results("
				SELECT * FROM $chart_table
					WHERE chart_id = $chart_id
					AND period_id = $period_id
					ORDER BY ranking,last_ranking,date ASC 
		",ARRAY_A);
	if(count($unsorted)>0){
		foreach ($unsorted as $key => $value) {
			$votes = getSongTotalVotes($value['song_id'],$value['chart_id'],$value['period_id']);
			$total_votes = $votes + $value['dj_vote'];
			$a4ranking[] = array(
				'id'=>$value['id'],
				'date'=>$value['date'],
				'song_id'=>$value['song_id'],
				'period_id'=>$value['period_id'],
				'chart_id'=>$value['chart_id'],
				'ranking'=>$value['ranking'],
				'last_ranking'=>$value['last_ranking'],
				'dj_vote'=>$value['dj_vote'],
				'votes'=>$votes,
				'total_votes'=>$total_votes
			);
		}
	
	}
	array_multisort(array_column($a4ranking, 'total_votes'),  SORT_DESC,
                array_column($a4ranking, 'last_ranking'), SORT_ASC,
                $a4ranking);
		//updateRankingRecords($songs2sort,$carta_id,$period_id);
	$rank = 1;
	foreach ($a4ranking as $key => $value) {
		$value['ranking']= $rank;
		$wpdb->update(
			$chart_table,
			array(
				"ranking"=>$rank,
				"votes"=>$value['votes'],
				"total_votes"=>$value['total_votes'],
				
					),
			array('song_id'=>$value['song_id'],
				  'chart_id'=>$value['chart_id'],
				  'period_id'=>$value['period_id']
					),
			array("%d","%d","%d")
			);
		$rank=$rank+1;
	}

}

function getIsoWeeksInYear($year) 
{
    $date = new DateTime;
    $date->setISODate($year, 53);
    return ($date->format("W") === "53" ? 53 : 52);
} 

function getAllTimevotes($song_id,$chart_id){
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';
	$votes_table = $wpdb->prefix.'_boom_votes';
	$dj=  $wpdb->get_var("
			SELECT  SUM(dj_vote) FROM $chart_table
				WHERE song_id = $song_id AND chart_id = $chart_id
		");
	$votes =  $wpdb->get_var("
			SELECT  COUNT(*) FROM $votes_table
				WHERE song_id = $song_id AND chart_id = $chart_id
		");
	return $dj+$votes;
}

function getSongDJVote($song_id,$chart_id,$period_id){
	global $wpdb;
	$votes_table = $wpdb->prefix.'_boom_chart';
	//$chart_table = $wpdb->prefix.'_boom_chart';
	return $wpdb->get_var("SELECT dj_vote
					FROM $votes_table
					WHERE song_id =$song_id AND 
						  chart_id = $chart_id AND 
						  period_id = $period_id");
}

function getSongTotalVotes($song_id,$chart_id,$period_id){
	global $wpdb;
	$votes_table = $wpdb->prefix.'_boom_votes';
	//$chart_table = $wpdb->prefix.'_boom_chart';
	return $wpdb->get_var("SELECT COUNT(*)
					FROM $votes_table
					WHERE song_id =$song_id AND 
						  chart_id = $chart_id AND 
						  period_id = $period_id");
}

function updateChartComponents($chart_id,$period_id)
{
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';
	$songs =  $wpdb->get_results("SELECT *
					FROM $chart_table
					WHERE chart_id = $chart_id AND 
						  period_id = $period_id");
	foreach ($songs as $key => $song) {
		updateAllTimeVotes($song->chart_id,$song->song_id,$song->period_id);
		updateHighestRanking($song->chart_id,$song->song_id,$song->period_id);
		updateLastRank($song->chart_id,$song->song_id,$song->period_id);
		updateNumWeeks($song->chart_id,$song->song_id,$song->period_id);
	}
	
}

function charts($params){
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';

	if(!isset($params['term']))
	{
		return array('success'=>FALSE,
					 'message'=>'No Chart Category pass');
	}
	
	$term = get_term_by( 'slug', $params['term'],'carta-chart' );
	if(!$term){
		return array('success'=>FALSE,
					 'message'=>'Invalid Chart Category');
	}
	
	$chart_id = $term->term_id;
	
	

	$lastweek = isset($_REQUEST['period'])?$_REQUEST['period']:FALSE;
	$orderby = isset($_REQUEST['orderby'])?$_REQUEST['orderby']:'ranking';
	$sort = isset($_REQUEST['sort'])?$_REQUEST['sort']:'ASC'; //ASC DESC



	//$page = isset($_REQUEST['page'])?$_REQUEST['page']:1;
	$orderby = $orderby=='title'?'post_title':$orderby;
	$per_page = isset($_REQUEST['limit'])?$_REQUEST['limit']:40;
	$limit = " LIMIT $per_page";
  	//$offset = ' OFFSET ' . ( $page - 1 ) * $per_page;
  	$week = date('W');
  	$year = date('Y');
  	$period_id = absint( $week.$year );

  	if($lastweek){
  		  	$lastPeriod = absint($week)-1;
  			$lastPeriod = $lastPeriod.$year;
  			$period_id = absint($lastPeriod);
  			if($week == 1){
  				$last_year = $year-1;
  				$lastPeriod = getIsoWeeksInYear($last_year).$last_year;
  				$period_id = absint( $lastPeriod.$last_year);
  			}
  	}
  
  	
  	if(!$chart_id)
  		return array('success'=>FALSE,
  					 'message'=>'No Chart parameter');
  	if(!$period_id)
  		return array('success'=>FALSE,
  					 'message'=>'No Period paramenter');

  	$charts = $wpdb->get_results("
  						SELECT * FROM $chart_table
  							INNER JOIN $wpdb->posts ON( $wpdb->posts.ID = $chart_table.song_id) 
  						WHERE chart_id = $chart_id
  							AND period_id =$period_id
  						ORDER BY $orderby $sort
  						$limit  
  		");
  	if(count($charts)>0)
  	{
  		$returnValues['header'] = array(
							'type'=> 'chart',
							'records'=>count($charts),
							'success'=>true,
							'week'=>$lastweek?'last':'current',
						    'sort_by'=>$orderby,
						    'order'=>$sort,
						    'period'=>$period_id);
  	}
  	else
  	{
  		return array(
							'type'=> 'chart',
							'records'=>0,
							'success'=>false,
							'week'=>$lastweek?'last':'current',
							'period'=>$period_id,
							'query'=>$wpdb->last_query);
  	}
  	foreach($charts as $key=>$val){
  		$id = $val->song_id;
  		$the_post = get_post($id);
  		$values[] = array(
  						'song_id'=>$id,
						'post_date'=>humanDate($the_post->post_date),
						'song_cover'=>get_post_meta($id,'wpcf-song-cover',TRUE),
						'artist'=>songArtist($id),
						'youtube_url'=>get_post_meta($id,'wpcf-youtube-url',TRUE),
						'title'=>$the_post->post_title,
						'link'=>get_the_permalink( $id ),
						'vote_link'=>$lastweek?null:get_rest_url()."boomlabs/v2/vote?song=$id&chart=$val->chart_id&period=$val->period_id",
						'dj_vote'=>$val->dj_vote,
						'votes'=>$val->votes,
						'total_votes'=>$val->total_votes,
						'ranking'=>$val->ranking,
						'num_weeks'=>$val->num_weeks,
						'highest_ranking'=>$val->highest_ranking,
						'all_time_votes'=>getAllTimevotes($id,$chart_id),
						'last_ranking'=>$val->last_ranking,
						
  					);
  	}

  	$returnValues['result_sets']= $values;
  	return $returnValues;
	
	
}
function humanDate($date){
	$theDate = new \DateTime($date, new DateTimeZone('Asia/Kuala_Lumpur'));
	return $theDate->format('d F, Y');
}

function songArtist($id){
	$artists = get_field('artistsongs',$id);
	if($artists){
		foreach ($artists as $artist) {
			return $artist->post_title;
		}
	}
}
function getNumWeeks($song_id,$chart_id){
	global $wpdb;
	$table_name = $wpdb->prefix.'_boom_chart';
	 return $wpdb->get_var("
			SELECT COUNT(*)
			FROM $table_name
			WHERE song_id = $song_id
				AND chart_id = $chart_id
		"); 
}

function getHighestPosition($song_id,$chart_id){
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';
	$highest = $wpdb->get_results("
				SELECT ranking
				FROM $chart_table
				WHERE song_id = $song_id AND 
				      chart_id = $chart_id
				ORDER BY ranking ASC
				LIMIT 1
			",ARRAY_A);
	foreach($highest as $rank){
		return $rank['ranking'];
	}

}

function djVotes(){
	global $wpdb;
	$chart_table = $wpdb->prefix . '_boom_chart';
	$id=$_REQUEST['id'];
    $value=$_REQUEST['value'];
    $song_id=$_REQUEST['song_id'];
    $chart_id=$_REQUEST['chart_id'];
    $period_id=$_REQUEST['period_id'];
   
   	$wpdb->update(
			$chart_table,
			array('dj_vote'=>$value,
				  'total_votes'=>absint( $value)+getSongTotalVotes($song_id,$chart_id,$period_id),
				  'all_time_votes'=>getAllTimevotes($song_id,$chart_id)),
			array('id'=>$id),
			array('%d','%d','%d')
	);
	rankSongsOnChart($chart_id,$period_id);
	updateChartComponents($chart_id,$period_id);
 

}
function ajaxRank2(){
	$chart_id = $_POST['chart'];
	$week_id = $_POST['week'];
	$year_id = $_POST['year'];
	global $wpdb;
	//return json_encode(array("chart"=>$_POST['carta'],'year'=>$_POST['year'],'week'=>$_POST['week']));
	$chart_table = $wpdb->prefix . '_boom_chart';
	// just rank on whats the chart table;
	$records = $wpdb->get_results(
			"SELECT * FROM $chart_table
			WHERE chart_id LIKE '$chart_id'
				AND week_id = $week_id
				AND year_id =$year_id 
			ORDER BY total_votes,song_id DESC"
	);
	// return json_encode($records);
	array_multisort(array_column($records, 'total_votes'),  SORT_DESC,
                array_column($records, 'song_id'), SORT_DESC,
                $records);
	// return json_encode(array('id'=>array_column($records, 'ranking'),'total_votes'=>array_column($records, 'total_votes'))); 
	$ranking = 1;
	// foreach($records as $rec){
	// 	//$val[$i]['ranking'] = $ranking;
	// 	//$ranking = $ranking + 1;
	// 	$data[] = array('total_votes'=>$rec->total_votes,'ranking'=>$rec->ranking);
	// }
	// return json_encode($data);
	foreach($records as $val){
		//$val->ranking = $ranking;
		//if(isExistOnChart($val->song_id,$carta,$val->year_id,$val->week_id)){
		$success =  $wpdb->update( 
							$chart_table, 
							array('ranking'=>$ranking),
							array('song_id'=>$val->song_id,
								  'chart_slug'=>$carta,
								  'year_id'=>$val->year_id,
								  'week_id'=>$val->week_id),
							array('%d','%d','%d','%d','%s','%d','%d','%d')
						);
		//}
		//$data[] = array($val->total_votes=>$val->ranking);
		$ranking = $ranking + 1;
		//if(!$success) return json_encode($wpdb->last_error); 
	}
}


if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}
class Song_List extends WP_List_Table {
	/** Class constructor */
	public function __construct() {
		parent::__construct( [
			'singular' => __( 'Song', 'sp' ), //singular name of the listed records
			'plural'   => __( 'Songs', 'sp' ), //plural name of the listed records
			'ajax'     => true //should this table support ajax?

		] );
	}
	
	public static function get_songs( $per_page = 5, $page_number = 1 ) 
	{
	  	global $wpdb;
		$table_name = $wpdb->prefix.'_boom_chart';
	  	$sql = "SELECT * FROM {$table_name} ";
	  	$where = '';
	  	
	  	if(! empty($_REQUEST['chart']) || !empty($_REQUEST['period']) )
	  	{
	  		$where .= " WHERE ";
	  		
	  		if(!empty($_REQUEST['chart']))
	  		{
	  			$where .= " chart_id = {$_REQUEST['chart']} ";
	  		}

	  		if(!empty($_REQUEST['period']))
	  		{
		  		$where .= !empty($_REQUEST['chart'])?' AND ':'';
		  		$where .= " period_id = {$_REQUEST['period']}";
	  		}
	  	}


	  
		if(!empty($where))
		{ 	
			$sql .= $where;
		}

		if ( ! empty( $_REQUEST['orderby'] ) ) 
		{
		    $sql .= ' ORDER BY ' . esc_sql( $_REQUEST['orderby'] );
		    $sql .= ! empty( $_REQUEST['order'] ) ? ' ' . esc_sql( $_REQUEST['order'] ) : ' DESC';
		}

	  	$sql .= " LIMIT $per_page";

	  	$sql .= ' OFFSET ' . ( $page_number - 1 ) * $per_page;


	  	$result = $wpdb->get_results( $sql, 'ARRAY_A' );

	  	return $result;
	}

	public static function record_count() 
	{
  		global $wpdb;
  		$table_name = $wpdb->prefix.'_boom_chart';
  
  		$sql = "SELECT COUNT(*) FROM {$table_name}";
	 	$where = '';
		if(! empty($_REQUEST['chart']) || !empty($_REQUEST['period']) )
		{
			$where .= " WHERE ";
			if(!empty($_REQUEST['chart']))
			{
				$where .= " chart_id = {$_REQUEST['chart']} ";
			} 
			if(!empty($_REQUEST['period']))
			{
				$where .= !empty($_REQUEST['chart'])?' AND ':'';
				$where .= " period_id = {$_REQUEST['period']}";
			}
		}
	  	if(!empty($where)) $sql .=$where;
	  	
	  	return $wpdb->get_var( $sql );
	}

	public function no_items()
	{
		_e( "No Songs available", 'sp' );
	}

	function column_name( $item ) {

	  // create a nonce
	  //$delete_nonce = wp_create_nonce( 'sp_delete_customer' );

	  $title = '<strong>' . $item['name'] . '</strong>';

	  // $actions = [
	  //   'delete' => sprintf( '<a href="?page=%s&action=%s&customer=%s&_wpnonce=%s">Delete</a>', esc_attr( $_REQUEST['page'] ), 'delete', absint( $item['ID'] ), $delete_nonce )
	  // ];

	  // return $title . $this->row_actions( $actions );
	  return $title;
	}

	public function column_default( $item, $column_name ) 
	{
		$the_post = get_post($item['song_id']);
		$current_period_id = date('W').date('Y');
		switch ( $column_name ) {
	    case 'votes':
	    case 'total_votes':
	    case 'ranking':
	    case 'num_weeks':
	    case 'last_ranking':
	    case 'highest_ranking':
	    // case 'all_time_votes':
			return $item[ $column_name ];
		case 'period_id':
				return $this->getNicePeriod($item[ $column_name ]);
		case 'chart_id':
			   return $this->getNiceChart($item[ $column_name ]);
		case 'title':
			  //wp-admin/post.php?post=1497&action=edit
			  return "<a href='".get_edit_post_link($the_post->ID)."'>".$the_post->post_title.'</a>';
		
		case 'dj_vote':
			if($current_period_id != $item['period_id'])
			{
				return "<input readonly value='".$item[ $column_name]."' class='dj_votes' placeholder='0'  />";
			}
			else{
					return $this->renderDJVotes($item['id'],$the_post->ID,$item[ 'chart_id' ],$item['period_id'], $item[ $column_name],$the_post->post_title,
					$this->getNicePeriod($item[ 'period_id' ]),
					$this->getNiceChart($item[ 'chart_id' ]));
			}
			break;	
			
		case "song_cover":
			$img_url = get_post_meta($the_post->ID,"wpcf-song-cover",true);
			return "<img src='$img_url' alt='$the_post->post_title' class='post-thumbnail' width='80' height='80'>";
		case 'vote':
				if($current_period_id != $item['period_id'])
				{
					return '';
				}
				else
				{
					return
					"<a target='_blank' href='". get_rest_url() ."boomlabs/v2/vote?song=".$item['song_id']."&chart=".$item['chart_id']."&period=".$item['period_id']."'><i class='fa fa-thumbs-up' aria-hidden='true'></i></a>";
				}
		default:
		    print_r( $item, true ); //Show the whole array for troubleshooting purposes
		    break;
		}
	}
	public function renderDJVotes($id,$song_id,$chart_id,$period_id,$value,$title,$niceperiod,$nicechart){
		//$id = "song-$chart_id-$period_id";
		return "<input id='$id' readonly value='$value' class='dj_votes' placeholder='0'  />
		<a class='add-dj-votes' data-id='$id' data-song='$song_id' data-chart='$chart_id' data-period='$period_id' data-value='$value' data-title='$title' data-nice-period='$niceperiod' data-nice-chart='$nicechart'><i class='fa fa-pencil fa-lg dimmer' aria-hidden='true'></i></a>
		";

	}
	
	public function numWeeks($id,$carta_id){
		global $wpdb;
		$table_name = $wpdb->prefix.'_boom_chart';
		 return $wpdb->get_var("
				SELECT COUNT(*)
				FROM $table_name
				WHERE song_id = $id
					AND carta_id = $carta_id
			"); 
	}

	public function get_columns() {
	  $columns = [
	    'title'    => __( 'Title', 'sp' ),
	    // 'vote'    => __( 'V', 'sp' ),
	    'song_cover'=>__( 'Cover', 'sp' ),
	    'chart_id' => __( 'Chart', 'sp' ),
	    'period_id'=> __( 'Period', 'sp' ),
	    'votes'    => __( 'Votes', 'sp' ),
	    'dj_vote' => __( 'DJ Vote', 'sp' ),
	    'total_votes' => __( 'Total', 'sp' ),
	    'ranking'    => __( 'Rank', 'sp' ),
	    'num_weeks'=> __( 'Weeks', 'sp' ),
	    'last_ranking'=> __( 'Last', 'sp' ),
	    'highest_ranking'=> __( 'Highest', 'sp' ),
	    // 'all_time_votes'=> __( 'All Time', 'sp' ),
	  ];

	  return $columns;
	}
	public function get_sortable_columns() {
	  $sortable_columns = array(
	    'ranking' => array( 'ranking', true ),
	    'h_votes' => array( 'h_votes', FALSE ),
	  );

	  return $sortable_columns;
	}

	public function prepare_items() {

	  $_SERVER['REQUEST_URI'] = remove_query_arg( '_wp_http_referer', $_SERVER['REQUEST_URI'] );
	  $_SERVER['REQUEST_URI'] = remove_query_arg( '_wpnonce', $_SERVER['REQUEST_URI'] );
	  $this->_column_headers = $this->get_column_info();

	  /** Process bulk action */
	  $this->process_bulk_action();

	  $per_page     = $this->get_items_per_page( 'songs_per_page', 12 );
	  $current_page = $this->get_pagenum();
	  $total_items  = self::record_count();

	  $this->set_pagination_args( [
	    'total_items' => $total_items, //WE have to calculate the total number of items
	    'per_page'    => $per_page //WE have to determine how many items to show on a page
	  ] );


	  $this->items = self::get_songs( $per_page, $current_page );
	}
	function getNiceChart($chart_id)
	{
		
		$term = get_term($chart_id,'carta-chart');
		return $term?$term->slug:FALSE;
	}
	function getNicePeriod($period_id)
	{
		$week = substr($period_id, 0,strlen($period_id)<6?1:2 );
		$year = substr($period_id,strlen($period_id)<6?1:2 );
		return "W:$week - Y:$year";
	}
	function extra_tablenav( $which ) {
		$_SERVER['REQUEST_URI'] = remove_query_arg( '_wp_http_referer', $_SERVER['REQUEST_URI'] );
	  $_SERVER['REQUEST_URI'] = remove_query_arg( '_wpnonce', $_SERVER['REQUEST_URI'] );
		global $wpdb;
		$table_chart = $wpdb->prefix.'_boom_chart';
		$period = isset( $_REQUEST['period'] )?$_REQUEST['period']:'';
        $chart = isset( $_REQUEST['chart'] )?$_REQUEST['chart']:'';
	   if ( $which == "top" ){
	      //The code that goes before the table is here
	      
	    $charts = $wpdb->get_results("SELECT DISTINCT(chart_id) 
	    								FROM $table_chart
	    								ORDER BY chart_id ASC");
	    $periods = $wpdb->get_results( "SELECT DISTINCT(period_id) 
	    								FROM $table_chart
	    								 ORDER BY period_id DESC")  ;
	      ?>
	      <div class="alignrleft actions bulkactions">
	      	 <select id='chart' name="chart" class="ewc-filter-cat">
	      	 	<option value="">Filter by Chart</option>
	      <?php 
	      		foreach($charts as $chart){
	      			if($this->getNiceChart($chart->chart_id) !== FALSE){	
		      			if(isset($_REQUEST['chart']) && $chart->chart_id == $_REQUEST['chart']):?>
							<option value="<?php echo $chart->chart_id;?> " selected><?php echo $this->getNiceChart($chart->chart_id)?></option>
			      		<?php else:?>
							<option value="<?php echo $chart->chart_id;?>"><?php echo $this->getNiceChart($chart->chart_id)?></option>
			      		<?php endif;?> 
		      			
		      			<?php
	      			}
	      		}
	      		?>
	      	</select>
	      	
	      	 <select id='period' name="period" class="ewc-filter-cat">
	      	 	<option value="">Filter by Period</option>
	      		
	      		<?php 
	      		foreach($periods as $period){
	      			if( isset($_REQUEST['period']) && $period->period_id == $_REQUEST['period'] ) :?>
		      			<option value="<?php echo $period->period_id;?>" selected><?php echo $this->getNicePeriod($period->period_id);?> </option>
	      			<?php else:?>
						<option value="<?php echo $period->period_id;?>"><?php echo $this->getNicePeriod($period->period_id)?> </option>
	      			<?php endif;?>
	      			<?php  
	      		}
	      		?>
	      	</select>
	      	
	      	<input type="submit" name="filter_action" id="post-query-submit" class="button" value="Filter" />
	      </div>
	      	<?php

	   }
	}
}

class SP_Plugin {

	// class instance
	static $instance;

	// customer WP_List_Table object
	public $songs_object;

	// class constructor
	public function __construct() {
		add_filter( 'set-screen-option', [ __CLASS__, 'set_screen' ], 10, 3 );
		add_action( 'admin_menu', [ $this, 'plugin_menu' ] );
	}
	public static function set_screen( $status, $option, $value ) {
			return $value;
	}

	public function plugin_menu() {

		$hook = add_menu_page(
			'Chart Results',
			'Chart Results',
			'manage_options',
			'charts-results',
			[ $this, 'plugin_settings_page' ]
		);

		add_action( "load-$hook", [ $this, 'screen_option' ] );

	}
	public function screen_option() {

		$option = 'per_page';
		$args   = [
			'label'   => 'Chart Results',
			'default' => 5,
			'option'  => 'songs_per_page'
		];

		add_screen_option( $option, $args );

		$this->songs_object = new Song_List();
	}
	public function plugin_settings_page() {
		?>
		<div class="wrap">
			<h2>Chart Results</h2>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder">
					<div id="post-body-content">
						<div class="meta-box-sortables ui-sortable">
							 <form id="song-filter" method="GET">
							 	<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
								<?php
								$this->songs_object->prepare_items();
								$this->songs_object->display(); ?>
							</form>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
	<?php
	}
	public static function get_instance() {
	if ( ! isset( self::$instance ) ) {
		self::$instance = new self();
	}
	return self::$instance;
	}
}

add_action( 'plugins_loaded', function () {
	SP_Plugin::get_instance();
} );

add_action( 'admin_enqueue_scripts', 'load_custom_wp_admin_script_style');
function load_custom_wp_admin_script_style($hook){
	if($hook != 'toplevel_page_charts-results') return;
	wp_enqueue_script('chart-ajax', plugins_url('js/chart-ajax.js',__FILE__), array('jquery'),'2.0',TRUE);
	wp_enqueue_script('modal-js', 'https://cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.1/jquery.modal.min.js', array('jquery'),'2.0',TRUE);  
	wp_enqueue_style( 'modal-style','https://cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.1/jquery.modal.min.css',__FILE__); 	
	wp_enqueue_style( 'chart-style',plugins_url('css/plug-style.css',__FILE__)); 	
	

}

add_action('admin_footer', 'my_admin_footer_function', 100);
function my_admin_footer_function() {
	$screen = get_current_screen();
	if($screen->id != 'toplevel_page_charts-results') return;
	?>
	<aside id="dj-votes-popup" class="modal">
			
			<h2>Manual DJ Votes</h2>

			<label id='title'></label>
			<br>
			<label id='period'></label>
			<br>
			<label id='chart'></label>
			<br>
			Votes: <input type="number" id='dj_votes' name="dj_votes">
			<br>
			<button id='change-me'>Save</button>
		</aside>
	<?php 
}

function testdata(){
	global $wpdb;
	$chart_table = $wpdb->prefix.'_boom_chart';
	$votes_table = $wpdb->prefix.'_boom_votes';

	$period_id = 452017;
	$currents = $wpdb->get_results("
		SELECT * FROM {$chart_table}
		");
	foreach ($currents as $current) {
			$wpdb->update(
				$chart_table,
				array('period_id'=>$period_id),
				array('id'=>$current->id,
			          'song_id'=>$current->song_id),
				array('%d')
					);
			
	}
	$votes = $wpdb->get_results("
		SELECT * FROM {$votes_table}
		");
	foreach ($votes as $current) {
		$wpdb->update(
				$votes_table,
				array('period_id'=>$period_id),
				array('id'=>$current->id,
			          'song_id'=>$current->song_id),
				array('%d')
					);
	}
	

}


// add_filter( 'wp_insert_post_data', 'set_post_to_draft', 99, 2 );

// function set_post_to_draft( $data, $postarr ) {
//   if (get_post_type()=='song' && !is_super_admin()) {
//     $data['post_status'] = 'draft';
//   }
//   return $data;
// }

// function show_only_your_posts($query) {
//     global $pagenow;
 
//     if( 'edit.php' != $pagenow || !$query->is_admin )
//         return $query;
 
//     if( !current_user_can( 'edit_others_posts' ) ) {
//         global $user_ID;
//         $query->set('author', $user_ID );
//     }
//     return $query;
// }
// add_filter('pre_get_posts', 'show_only_your_posts');