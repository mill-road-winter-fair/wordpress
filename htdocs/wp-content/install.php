<?php
/**
 * WordPress custom install script.
 *
 * Drop-ins are advanced plugins in the wp-content directory that replace
 * WordPress functionality when present.
 *
 */

/**
 * Installs the site.
 *
 * Runs the required functions to set up and populate the database,
 * including primary admin user and initial options.
 *
 * @since 2.1.0
 *
 * @param string $blog_title    Blog title.
 * @param string $user_name     User's username.
 * @param string $user_email    User's email.
 * @param bool   $public        Whether blog is public.
 * @param string $deprecated    Optional. Not used.
 * @param string $user_password Optional. User's chosen password. Default empty (random password).
 * @param string $language      Optional. Language chosen. Default empty.
 * @return array Array keys 'url', 'user_id', 'password', and 'password_message'.
 */
function wp_install( $blog_title, $user_name, $user_email, $public, $deprecated = '', $user_password = '', $language = '' ) {
  if ( ! empty( $deprecated ) ) {
    _deprecated_argument( __FUNCTION__, '2.6' );
  }

  wp_check_mysql_version();
  wp_cache_flush();
  make_db_current_silent();
  populate_options();
  populate_roles();

  // Since the default Vagrant box name is "wordpress", ensure
  // it is capitalized correctly if used as site tile
  if ( $blog_title == 'Wordpress' ) {
    $blog_title = 'WordPress';
  }
  update_option('blogname', $blog_title);
  update_option('admin_email', $user_email);
  update_option('blog_public', $public);

  // Freshness of site - in the future, this could get more specific about actions taken, perhaps.
  update_option( 'fresh_site', 1 );

  // Seravo: Pickup environment defined language
  $env_wp_lang = getenv('WP_LANG');

  // Seravo: Load the text domain for environment variable
  if ( ! $language && $env_wp_lang ) {
    $language = $env_wp_lang;

    load_default_textdomain( $env_wp_lang );
    $GLOBALS['wp_locale'] = new WP_Locale();
  }

  if ( $language ) {
    update_option( 'WPLANG', $language );
  }

  $guessurl = wp_guess_url();

  update_option('siteurl', $guessurl);

  // If not a public blog, don't ping.
  if ( ! $public ) {
    update_option('default_pingback_flag', 0);
  }

  /*
   * Create default user. If the user already exists, the user tables are
   * being shared among blogs. Just set the role in that case.
   */
  $user_id = username_exists($user_name);
  $user_password = trim($user_password);
  $email_password = false;
  if ( ! $user_id && empty($user_password) ) {
    $user_password = wp_generate_password( 12, false );
    $message = __('<strong><em>Note that password</em></strong> carefully! It is a <em>random</em> password that was generated just for you.');
    $user_id = wp_create_user($user_name, $user_password, $user_email);
    update_user_option($user_id, 'default_password_nag', true, true);
    $email_password = true;
  } elseif ( ! $user_id ) {
    // Password has been provided
    $message = '<em>' . __('Your chosen password.') . '</em>';
    $user_id = wp_create_user($user_name, $user_password, $user_email);
  } else {
    $message = __('User already exists. Password inherited.');
  }

  $user = new WP_User($user_id);
  $user->set_role('administrator');

  wp_install_defaults($user_id);

  wp_install_maybe_enable_pretty_permalinks();

  flush_rewrite_rules();

  // Seravo: Don't notify the site admin that the setup is complete
  // wp_new_blog_notification($blog_title, $guessurl, $user_id, ($email_password ? $user_password : __('The password you chose during the install.') ) );

  wp_cache_flush();

  /**
   * Fires after a site is fully installed.
   *
   * @since 3.9.0
   *
   * @param WP_User $user The site owner.
   */
  do_action( 'wp_install', $user );

  return array(
    'url'              => $guessurl,
    'user_id'          => $user_id,
    'password'         => $user_password,
    'password_message' => $message,
  );
}

