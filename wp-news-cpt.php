<?php
/*
Plugin Name: News Custom Post Type
Plugin URI: http://urre.me
Author: @urre
Author URI: http://urre.me inspired by av http://www.daverupert.com
Description: Adds Custom Post Type News. Supports custom url rewrites for date archives. Endpooints /news, /news/2012, /news/2012/06. Also displays Post Thumbnail in separate column i admin
Version: 0.1
*/

class Custom_Post_Type_With_Rewrite_Rules {

  private $post_type;
  private $query_var;
  private $permalink_prefix;
  private $permalink_structure;

  /**
   * Constructor method
   *
   * $permalink_args options:
   * -front: The front of the permalinks for this post type.  All URLs for this post type will start with this
   * -structure: The structure of the permalink.  Accepts the following tags: %year%, %month%, %day% and %{query_var}%, the structure must contain the query var tag
   *
   * @param string $post_type
   * @param array $post_type_args Arguments normally passed into register_post_type
   * @param array $permalink_args Arguments controlling the permalink structure.
   */
  public function __construct($post_type, $post_type_args = array(), $permalink_args = array()) {
    //make sure the rewrite settings for the post type are set to false to prevent interference
    $post_type_args['rewrite'] = false;

    //register the post type and get the returned args
    $post_type_args = register_post_type($post_type, $post_type_args);

    if('' == get_option('permalink_structure') || !$post_type_args->publicly_queryable) {
      return; //only continue if using permalink structures and post type is publicly queryable
    }

    $this->post_type = $post_type_args->name;
    $this->query_var = $post_type_args->query_var;

    $default_permalink_args = array(
      'structure' => '%year%/%monthnum%/%day%/%'.$this->query_var.'%/',
      'front' => $this->post_type
    );

    $permalink_args = wp_parse_args($permalink_args, $default_permalink_args);

    $this->permalink_prefix = trim($permalink_args['front'], '/');
    $this->permalink_structure = trailingslashit(ltrim($permalink_args['structure'], '/'));

    //register the add_rewrite_rules method to run only when rules are being flushed.
    add_action('delete_option_rewrite_rules', array($this, 'add_rewrite_rules'));

    //go ahead and add the rewrite rules if the option is currently empty
    $current_rules = get_option('rewrite_rules');
    if(empty($current_rules)) {
      $this->add_rewrite_rules();
    }

    //add a filter to fix the url for this post type
    add_filter('post_type_link', array($this, 'filter_post_type_link'), 10, 4);

    # Add Post Type to Search
    add_filter('pre_get_posts', array( $this, 'query_post_type') );

    # Save entered data
    add_action('save_post', array( &$this, 'save_postdata') );

    # Custom Post Type Columns
    add_filter('manage_edit-news_columns', array($this, 'manage_news_edit_columns'));
    add_action('manage_news_posts_custom_column', array($this, 'manage_news_custom_columns'));

  }

  public function add_rewrite_rules() {
    global $wp_rewrite;

    //register the rewrite tag to use for the post type
    $wp_rewrite->add_rewrite_tag('%'.$this->query_var.'%', '([^/]+)', $this->query_var . '=');

    //we use the WP_Rewrite class to generate all the endpoints WordPress can handle by default.
    $rewrite_rules = $wp_rewrite->generate_rewrite_rules($this->permalink_prefix.'/'.$this->permalink_structure, EP_ALL, true, true, true, true, true);

    //build a rewrite rule from just the prefix to be the base url for the post type
    $rewrite_rules = array_merge($wp_rewrite->generate_rewrite_rules($this->permalink_prefix), $rewrite_rules);
    $rewrite_rules[$this->permalink_prefix.'/?$'] = 'index.php?paged=1';
    foreach($rewrite_rules as $regex => $redirect) {
      if(strpos($redirect, 'attachment=') === false) {
        //add the post_type to the rewrite rule
        $redirect .= '&post_type=' . $this->post_type;
      }

      //turn all of the $1, $2,... variables in the matching regex into $matches[] form
      if(0 < preg_match_all('@\$([0-9])@', $redirect, $matches)) {
        for($i = 0; $i < count($matches[0]); $i++) {
          $redirect = str_replace($matches[0][$i], '$matches['.$matches[1][$i].']', $redirect);
        }
      }
      //add the rewrite rule to wp_rewrite
      $wp_rewrite->add_rule($regex, $redirect, 'top');
    }
  }

  public function query_post_type($query) {
    if(is_category() || is_tag()) {
      $post_type = get_query_var('post_type');
    if($post_type) {
      $post_type = $post_type;
    } else {
      $post_type = array($this->type); // replace cpt to your custom post type
    }
    $query->set('post_type',$post_type);
    return $query;
    }
  }

