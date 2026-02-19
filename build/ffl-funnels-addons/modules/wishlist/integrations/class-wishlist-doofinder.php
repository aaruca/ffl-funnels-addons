<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Doofinder Integration
 */
class Alg_Wishlist_Doofinder
{

    public function enqueue_compatibility_script()
    {
        // This script is small enough to be inline or part of the main assets, 
        // but we keep it separate if it grows.
        // For now, the main 'algenib-wishlist.js' handles the 'df:layer:render' event.
        // If we need specific extra logic, we add it here.
    }

}
