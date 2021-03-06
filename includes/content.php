<?php
/*
	Functions to detect member content and protect it.
*/
function pmpro_has_membership_access($post_id = NULL, $user_id = NULL, $return_membership_levels = false)
{
	global $post, $wpdb, $current_user;

	//get queried object in case we check against that
	if(!is_admin())
		$queried_object = get_queried_object();
	else
		$queried_object = NULL;
		
	//use post global or queried object if no $post_id was passed in
	if(!$post_id && !empty($post) && !empty($post->ID))
		$post_id = $post->ID;
	elseif(!$post_id && !empty($queried_object) && !empty($queried_object->ID))
		$post_id = $queried_object->ID;
	
	//no post, return true (changed from false in version 1.7.2)
	if(!$post_id)
		return true;
	
	//use current user if no value is supplied
	if(!$user_id)
		$user_id = $current_user->ID;
	
	//if no post or current_user object, set them up
	if(isset($queried_object->ID) && !empty($queried_object->ID) && $post_id == $queried_object->ID)
		$mypost = $queried_object;
	elseif(isset($post->ID) && !empty($post->ID) && $post_id == $post->ID)
		$mypost = $post;
	else
		$mypost = get_post($post_id);

	if($user_id == $current_user->ID)
		$myuser = $current_user;
	else
		$myuser = get_userdata($user_id);

	//for these post types, we want to check the parent
	if(isset($mypost->post_type) && in_array( $mypost->post_type, array("attachment", "revision")))
	{
		$mypost = get_post($mypost->post_parent);
	}

	// Allow plugins and themes to find the protected post        
    $mypost = apply_filters( 'pmpro_membership_access_post', $mypost, $myuser );
	
	if(isset($mypost->post_type) && $mypost->post_type == "post")
	{
		$post_categories = wp_get_post_categories($mypost->ID);

		if(!$post_categories)
		{
			//just check for entries in the memberships_pages table
			$sqlQuery = "SELECT m.id, m.name FROM $wpdb->pmpro_memberships_pages mp LEFT JOIN $wpdb->pmpro_membership_levels m ON mp.membership_id = m.id WHERE mp.page_id = '" . $mypost->ID . "'";
		}
		else
		{
			//are any of the post categories associated with membership levels? also check the memberships_pages table
			$sqlQuery = "(SELECT m.id, m.name FROM $wpdb->pmpro_memberships_categories mc LEFT JOIN $wpdb->pmpro_membership_levels m ON mc.membership_id = m.id WHERE mc.category_id IN(" . implode(",", $post_categories) . ") AND m.id IS NOT NULL) UNION (SELECT m.id, m.name FROM $wpdb->pmpro_memberships_pages mp LEFT JOIN $wpdb->pmpro_membership_levels m ON mp.membership_id = m.id WHERE mp.page_id = '" . $mypost->ID . "')";
		}
	}
	else
	{
		//are any membership levels associated with this page?
		$sqlQuery = "SELECT m.id, m.name FROM $wpdb->pmpro_memberships_pages mp LEFT JOIN $wpdb->pmpro_membership_levels m ON mp.membership_id = m.id WHERE mp.page_id = '" . $post_id . "'";
	}


	$post_membership_levels = $wpdb->get_results($sqlQuery);

	$post_membership_levels_ids = array();
	$post_membership_levels_names = array();

	if(!$post_membership_levels)
	{
		$hasaccess = true;
	}
	else
	{
		//we need to see if the user has access
		foreach($post_membership_levels as $level)
		{
			$post_membership_levels_ids[] = $level->id;
			$post_membership_levels_names[] = $level->name;
		}

		//levels found. check if this is in a feed or if the current user is in at least one of those membership levels
		if(is_feed())
		{
			//always block restricted feeds
			$hasaccess = false;
		}
		elseif(!empty($myuser->ID))
		{
			$myuser->membership_level = pmpro_getMembershipLevelForUser($myuser->ID);
			if(!empty($myuser->membership_level->ID) && in_array($myuser->membership_level->ID, $post_membership_levels_ids))
			{
				//the users membership id is one that will grant access
				$hasaccess = true;
			}
			else
			{
				//user isn't a member of a level with access
				$hasaccess = false;
			}
		}
		else
		{
			//user is not logged in and this content requires membership
			$hasaccess = false;
		}
	}

	/*
		Filters
		The generic filter is run first. Then if there is a filter for this post type, that is run.
	*/
	//general filter for all posts
	$hasaccess = apply_filters("pmpro_has_membership_access_filter", $hasaccess, $mypost, $myuser, $post_membership_levels);
	//filter for this post type
	if(has_filter("pmpro_has_membership_access_filter_" . $mypost->post_type))
		$hasaccess = apply_filters("pmpro_has_membership_access_filter_" . $mypost->post_type, $hasaccess, $mypost, $myuser, $post_membership_levels);

	//return
	if($return_membership_levels)
		return array($hasaccess, $post_membership_levels_ids, $post_membership_levels_names);
	else
		return $hasaccess;
}

