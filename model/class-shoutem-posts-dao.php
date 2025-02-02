<?php


/*
  Copyright 2011 by ShoutEm, Inc. (www.shoutem.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class ShoutemPostsDao extends ShoutemDao {

	public function __construct() {
		parent::__construct();

		remove_filter('shoutem_shortcode_wrapper', 'shoutem_shortcode_wrapper_filter', 10);
		add_filter('shoutem_shortcode_wrapper', 'shoutem_shortcode_wrapper_filter', 10, 3);

		remove_filter('img_caption_shortcode', 'shoutem_img_caption_shortcode_filter', 10);
		add_filter('img_caption_shortcode', 'shoutem_img_caption_shortcode_filter', 10, 3);
	}

	public function get($params) {
		global $post;
		$wp_post = get_post($params['post_id']);
		if ($wp_post == null) {
			throw new ShoutemApiException('invalid_params');
		}
		setup_postdata($wp_post);
		$this->attach_external_plugin_integration();
		$post = $this->get_post($wp_post, $params);
		return $post;
	}

	public function pages($params) {
		$offset = $params['offset'];
		$limit = $params['limit'];

		$args = array (
			'numberposts' => $limit +1,
			'offset' => $offset,

		);
		$pages = get_pages($args);

		if ($pages == null) {
			throw new ShoutemApiException('invalid_params');
		}
		$results = array ();

		foreach ($pages as $page) {
			setup_postdata($page);
			$results[] = $this->get_post($page, $params);
		}
		return $this->add_paging_info($results, $params);
	}

	public function categories($params) {
		$offset = $params['offset'];
		$limit = $params['limit'];

		$results = array ();

		if ($offset == 0) {
			//add fictive category all
			$blog_name = get_bloginfo('name');
			$limit = $limit -1;
			$results[] = array (
				'category_id' => -1,
				'name' => $blog_name,
				'allowed' => true
			);
		} else {
			//compensate offset for fictive category all
			$offset -= 1;
		}

		$category_args = array (
			'number' => $offset + $limit +1
		);

		$categories = get_categories();
		//because there is no offset in get_categories();
		$categories = array_slice($categories, $offset, $limit +1);

		foreach ($categories as $category) {
			$remaped_category = $this->array_remap_keys($category, array (
				'cat_ID' => 'category_id',
				'name' => 'name'
			));
			$remaped_category['allowed'] = true;
			$results[] = $remaped_category;
		}

		return $this->add_paging_info($results, $params);

	}

	public function find($params) {
		global $post;
		$offset = $params['offset'];
		$limit = $params['limit'];

		$post_args = array (
			'showposts' => $limit +1,
			'offset' => $offset,
			'orderby' => 'date',
			'order' => 'DESC',
			'post_type' => 'post',
			'post_status' => 'publish'
		);
		if (isset ($params['category_id']) && '-1' != $params['category_id']) { //cetogory_id = -1 when fitive category all post is searched
			$post_args['category__in'] = array($params['category_id']);
		}

		if (isset($params['exclude_categories'])) {
			$excluded_categories = explode(',',$params['exclude_categories']);
			$post_args['category__not_in'] = $excluded_categories;
		}
		if (isset($params['meta_key'])){
			$post_args['meta_key']=$params['meta_key'];
		}
		$query = new WP_Query($post_args);
		$remaped_posts = array ();
		$this->attach_external_plugin_integration();

		while($query->have_posts()) {
			$query->next_post();
			$post = $query->post;
			
			setup_postdata($post);
			$remaped_posts[] = $this->get_post($post, $params);
		}
		$paged_posts = $this->add_paging_info($remaped_posts, $params);
		return $paged_posts;
	}

	/**
	 * Attaches the external plugin integration daos to the post generating process,
	 * For example:
	 * @see ShoutemNGGDao
	 */
	private function attach_external_plugin_integration() {
		$daos = ShoutemStandardDaoFactory :: instance()->get_external_plugin_integration_daos();
		foreach ($daos as $dao) {
			$dao->attach_to_hooks();
		}
	}

	private function get_post($post, $params) {
		$attachments = array (
			'images' => array (),
			'videos' => array (),
			'audio' => array ()
		);
		$this->attachments = & $attachments;

		$is_user_logged_in = isset ($params['session_id']);
		$include_raw_post = isset ($params['include_raw_post']);
		$is_reqistration_required = ('1' == get_option('comment_registration'));
		$remaped_post = $this->array_remap_keys($post, array (
			'ID' => 'post_id',
			'post_date_gmt' => 'published_at',
			'post_title' => 'title',
			'post_excerpt' => 'summary',
			'post_content' => 'body',
			'comment_status' => 'commentable',
			'comment_count' => 'comments_count',

		));

		$post_categories = wp_get_post_categories($remaped_post['post_id']);
		$categories = array ();
		$tags = array ();
		foreach ($post_categories as $category) {
			$cat = get_category($category);
			$categories[] = array (
				'id' => $cat->cat_ID,
				'name' => $cat->name
			);
		}
		$remaped_post['categories'] = $categories;

		//*** ACTION  shoutem_get_post_start ***//
		//Integration with external plugins will usually hook to this action to
		//substitute shortcodes or generate appropriate attachments from the content.
		//For example: @see ShoutemNGGDao, @see ShoutemFlaGalleryDao.
		do_action('shoutem_get_post_start', array (
			'wp_post' => $post,
			'attachments_ref' => & $attachments
		));
		
		$body = $remaped_post['body'];
		if ($include_raw_post) {
			$remaped_post['raw_post'] = $body;
		}

		// strip script/style tags before wpautop filter has a chance to break them
		$body = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $body);

		//we have to trigger filters before the_content/shortcodes handlers are fired
		$body = apply_filters('shoutem_twitter', $body);
		$body = apply_filters('shoutem_instagram', $body);
		$body = apply_filters('shoutem_brightcove_link', $body);

		$body = apply_filters('the_content', do_shortcode($body));

		$body = apply_filters('shoutem_shortcode_wrapper', $body, '#SMG_PhotoGallery', 'shoutemsmgallery');
		$body = apply_filters('shoutem_shortcode_wrapper', $body, '.embed-twitter', 'shoutemtwitterembed');
		$body = apply_filters('shoutem_shortcode_wrapper', $body, '.brightcove-embed', 'shoutembrightcoveembed');
		$body = apply_filters('shoutem_shortcode_wrapper', $body, '#simple-brightcove-', 'shoutembrightcovesimple');
		$body = apply_filters('shoutem_shortcode_wrapper', $body, '#wpcom-iframe-form-', 'shoutemwpcomwidgetembed');

		//lets override default wp instagram embed
		//note to myself: shoutem_instagram_handler and shoutem_twitter_handler are located in class-shoutem-embedoverrides-dao.php
		
		$body = do_shortcode($body);

		$striped_attachments = array ();
		$remaped_post['body'] = sanitize_html($body, $striped_attachments);
		$remaped_post['body'] = preg_replace('/<\/attachment>(<br>)+<p class=\"image-caption\">/si','</attachment><p class="image-caption">', $remaped_post['body']);

		$user_data = get_userdata($post->post_author);

		if (function_exists( 'get_coauthors')){ //if coauthors exist, fetch only first coauthor
			$coauthors = get_coauthors($post->ID);
			$remaped_post['author'] = $coauthors ? $coauthors[0]->display_name : $user_data->display_name;
		}
		else{
			$remaped_post['author'] = $user_data->display_name;
		}

		$remaped_post['likeable'] = 0;
		$remaped_post['likes_count'] = 0;
		$remaped_post['link'] = get_permalink($remaped_post['post_id']);

		$this->include_leading_image_in_attachments($attachments, $post->ID);

		$attachments['images'] = array_merge($attachments['images'], $striped_attachments['images']);
		$attachments['videos'] = array_merge($attachments['videos'], $striped_attachments['videos']);
		$attachments['audio'] = array_merge($attachments['audio'], $striped_attachments['audio']);
		sanitize_attachments($attachments);
		$remaped_post['attachments'] = $attachments;
		$remaped_post['image_url'] = '';

		$images = $attachments['images'];
		if (count($images) > 0) {
			$remaped_post['image_url'] = $images[0]['src'];
		}

		$post_commentable = ($remaped_post['commentable'] == 'open');

		if (!$this->options['enable_wp_commentable']) {
			$remaped_post['commentable'] = 'no';
		} else
			if (array_key_exists('commentable', $params)) {
				$remaped_post['commentable'] = $params['commentable'];
			} else {
				$remaped_post['commentable'] = $this->get_commentable($post_commentable, $is_user_logged_in, $is_reqistration_required);
			}

		if ($this->options['enable_fb_commentable']) {
			$remaped_post['fb_commentable'] = 'yes';
		}

		if (!$remaped_post['summary']) {
			$remaped_post['summary'] = wp_trim_excerpt(apply_filters('the_excerpt', get_the_excerpt()));
			$remaped_post['summary'] = html_to_text($remaped_post['summary']);
		}

		$remaped_post['title'] = html_to_text($remaped_post['title']);

		$remaped_posts[] = $remaped_post;
		return $remaped_post;
	}

	private function get_commentable($post_commentable, $is_user_logged_in, $is_reqistration_required) {
		//post is not commentable
		if ($post_commentable == false) {
			return 'no';
		}

		//post is commentable, user is logged in
		if ($is_user_logged_in) {
			return 'yes';
		}

		//post is commentable, user not logged in
		if ($is_reqistration_required) {
			return 'denied';
		}

		//post is commentable, user not logged in, anonymous comments are enabled
		return 'yes';

	}

}