  public function manage_news_edit_columns( $columns ) {
      $columns['bild'] = 'Översikt';
      return $columns;
  }

  public function manage_news_custom_columns( $column ) {
    global $post;

    $image = get_the_post_thumbnail($post->ID, 'medium');

    switch( $column ) {
        case 'bild':
        if ( isset($image)) { ?>
          <?php echo $image; ?>
          <?php } else {
            echo __('Post thumbnail missing');
          }
        break;
    }
  }


  public function save_postdata(){
    if ( empty($_POST) || $_POST['post_type'] !== $this->type || !wp_verify_nonce( $_POST['noncename'], plugin_basename(__FILE__) )) {
      return $post_id;
    }

    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
          return $post_id;

    // Check permissions
    if ( 'page' == $_POST['post_type'] ) {
      if ( !current_user_can( 'edit_page', $post_id ) )
        return $post_id;
    } else {
      if ( !current_user_can( 'edit_post', $post_id ) )
        return $post_id;
    }

    if($_POST['post_type'] == $this->type) {
      global $post;
      foreach($_POST['data'] as $key => $val) {
        update_post_meta($post->ID, $key, $val);
      }
    }
  }
  /**
   * Filter to turn the links for this post type into ones that match our permalink structure
   *
   * @param string $permalink
   * @param object $post
   * @return string New permalink
   */
  public function filter_post_type_link($permalink, $post) {
    if(($this->post_type == $post->post_type) && '' != $permalink && !in_array($post->post_status, array('draft', 'pending', 'auto-draft')) ) {
      $rewritecode = array(
        '%year%',
        '%monthnum%',
        '%day%',
        '%hour%',
        '%minute%',
        '%second%',
        '%post_id%',
        '%author%',
        '%'.$this->query_var.'%'
      );

      $author = '';
      if ( strpos($this->permalink_structure, '%author%') !== false ) {
        $authordata = get_userdata($post->post_author);
        $author = $authordata->user_nicename;
      }

      $unixtime = strtotime($post->post_date);
      $date = explode(" ",date('Y m d H i s', $unixtime));
      $rewritereplace = array(
        $date[0],
        $date[1],
        $date[2],
        $date[3],
        $date[4],
        $date[5],
        $post->ID,
        $author,
        $post->post_name,
      );
      $permalink = str_replace($rewritecode, $rewritereplace, '/'.$this->permalink_prefix.'/'.$this->permalink_structure);
      $permalink = user_trailingslashit(home_url($permalink));
    }
    return $permalink;
  }
}

/**
 * Public registration method for custom post types with rewrite rules.
 *
 * $permalink_args options:
 * -front: The front of the permalinks for this post type.  All URLs for this post type will start with this
 * -structure: The structure of the permalink.  Accepts the following tags: %year%, %month%, %day% and %{query_var}%, the structure must contain the query var tag
 *
 * @param string $post_type
 * @param array $post_type_args Arguments normally passed into register_post_type
 * @param array $permalink_args Arguments controlling the permalink structure.
 */
function register_post_type_with_rewrite_rules($post_type, $post_type_args = array(), $permalink_args = array()) {
  new Custom_Post_Type_With_Rewrite_Rules($post_type, $post_type_args, $permalink_args);
}

//test code for the above
function register_custom_post_types() {
  register_post_type_with_rewrite_rules('news', array(
    'labels' => array(
      'name' => _x('News', 'post type general name'),
      'singular_name' => _x('News', 'post type singular name'),
      'add_new' => _x('Add news', 'news'),
      'add_new_item' => __('Add news article'),
      'edit_item' => __('Edit news article'),
      'new_item' => __('New news article'),
      'view_item' => __('Show news'),
      'search_items' => __('Search for news'),
      'not_found' =>  __('No news were found'),
      'not_found_in_trash' => __('No news were found in trash'),
      'parent_item_colon' => ''
      ),
    'public' => true,
    'publicly_queryable' => true,
    'query_var' => 'news',
    'rewrite' => false,
    'capability_type' => 'post',
    'menu_icon' => plugin_dir_url( __FILE__ ) . 'news.png',
    'hierarchical' => false,
    'has_archive' => true, /* Looks for archive-news.php */
    'supports' => array('title', 'editor', 'author', 'thumbnail', 'revisions', 'excerpt', 'trackbacks', 'custom-fields'),
  ), array('front'=> 'news', 'structure'=>'%year%/%monthnum%/%news%'));
}
add_action('init', 'register_custom_post_types');
?>