function pmpro_search_filter($query)
{
    global $current_user, $wpdb, $pmpro_pages;
			
    //hide pmpro pages from search results
    if(!$query->is_admin && $query->is_search && empty($query->query['post_parent']))
    {
        if(empty($query->query_vars['post_parent']))	//avoiding post_parent queries for now			
			$query->set('post__not_in', $pmpro_pages );

		$query->set('post__not_in', $pmpro_pages ); // id of page or post		
    }

    //hide member pages from non-members (make sure they aren't hidden from members)    
	if(!$query->is_admin && 
	   !$query->is_singular && 
	   empty($query->query['post_parent']) &&
	   (
		empty($query->query_vars['post_type']) || 
		in_array($query->query_vars['post_type'], apply_filters('pmpro_search_filter_post_types', array("page", "post")))
	   )	   
	)
    {		
		//get page ids that are in my levels
        $levels = pmpro_getMembershipLevelsForUser($current_user->ID);
        $my_pages = array();

        if($levels) {
            foreach($levels as $key => $level) {
                //get restricted posts for level
                $sql = "SELECT page_id FROM $wpdb->pmpro_memberships_pages WHERE membership_id=" . $current_user->membership_level->ID;
                $member_pages = $wpdb->get_col($sql);
                $my_pages = array_unique(array_merge($my_pages, $member_pages));
            }
        }

        //get hidden page ids
        if(!empty($my_pages))
			$sql = "SELECT page_id FROM $wpdb->pmpro_memberships_pages WHERE page_id NOT IN(" . implode(',', $my_pages) . ")";
		else
			$sql = "SELECT page_id FROM $wpdb->pmpro_memberships_pages";
        $hidden_page_ids = array_values(array_unique($wpdb->get_col($sql)));						
		
        if($hidden_page_ids)
		{
			if(empty($query->query_vars['post_parent']))			//avoiding post_parent queries for now				
				$query->set('post__not_in', $hidden_page_ids);
		}
				
        //get categories that are filtered by level, but not my level
        global $pmpro_my_cats;
		$pmpro_my_cats = array();

        if($levels) {
            foreach($levels as $key => $level) {
                $member_cats = pmpro_getMembershipCategories($level->id);
                $pmpro_my_cats = array_unique(array_merge($pmpro_my_cats, $member_cats));
            }
        }
		
        //get hidden cats
        if(!empty($pmpro_my_cats))
			$sql = "SELECT category_id FROM $wpdb->pmpro_memberships_categories WHERE category_id NOT IN(" . implode(',', $pmpro_my_cats) . ")";
		else
			$sql = "SELECT category_id FROM $wpdb->pmpro_memberships_categories";
					
        $hidden_cat_ids = array_values(array_unique($wpdb->get_col($sql)));
				
        //make this work
        if($hidden_cat_ids)
		{			
            $query->set('category__not_in', $hidden_cat_ids);
						
			//filter so posts in this member's categories are allowed
			add_action('posts_where', 'pmpro_posts_where_unhide_cats');
		}
    }

    return $query;
}
$filterqueries = pmpro_getOption("filterqueries");
if(!empty($filterqueries))
    add_filter( 'pre_get_posts', 'pmpro_search_filter' );
  
/*
 * Find taxonomy filters and make sure member categories are not hidden from members.
 * @since 1.7.15
*/
function pmpro_posts_where_unhide_cats($where)
{
	global $pmpro_my_cats, $wpdb;
		
	//if we have member cats, make sure they are allowed in taxonomy queries
	if(!empty($where) && !empty($pmpro_my_cats))
	{
		$pattern = "/$wpdb->posts.ID NOT IN \(\s*SELECT object_id\s*FROM dev_term_relationships\s*WHERE term_taxonomy_id IN \((.*)\)\s*\)/";
		$replacement = $wpdb->posts . '.ID NOT IN (
						SELECT tr1.object_id
						FROM ' . $wpdb->term_relationships . ' tr1
							LEFT JOIN ' . $wpdb->term_relationships . ' tr2 ON tr1.object_id = tr2.object_id AND tr2.term_taxonomy_id IN(' . implode($pmpro_my_cats) . ') 
						WHERE tr1.term_taxonomy_id IN(${1}) AND tr2.term_taxonomy_id IS NULL ) ';	
		$where = preg_replace($pattern, $replacement, $where);
	}
			
	//remove filter for next query
	remove_action('posts_where', 'pmpro_posts_where_unhide_cats');
		
	return $where;
}
  
