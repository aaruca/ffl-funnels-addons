<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Bricks Builder Integration
 */
class Alg_Wishlist_Bricks
{

    public static function init()
    {
        // Load Query Integration immediately to catch setup/control_options hooks early
        require_once dirname(__DIR__) . '/integrations/class-wishlist-bricks-query.php';
        Alg_Wishlist_Bricks_Query::init();
    }
}