function shoutem_img_caption_shortcode_filter($empty, $attr, $content) {
	if (!isset($_GET['shoutemapi'])) {
		return '';
	}

	if (empty($attr['caption'])) {
		return '<figure>'.$content.'</figure>';
	}

	$content = '<figure>'.$content.'<figcaption class="image-caption">'.$attr['caption'].'</figcaption>'.'</figure>';

	$dom = shoutem_html_load($content);
	foreach ($dom->getElementsByTagName('img') as $img) {
		$img->setAttribute('caption', $attr['caption']);
		if (!empty($attr['width'])) {
			$img->setAttribute('width', $attr['width']);
		}
	}
	return shoutem_html_save($dom);
}

function shoutem_shortcode_wrapper_filter($content, $css_id_or_class, $shortcode) {
	if (!isset($_GET['shoutemapi'])) {
		return $content;
	}

	$id = '';
	$class = '';
	if (strpos($css_id_or_class, "#") === 0) {
		$id = substr($css_id_or_class, 1);
	}

	if (strpos($css_id_or_class, ".") === 0) {
		$class = substr($css_id_or_class, 1);
	}

	if (!$id && !$class) {
		return $content;
	}

	$dom = shoutem_html_load($content);

	$nodes_to_wrap = array();
	$xpath = new DOMXPath($dom);
	if ($id) {
		$node = $xpath->query("//*[@id='".$id."']")->item(0);
		if ($node) {
			$nodes_to_wrap[] = $node;
		}
		else {
			$nodes_to_wrap = $xpath->query("//*[contains(@id,'".$id."')]");
		}
	} else if ($class) {
		$nodes_to_wrap = $xpath->query("//*[contains(concat(' ', @class, ' '),' ".$class." ')]");
	}

	foreach ($nodes_to_wrap as $node_to_wrap) {
		$shortcode_fragment = $dom->createDocumentFragment();
		$shortcode_fragment->appendChild(new DOMText('['.$shortcode.']'));
		$shortcode_fragment->appendChild($node_to_wrap->cloneNode(true));
		$shortcode_fragment->appendChild(new DOMText('[/'.$shortcode.']'));
		$node_to_wrap->parentNode->replaceChild($shortcode_fragment, $node_to_wrap);
	}

	return shoutem_html_save($dom);
}

function shoutem_html_load($html) {
	if (function_exists('mb_encode_numericentity')) {
	    $html = mb_encode_numericentity($html, array (0x80, 0xffff, 0, 0xffff), 'UTF-8');
	}
	$dom = new DOMDocument();
	// supress warnings caused by HTML5 tags
	@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
	return $dom;
}

function shoutem_html_save($dom) {
	$html = substr(dao_util_save_html_node($dom->getElementsByTagName('body')->item(0)), 6, -7);
	if (function_exists('mb_decode_numericentity')) {
	    $html = mb_decode_numericentity($html, array (0x80, 0xffff, 0, 0xffff), 'UTF-8');
	}
	return $html;
}

?>