function pmpro_membership_content_filter($content, $skipcheck = false)
{	
	global $post, $current_user;

	if(!$skipcheck)
	{
		$hasaccess = pmpro_has_membership_access(NULL, NULL, true);
		if(is_array($hasaccess))
		{
			//returned an array to give us the membership level values
			$post_membership_levels_ids = $hasaccess[1];
			$post_membership_levels_names = $hasaccess[2];
			$hasaccess = $hasaccess[0];
		}
	}

	if($hasaccess)
	{
		//all good, return content
		return $content;
	}
	else
	{
		//if show excerpts is set, return just the excerpt
		if(pmpro_getOption("showexcerpts"))
		{			
			//show excerpt
			global $post;
			if($post->post_excerpt)
			{								
				//defined exerpt
				$content = wpautop($post->post_excerpt);
			}
			elseif(strpos($content, "<span id=\"more-" . $post->ID . "\"></span>") !== false)
			{				
				//more tag
				$pos = strpos($content, "<span id=\"more-" . $post->ID . "\"></span>");
				$content = wpautop(substr($content, 0, $pos));
			}
			elseif(strpos($content, 'class="more-link">') !== false)
			{
				//more link
				$content = preg_replace("/\<a.*class\=\"more\-link\".*\>.*\<\/a\>/", "", $content);
			}
			else
			{
				//auto generated excerpt. pulled from wp_trim_excerpt
				$content = strip_shortcodes( $content );
				$content = str_replace(']]>', ']]&gt;', $content);
				$content = strip_tags($content);
				$excerpt_length = apply_filters('excerpt_length', 55);
				$words = preg_split("/[\n\r\t ]+/", $content, $excerpt_length + 1, PREG_SPLIT_NO_EMPTY);
				if ( count($words) > $excerpt_length ) {
					array_pop($words);
					$content = implode(' ', $words);
					$content = $content . "... ";
				} else {
					$content = implode(' ', $words) . "... ";
				}

				$content = wpautop($content);
			}
		}
		else
		{
			//else hide everything
			$content = "";
		}

		if(empty($post_membership_levels_ids))
			$post_membership_levels_ids = array();

		if(empty($post_membership_levels_names))
			$post_membership_levels_names = array();

        //hide levels which don't allow signups by default
        if(!apply_filters("pmpro_membership_content_filter_disallowed_levels", false, $post_membership_levels_ids, $post_membership_levels_names))
        {
            foreach($post_membership_levels_ids as $key=>$id)
            {
                //does this level allow registrations?
                $level_obj = pmpro_getLevel($id);
                if(!$level_obj->allow_signups)
                {
                    unset($post_membership_levels_ids[$key]);
                    unset($post_membership_levels_names[$key]);
                }
            }
        }

		$pmpro_content_message_pre = '<div class="pmpro_content_message">';
		$pmpro_content_message_post = '</div>';

		$sr_search = array("!!levels!!", "!!referrer!!");
		$sr_replace = array(pmpro_implodeToEnglish($post_membership_levels_names), urlencode(site_url($_SERVER['REQUEST_URI'])));

		//get the correct message to show at the bottom
		if(is_feed())
		{
			$newcontent = apply_filters("pmpro_rss_text_filter", stripslashes(pmpro_getOption("rsstext")));
			$content .= $pmpro_content_message_pre . str_replace($sr_search, $sr_replace, $newcontent) . $pmpro_content_message_post;
		}
		elseif($current_user->ID)
		{
			//not a member
			$newcontent = apply_filters("pmpro_non_member_text_filter", stripslashes(pmpro_getOption("nonmembertext")));
			$content .= $pmpro_content_message_pre . str_replace($sr_search, $sr_replace, $newcontent) . $pmpro_content_message_post;
		}
		else
		{
			//not logged in!
			$newcontent = apply_filters("pmpro_not_logged_in_text_filter", stripslashes(pmpro_getOption("notloggedintext")));
			$content .= $pmpro_content_message_pre . str_replace($sr_search, $sr_replace, $newcontent) . $pmpro_content_message_post;
		}
	}

	return $content;
}
add_filter('the_content', 'pmpro_membership_content_filter', 5);
add_filter('the_content_rss', 'pmpro_membership_content_filter', 5);
add_filter('comment_text_rss', 'pmpro_membership_content_filter', 5);

