<?php
ob_start(); // Start buffering the output

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function waybill_page() {
    
    echo do_shortcode('[kit_waybill_form]');
}
function plugin_Waybill_list_page()
{ 
    echo KIT_Waybills::kit_get_all_waybills_table();
}