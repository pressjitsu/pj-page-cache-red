<?php 
/**
 * Generated by the WordPress Option Page generator
 * at http://jeremyhixon.com/wp-tools/option-page/
 */

class RedisFullPageCache {
	private $redis_full_page_cache_options;

	public function redis_full_page_cache_add_plugin_page() {
		add_options_page(
			'Redis Full Page Cache', // page_title
			'Redis Full Page Cache', // menu_title
			'manage_options', // capability
			'redis-full-page-cache', // menu_slug
			array( $this, 'redis_full_page_cache_create_admin_page' ) // function
		);
	}

	public function redis_full_page_cache_create_admin_page() {
		$this->redis_full_page_cache_options = get_option( 'redis_full_page_cache_option_name' ); ?>

		<div class="wrap">
			<h2>Redis Full Page Cache</h2>
			<p>These settings add additional options when a page is purged</p>
            <div>Fields are comma seperated (e.g "/test/,/test2/")</div>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
					settings_fields( 'redis_full_page_cache_option_group' );
					do_settings_sections( 'redis-full-page-cache-admin' );
					submit_button();
				?>
			</form>
		</div>
	<?php }

	public function redis_full_page_cache_page_init() {
		register_setting(
			'redis_full_page_cache_option_group', // option_group
			'redis_full_page_cache_option_name', // option_name
			array( $this, 'redis_full_page_cache_sanitize' ) // sanitize_callback
		);

		add_settings_section(
			'redis_full_page_cache_setting_section', // id
			'Settings', // title
			array( $this, 'redis_full_page_cache_section_info' ), // callback
			'redis-full-page-cache-admin' // page
		);

		add_settings_field(
			'always_purge_urls_0', // id
			'Always Purge URLs', // title
			array( $this, 'always_purge_urls_0_callback' ), // callback
			'redis-full-page-cache-admin', // page
			'redis_full_page_cache_setting_section' // section
		);

	}

	public function redis_full_page_cache_sanitize($input) {
		$sanitary_values = array();
		if ( isset( $input['always_purge_urls_0'] ) ) {
			$sanitary_values['always_purge_urls_0'] = sanitize_text_field( $input['always_purge_urls_0'] );
		}

		return $sanitary_values;
	}

	public function redis_full_page_cache_section_info() {
		
	}

	public function always_purge_urls_0_callback() {
        $overrides = '';
        if (getenv('ALWAYS_PURGE_URLS') !== null) {
            $overrides = ',' . getenv('ALWAYS_PURGE_URLS');
        }
		printf(
            '<div>These URLs will always be purged on each post update/publish</div>
            <input class="regular-text" type="text" name="redis_full_page_cache_option_name[always_purge_urls_0]" id="always_purge_urls_0" value="%s">
            <div><strong>Current Overrides: "/' . $overrides . '"</div></strong>',
			isset( $this->redis_full_page_cache_options['always_purge_urls_0'] ) ? esc_attr( $this->redis_full_page_cache_options['always_purge_urls_0']) : ''
		);
	}

}

$redis_full_page_cache = new RedisFullPageCache();