<?php
class plugin_manager {
	function plugin_manager_adminLinks(&$links)
	{
	    $links['plugins'] = array("link" => "/admin/plugins", "name" => "Plugins", "description" => "Manage all your plugins");
	}
}