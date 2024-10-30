<?php
/**
 * This class is designed to work with protected iframe embed markup for Wordpress.VIP embedding (funnyordie, facebook images/videos, something else in the future ... ) wrapped in our own shortcode
 * Also contains basic parser for Visual Composer plugin
 */

require_once "dao-util.php";

class ShoutemProtectedIframeEmbedDao extends ShoutemDao {
	
	function attach_to_hooks() {
		$this->attach_to_shortcodes();
	}
	
	public function attach_to_shortcodes() {
		remove_shortcode( 'protected-iframe');
		add_shortcode( 'protected-iframe', array(&$this, 'shortcode_protectediframe' ) );
		remove_shortcode( 'vc_row');
		add_shortcode( 'vc_row', array(&$this, 'shortcode_shoutem_vc_row' ) );		
		remove_shortcode( 'vc_column');
		add_shortcode( 'vc_column', array(&$this, 'shortcode_shoutem_vc_column' ) );	
		remove_shortcode( 'vc_column_text');
		add_shortcode( 'vc_column_text', array(&$this, 'shortcode_shoutem_vc_column_text' ) );		
		remove_shortcode( 'vc_video');
		add_shortcode( 'vc_video', array(&$this, 'shortcode_shoutem_vc_video' ) );
	}

	/**
	 * shoutem protected iframe embed shortcode
	 */
	function shortcode_protectediframe($atts, $content) {
		//for now, we only need 'info' shortcode attribute
	$atts = shortcode_atts(array(
		'info' => '' 
	), $atts );
	
	//funny or die.com videos
	
	if ($atts['info'] != '' && strpos($atts['info'],'funnyordie.com/embed/') !== false) { //this should be reliable enough for funnyordievideos

			$video_url = htmlspecialchars_decode($atts['info']);
			if(!preg_match("/(http:\/\/|https:\/\/)/i",$video_url,$matches)){ //if uri doesn't contain http or https, only //, prepend protocol
				$video_url = 'http:'.$video_url;
			}
			if (function_exists("wpcom_vip_file_get_contents")) {
				$html = wpcom_vip_file_get_contents($video_url);
			}
			else {
				$html = file_get_contents($video_url);
			}
			if(!$html) return ''; //unable to retrieve response from funnyordie embed site
			
			if(preg_match_all("/<source src=\"\/\/(.*)\" type='video\/mp4'>/si",$html,$matches) > 0) {
				$mp4_video='http://'.$matches[1][0];
			}
			
			//TODO: check do we support webm videos as failover for mp4 (funnyordie provides mp4 and webm videos)
			
			if(preg_match_all("/<img.*?alt=\"Watch Video\".*?src=\"\/\/(.*)\".*\/>/i",$html,$matches) > 0) {
				$thumbnail_url='http://'.$matches[1][0];
			}
				
			return "<iframe provider=\"mp4video\" src=\"".esc_url($mp4_video)."\" thumbnail_url=\"".esc_url($thumbnail_url)."\"></iframe>";
		

	}
	
	//facebook image / video posts failover
	
	if ($atts['info'] != '' && (strpos($atts['info'],'facebook.com/plugins/post.php') !== false || strpos($atts['info'],'facebook.com/plugins/video.php') !== false)) { //this should be reliable enough for facebook images and videos
			
			$facebook_embed_url = htmlspecialchars_decode($atts['info']);
			$parsed_url = parse_url($facebook_embed_url, PHP_URL_QUERY);
			
			if (!$parsed_url) return ''; //unable to parse params from given URL
			
			parse_str($parsed_url, $part);
			$facebook_post_url=$part['href'];
			
			if (!$facebook_post_url) return ''; //unable to find direct link to post
			
			$facebook_post_url = htmlspecialchars_decode($facebook_post_url);
			return "<a href=\"".esc_url($facebook_post_url)."\">View content on Facebook.com</a>";

	}

	return "(Sorry, unsupported content, see it <a href=\"".esc_url($atts['info'])."\">here</a>)"; //return this if we have received unsupported protected-iframe shortcode (like some weird widget or unsupported video)

	}

	/**
	 * shoutem visual composer basic support
	 */
	function shortcode_shoutem_vc_row($atts, $content) {
		//remove all [shortcode_vc_row] elements and return content within (column)
		return $content;
	}
	
	function shortcode_shoutem_vc_column($atts, $content) {
		//then remove all [vc_column] elements and return content (column_text or vc_video) within paragraph
		return '<p>'.$content.'</p>';
	}

	function shortcode_shoutem_vc_column_text($atts, $content) {
		//then encapsulate all [vc_column_text] elements in paragraphs from above
		return $content;
	}

	function shortcode_shoutem_vc_video($atts, $content) {
		$video_url = $atts['link'];
		$video_url = preg_replace('/&[^;]+;/si', '', $video_url);	
		if ($atts['link'] != '' && (strpos($atts['link'],'youtube.com') !== false || strpos($atts['link'],'youtu.be') !== false )) { //this should be reliable enough for funnyordievideos
			//if content has video in link shortcode tag, catch it and replace with string without shortcodes. also remove possible html-escaped characters
			$video_url = preg_replace('/watch\?v=/si', 'v/', $video_url);
			return "<iframe provider=\"video_url\" src=\"".esc_url($video_url)."\"></iframe>";
		}

	return "(Sorry, unsupported content, see it <a href=\"".esc_url($video_url)."\">here</a>)"; 		//return this if we have received unsupported video in vc_video shortcode

	}
} 
?>