/**
 * Creates the initial content for a newly-installed site.
 *
 * Adds the default "Uncategorized" category, the first post (with comment),
 * first page, and default widgets for default theme for the current version.
 *
 * @since 2.1.0
 *
 * @global wpdb       $wpdb
 * @global WP_Rewrite $wp_rewrite
 * @global string     $table_prefix
 *
 * @param int $user_id User ID.
 */
function wp_install_defaults( $user_id ) {
  global $wpdb, $wp_rewrite, $table_prefix;

  // Default category
  $cat_name = __('Uncategorized');
  /* translators: Default category slug */
  $cat_slug = sanitize_title(_x('Uncategorized', 'Default category slug'));

  if ( global_terms_enabled() ) {
    $cat_id = $wpdb->get_var( $wpdb->prepare( "SELECT cat_ID FROM {$wpdb->sitecategories} WHERE category_nicename = %s", $cat_slug ) );
    if ( $cat_id === null ) {
      $wpdb->insert(
        $wpdb->sitecategories,
        array(
          'cat_ID'            => 0,
          'cat_name'          => $cat_name,
          'category_nicename' => $cat_slug,
          'last_updated'      => current_time('mysql', true),
        )
      );
      $cat_id = $wpdb->insert_id;
    }
    update_option('default_category', $cat_id);
  } else {
    $cat_id = 1;
  }

  $wpdb->insert( $wpdb->terms, array(
    'term_id'    => $cat_id,
    'name'       => $cat_name,
    'slug'       => $cat_slug,
    'term_group' => 0,
  ) );
  $wpdb->insert( $wpdb->term_taxonomy, array(
    'term_id'     => $cat_id,
    'taxonomy'    => 'category',
    'description' => '',
    'parent'      => 0,
    'count'       => 1,
  ));
  $cat_tt_id = $wpdb->insert_id;

  // Seravo: Don't create first post and comment to make the install cleaner
  // First post
  $now = current_time( 'mysql' );
  $now_gmt = current_time( 'mysql', 1 );

  // First Page
  if ( is_multisite() ) {
    $first_page = get_site_option( 'first_page' );
  }

  if ( empty( $first_page ) ) {
    // Seravo: Get content for first page from helper function
    $first_page = mrwf_first_page();
  }

  $first_post_guid = get_option('home') . '/?page_id=1';
  $wpdb->insert( $wpdb->posts, array(
    'id'                    => 1,
    'post_author'           => $user_id,
    'post_date'             => $now,
    'post_date_gmt'         => $now_gmt,
    'post_content'          => $first_page,
    'post_excerpt'          => '',
    'comment_status'        => 'closed',
    'post_title'            => seravo_page_title(),
    /* translators: Default page slug */
    // Seravo: Set a slug that makes sense and dont interfere with possible other content
    'post_name'             => __( 'seravo-start' ),
    'post_modified'         => $now,
    'post_modified_gmt'     => $now_gmt,
    'guid'                  => $first_post_guid,
    'post_type'             => 'page',
    'to_ping'               => '',
    'pinged'                => '',
    'post_content_filtered' => '',
  ));
  $wpdb->insert( $wpdb->postmeta, array(
    'post_id'    => 1,
    'meta_key'   => '_wp_page_template',
    'meta_value' => 'default',
  ) );

  // Privacy Policy page
  if ( is_multisite() ) {
    // Disable by default unless the suggested content is provided.
    $privacy_policy_content = get_site_option( 'default_privacy_policy_content' );
  } else {
    if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
      include_once ABSPATH . 'wp-admin/includes/misc.php';
    }

    $privacy_policy_content = WP_Privacy_Policy_Content::get_default_content();
  }

  if ( ! empty( $privacy_policy_content ) ) {
    $privacy_policy_guid = get_option( 'home' ) . '/?page_id=2';

    $wpdb->insert(
      $wpdb->posts,
      array(
        'id'                    => 2,
        'post_author'           => $user_id,
        'post_date'             => $now,
        'post_date_gmt'         => $now_gmt,
        'post_content'          => $privacy_policy_content,
        'post_excerpt'          => '',
        'comment_status'        => 'closed',
        'post_title'            => __( 'Privacy Policy' ),
        /* translators: Privacy Policy page slug */
        'post_name'             => __( 'privacy-policy' ),
        'post_modified'         => $now,
        'post_modified_gmt'     => $now_gmt,
        'guid'                  => $privacy_policy_guid,
        'post_type'             => 'page',
        'post_status'           => 'draft',
        'to_ping'               => '',
        'pinged'                => '',
        'post_content_filtered' => '',
      )
    );
    $wpdb->insert(
      $wpdb->postmeta,
      array(
        'post_id'    => 2,
        'meta_key'   => '_wp_page_template',
        'meta_value' => 'default',
      )
    );
    update_option( 'wp_page_for_privacy_policy', 2 );
  }

  // Seravo: Don't setup standard widgets to make the install cleaner
  // Set up default widgets for default theme.

  if ( ! is_multisite() ) {
    update_user_meta( $user_id, 'show_welcome_panel', 1 );
  } elseif ( ! is_super_admin( $user_id ) && ! metadata_exists( 'user', $user_id, 'show_welcome_panel' ) ) {
    update_user_meta( $user_id, 'show_welcome_panel', 2 );
  }

  if ( is_multisite() ) {
    // Flush rules to pick up the new page.
    $wp_rewrite->init();
    $wp_rewrite->flush_rules();

    $user = new WP_User($user_id);
    $wpdb->update( $wpdb->options, array( 'option_value' => $user->user_email ), array( 'option_name' => 'admin_email' ) );

    // Remove all perms except for the login user.
    $wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->usermeta WHERE user_id != %d AND meta_key = %s", $user_id, $table_prefix . 'user_level') );
    $wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->usermeta WHERE user_id != %d AND meta_key = %s", $user_id, $table_prefix . 'capabilities') );

    // Delete any caps that snuck into the previously active blog. (Hardcoded to blog 1 for now.) TODO: Get previous_blog_id.
    if ( ! is_super_admin( $user_id ) && $user_id != 1 ) {
      $wpdb->delete( $wpdb->usermeta, array(
        'user_id'  => $user_id,
        'meta_key' => $wpdb->base_prefix . '1_capabilities',
      ) );
    }
  }

  /**
   * Seravo specific option changes
   */

  /** @see wp-admin/options-general.php */
  // Remove standard blog description
  update_option( 'blogdescription', '' );
  // Set timezone, date and time format
  update_option( 'timezone_string', seravo_timezone_string() );
  update_option( 'date_format', seravo_date_format() );
  update_option( 'time_format', seravo_time_format() );

  /** @see wp-admin/options-reading.php */
  // Set the created page to show on the homepage
  update_option( 'show_on_front', 'page' );
  update_option( 'page_on_front', 1 );

  /** @see wp-admin/options-discussion.php */
  // Allow people to post comments on new articles (this setting may be
  // overridden for individual articles): false
  update_option( 'default_comment_status', 0 );

  /** @see wp-admin/options-permalink.php */
  // Permalink custom structure: /%postname%
  update_option( 'permalink_structure', '/%postname%/' );

  /** Activate some plugins automatically if they exists */
  seravo_install_activate_plugins();

  /** Activate all mature features of Seravo Plugin on new sites */
  seravo_plugin_options_enable();
}

