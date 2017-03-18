<?php

namespace RedisPageCache\Service;

class WPCompat
{
    public function isAdmin()
    {
        return defined('WP_ADMIN') && WP_ADMIN;
    }

    public function isAdminAjax()
    {
        return defined('DOING_AJAX') && DOING_AJAX;
    }

    public function addAction(string $name, array $args, int $priority = 10, int $num = 1): WPCompat
    {
        if (!function_exists('add_action')) {
            $this->addActionCompat($name, $args, $priority, $num);
        } else {
            add_action($name, $args, $priority, $num);
        }
        
        return $this;
    }

    public function setTestCookieOnLoginReq(): WPCompat
    {
        if (strpos($_SERVER['REQUEST_URI'], '/wp-login.php') === 0 && strtoupper($_SERVER['REQUEST_METHOD']) == 'POST') {
            $_COOKIE['wordpress_test_cookie'] = 'WP Cookie check';
        }

        return $this;
    }

    private function addActionCompat(string $name, array $args, int $priority = 10, int $num = 1): WPCompat
    {
         // Filters are not yet available, so hi-jack the $wp_filter global to add our actions.
        $GLOBALS['wp_filter'][$name][$priority]['pj-page-cache'] = array(
            'function' => $args, 'accepted_args' => $num );
    }
}
