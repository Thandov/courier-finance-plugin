<?php

class Plugin
{
    public static function init()
    {
        // You can add hooks, shortcodes, or any initialization code here
        // For example, register a shortcode or enqueue styles/scripts.
        add_action('init', 'kit_remove_manage_options_from_editor');
        add_action('admin_print_styles', 'customStyling');
        add_action('admin_enqueue_scripts', 'my_plugin_enqueue_scripts');
        
        // Initialize toast notification system
        if (class_exists('KIT_Toast')) {
            KIT_Toast::init();
        }
    }
}