/**
 * Helper functions to return language specific content and options
 */

function seravo_page_title() {

  $env_wp_lang = getenv('WP_LANG');

  if ( 'fi' == get_locale() || 'fi' == $env_wp_lang ) {
    $seravo_page_title = 'Tervetuloa';
  } elseif ( 'sv_SE' == get_locale() || 'sv_SE' == $env_wp_lang ) {
    $seravo_page_title = 'Välkommen';
  } else {
    $seravo_page_title = 'Welcome';
  }

  return $seravo_page_title;
}

function mrwf_first_page() {

  $env_wp_lang = getenv('WP_LANG');
  ob_start();
  ?>
  <!-- wp:heading -->
  <h2>Congratulations!</h2>
  <!-- /wp:heading -->

  <!-- wp:paragraph -->
  <p>You have successfully set up the test environment for the mill road winter fair wordpress site!</p>
  <!-- /wp:paragraph -->
  <?php
  $first_page = ob_get_clean();

  return $first_page;
}

function seravo_timezone_string() {

  $env_wp_lang = getenv('WP_LANG');

  if ( 'fi' == get_locale() || 'fi' == $env_wp_lang ) {
    $seravo_timezone_string = 'Europe/Helsinki';
  } elseif ( 'sv_SE' == get_locale() || 'sv_SE' == $env_wp_lang ) {
    $seravo_timezone_string = 'Europe/Stockholm';
  } else {
    $seravo_timezone_string = '';
  }

  return $seravo_timezone_string;
}

