<?php
/**
 * This class is designed to override default instagram, twitter, brightcove embedding to be compatible with shoutem
 */

require_once "dao-util.php";

class ShoutemEmbedOverridesDao extends ShoutemPostsDao {
	
    function attach_to_hooks() {
        remove_filter('shoutem_twitter', array(&$this, 'shoutem_twitter_callback'), 10);
        add_filter('shoutem_twitter', array(&$this, 'shoutem_twitter_callback'), 10);

        remove_filter('shoutem_instagram', array(&$this, 'shoutem_instagram_callback'), 10);
       	add_filter('shoutem_instagram', array(&$this, 'shoutem_instagram_callback'), 10);

        remove_filter('shoutem_brightcove_link', array(&$this, 'shoutem_brightcove_callback'), 10);
       	add_filter('shoutem_brightcove_link', array(&$this, 'shoutem_brightcove_callback'), 10);
    }

	/**
	 * shoutem instagram embed override
	 */
	function shoutem_instagram_callback($content) {
       return preg_replace_callback('/(?:(?:http|https):\/\/)?(?:www.)?(?:instagram.com|instagr.am)\/p\/([^\s]+)/si',function($matches){
            $url = $matches[0];
            $instagram_oembed_url='https://api.instagram.com/oembed/?omitscript=true&url='.$url;
            if (function_exists("wpcom_vip_file_get_contents")) {
                $instagram_post_data = @wpcom_vip_file_get_contents($instagram_oembed_url);
            }
            else {
                $instagram_post_data = @file_get_contents($instagram_oembed_url);
            }
            
            if (!$instagram_post_data) return ''; //nothing retrieved from instagram
            
            $parsed_post_data=json_decode($instagram_post_data, true);
            
            if(!$parsed_post_data) return ''; //unable to parse json above
            
            $image_src = $parsed_post_data["thumbnail_url"];

            $image_width  = $parsed_post_data["thumbnail_width"]; 
            $image_height = $parsed_post_data["thumbnail_height"];
            $post_author = $parsed_post_data["author_name"];
            $author_url = $parsed_post_data["author_url"];
            $post_title = $parsed_post_data["title"];
            
            //lets place post title in one paragraph, and author info in another one
            $body = '<p>'.esc_html($post_title).'</p><p> A photo posted by <a href="'.esc_url($author_url).'">@'.esc_html($post_author).'</a></p>';
            
            $html = '<img src="'.esc_url($image_src).'" width="'.esc_attr($image_width).'" height="'.esc_attr($image_height).'"><blockquote>'.$body.'</blockquote>';
            return $html;

        }, $content);
    }

	/**
	 * shoutem twitter embed override
	 */
    function shoutem_twitter_callback($content){
        return preg_replace_callback('/https?:\/\/twitter\.com\/[^\/]+\/status\/\d+/si',function($matches){
            $url = $matches[0];

            $fallbackContent = "<p>Unsupported Twitter content, see it directly on <a href=\"".esc_url($url)."\">Twitter</a>";

            $oembedUrl = "https://publish.twitter.com/oembed?omit_script=true&widget_type=video&url=" . urlencode($url);

            if (function_exists("wpcom_vip_file_get_contents")) {
                @$twitter_post_raw = wpcom_vip_file_get_contents($oembedUrl, 3, 900);
            }
            else {
                @$twitter_post_raw = file_get_contents($oembedUrl);
            }
           
            if (!$twitter_post_raw) return $fallbackContent;

            $twitter_post = json_decode($twitter_post_raw, true);

            if (!$twitter_post) return $fallbackContent;

            $author = $twitter_post['author_name'];

            $dom = new DOMDocument;
            @$dom->loadHTML($twitter_post['html']);
            $content = $dom->saveHTML($dom->getElementsByTagName('p')->item(0));

            $post_text = '<b>'. $author .'</b> on Twitter: ';
    	    $post_text .= $content;
            
            $html = '<blockquote>'.$post_text.'</blockquote>';
            
            return $html;
        }, $content);
    }

	/**
	 * shoutem brightcove embed override for videos formatted like this: http://link.brightcove.com/services/player/bcpid....?bckey=...&bctid=.... 
	 */
    function shoutem_brightcove_callback($content){
        return preg_replace_callback('/https?:\/\/link.brightcove.com\/services\/player\/bcpid(\d+)\?bckey=(.*)bctid=(\d+)/si',function($matches){
            $url = $matches[0];
            $bcpid = preg_replace('/&[^;]+;/si', '', $matches[1]);
            $bckey = preg_replace('/&[^;]+;/si', '', $matches[2]);
            $bctid = preg_replace('/&[^;]+;/si', '', $matches[3]);

            if(!url || !$bcpid || !$bckey || !$bctid){
                $html = "<p>Unsupported content, see it directly <a href=\"".esc_url($url)."\">Here</a>";
            	return $html;
            }

            $url = 'http://c.brightcove.com/services/viewer/htmlFederated?&isVid=true&isUI=true';
            $url .= '&playerKey='.$bckey;
            $url .= '&'.urlencode('@videoPlayer').'='.$bctid;
            $html = '<object><embed src="'.esc_url($url).'"></embed></object>';
            return $html;
        }, $content);
    }
} 
?>