/*
	If the_excerpt is called, we want to disable the_content filters so the PMPro messages aren't added to the content before AND after the ecerpt.
*/
function pmpro_membership_excerpt_filter($content, $skipcheck = false)
{		
	remove_filter('the_content', 'pmpro_membership_content_filter', 5);	
	$content = pmpro_membership_content_filter($content, $skipcheck);
	add_filter('the_content', 'pmpro_membership_content_filter', 5);
	
	return $content;
}
function pmpro_membership_get_excerpt_filter_start($content, $skipcheck = false)
{	
	remove_filter('the_content', 'pmpro_membership_content_filter', 5);		
	return $content;
}
function pmpro_membership_get_excerpt_filter_end($content, $skipcheck = false)
{	
	add_filter('the_content', 'pmpro_membership_content_filter', 5);		
	return $content;
}
add_filter('the_excerpt', 'pmpro_membership_excerpt_filter', 15);
add_filter('get_the_excerpt', 'pmpro_membership_get_excerpt_filter_start', 1);
add_filter('get_the_excerpt', 'pmpro_membership_get_excerpt_filter_end', 100);

function pmpro_comments_filter($comments, $post_id = NULL)
{
	global $post, $wpdb, $current_user;
	if(!$post_id)
		$post_id = $post->ID;

	if(!$comments)
		return $comments;	//if they are closed anyway, we don't need to check

	global $post, $current_user;

	$hasaccess = pmpro_has_membership_access(NULL, NULL, true);
	if(is_array($hasaccess))
	{
		//returned an array to give us the membership level values
		$post_membership_levels_ids = $hasaccess[1];
		$post_membership_levels_names = $hasaccess[2];
		$hasaccess = $hasaccess[0];
	}

	if($hasaccess)
	{
		//all good, return content
		return $comments;
	}
	else
	{
		if(!$post_membership_levels_ids)
			$post_membership_levels_ids = array();

		if(!$post_membership_levels_names)
			$post_membership_levels_names = array();

		//get the correct message
		if(is_feed())
		{
			if(is_array($comments))
				return array();
			else
				return false;
		}
		elseif($current_user->ID)
		{
			//not a member
			if(is_array($comments))
				return array();
			else
				return false;
		}
		else
		{
			//not logged in!
			if(is_array($comments))
				return array();
			else
				return false;
		}
	}

	return $comments;
}
add_filter("comments_array", "pmpro_comments_filter");
add_filter("comments_open", "pmpro_comments_filter");

//keep non-members from getting to certain pages (attachments, etc)
function pmpro_hide_pages_redirect()
{
	global $post;

	if(!is_admin() && !empty($post->ID))
	{
		if($post->post_type == "attachment")
		{
			//check if the user has access to the parent
			if(!pmpro_has_membership_access($post->ID))
			{
				wp_redirect(pmpro_url("levels"));
				exit;
			}
		}
	}
}
add_action('wp', 'pmpro_hide_pages_redirect');

/**
 * Adds custom classes to the array of post classes.
 *
 * pmpro-level-required = this post requires at least one level
 * pmpro-level-1 = this post requires level 1
 * pmpro-has-access = this post is usually locked, but the current user has access to this post
 *
 * @param array $classes Classes for the post element.
 * @return array
 *
 * @since 1.8.5.4
 */
function pmpro_post_classes( $classes, $class, $post_id ) {	
	
	$post = get_post($post_id);
	
	if(empty($post))
		return $classes;
	
	$post_levels = array();
	$post_levels = pmpro_has_membership_access($post->ID,NULL,true);
	
	if(!empty($post_levels))
	{
		if(!empty($post_levels[1]))
		{
			$classes[] = 'pmpro-level-required';
			foreach($post_levels[1] as $post_level)
				$classes[] = 'pmpro-level-' . $post_level[0];
		}
		if(!empty($post_levels[0]) && $post_levels[0] == true)
			$classes[] = 'pmpro-has-access';
		else
			$classes[] = 'pmpro-no-access';
	}
	return $classes;
}
add_filter( 'post_class', 'pmpro_post_classes', 10, 3 );

/**
 * Adds custom classes to the array of body classes.
 * Same as the above, but acts on the "queried object" instead of the post global.
 *
 * pmpro-body-level-required = this post requires at least one level
 * pmpro-body-level-1 = this post requires level 1
 * pmpro-body-has-access = this post is usually locked, but the current user has access to this post
 *
 * @param array $classes Classes for the body element.
 * @return array
 *
 * @since 1.8.6.1
 */
function pmpro_body_classes( $classes ) {	
	
	$post = get_queried_object();
	
	if(empty($post) || !is_singular())
		return $classes;
	
	$post_levels = array();
	$post_levels = pmpro_has_membership_access($post->ID,NULL,true);
	
	if(!empty($post_levels))
	{
		if(!empty($post_levels[1]))
		{
			$classes[] = 'pmpro-body-level-required';
			foreach($post_levels[1] as $post_level)
				$classes[] = 'pmpro-body-level-' . $post_level[0];
		}
		if(!empty($post_levels[0]) && $post_levels[0] == true)
			$classes[] = 'pmpro-body-has-access';
	}
	return $classes;
}
add_filter( 'body_class', 'pmpro_body_classes' );