function seravo_date_format() {

  $env_wp_lang = getenv('WP_LANG');

  if ( 'fi' == get_locale() || 'fi' == $env_wp_lang ) {
    $seravo_date_format = 'j.n.Y';
  } elseif ( 'sv_SE' == get_locale() || 'sv_SE' == $env_wp_lang ) {
    $seravo_date_format = 'Y-m-d';
  } else {
    $seravo_date_format = 'F j, Y';
  }

  return $seravo_date_format;
}

function seravo_time_format() {

  $env_wp_lang = getenv('WP_LANG');

  if ( 'fi' == get_locale() || 'fi' == $env_wp_lang ) {
    $seravo_time_format = 'H:i';
  } elseif ( 'sv_SE' == get_locale() || 'sv_SE' == $env_wp_lang ) {
    $seravo_time_format = 'H:i';
  } else {
    $seravo_time_format = 'H:i';
  }

  return $seravo_time_format;
}

/*
 * Helper which activates some useful plugins
 */
function seravo_install_activate_plugins() {

  // Get the list of all installed plugins
  $all_plugins = get_plugins();

  // Auto activate these plugins on install
  // You can override default ones using WP_AUTO_ACTIVATE_PLUGINS in your wp-config.php
  if (defined('WP_AUTO_ACTIVATE_PLUGINS')) {
    $plugins = explode(',',WP_AUTO_ACTIVATE_PLUGINS);
  } else {
    $plugins = array();
  }

  // Activate plugins if they can be found from installed plugins
  foreach ($all_plugins as $plugin_path => $data) {
    $plugin_name = explode('/',$plugin_path)[0]; // get the folder name from plugin
    if (in_array($plugin_name,$plugins)) { // If plugin is installed activate it
      // Do the activation
      include_once(WP_PLUGIN_DIR . '/' . $plugin_path);
      do_action('activate_plugin', $plugin_path);
      do_action('activate_' . $plugin_path);
      $current[] = $plugin_path;
      sort($current);
      update_option('active_plugins', $current);
      do_action('activated_plugin', $plugin_path);
    }
  }
}


/*
 * Ensure all new sites have all mature Seravo plugin features enabled
 *
 * Note that this install.php only runs on fresh installs. If some existing site
 * is migrated to Seravo, the database and all options are overwritten and these
 * options will no longer apply.
 */
function seravo_plugin_options_enable() {
  // Security settings
  update_option('seravo-disable-xml-rpc', 'on');
  update_option('seravo-disable-json-user-enumeration', 'on');
  update_option('seravo-disable-get-author-enumeration', 'on');

  // Image optimizer
  update_option('seravo-enable-optimize-images', 'on');
}
