<?php
// Delete the services table when the plugin is uninstalled
global $wpdb;
$table_name = $wpdb->prefix . 'kit_services';
$wpdb->query("DROP TABLE IF EXISTS $table_name");
