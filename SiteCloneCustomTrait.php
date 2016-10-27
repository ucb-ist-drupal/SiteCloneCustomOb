<?php

namespace SiteClone\Custom;

trait SiteCloneCustomTrait {

  // Use this file to add custom code. See README.md for details.

  public function transformCode_001(\Terminus\Models\Site $site, $env, $assoc_args) {
    $site_name = $site->get('name');
    $clone_path = $this->clone_path . DIRECTORY_SEPARATOR . $site_name;
    $path_to_settings_dns = $clone_path . DIRECTORY_SEPARATOR . "sites" . DIRECTORY_SEPARATOR . "default" . DIRECTORY_SEPARATOR . "settings_dns.php";

    $this->log()
      ->info("{function}: Correct settings_dns.php", [
        'function' => __FUNCTION__,
      ]);

    if (!is_file($path_to_settings_dns) || !is_readable($path_to_settings_dns)) {
      $this->log()
        ->error("{function}: Doesn't exist or not readable: {file}", [
          'function' => __FUNCTION__,
          'file' => $path_to_settings_dns
        ]);
      return FALSE;
    }

    include($path_to_settings_dns);
    if (!function_exists('_openberkeley_dns')) {
      // There's nothing to do. Source site is using a blank settings_dns.php
      return TRUE;
    }
    $settings_dns = _openberkeley_dns();
    if (!is_array($settings_dns) || !count($settings_dns)) {
      // There's nothing to do. Source site is using the default settings_dns.php.
      return TRUE;
    }
    $settings_dns['site_name'] = $site_name;
    $settings_dns['live'] = '';
    $settings_dns['ssl'] = FALSE;
    $data = file_get_contents($path_to_settings_dns);
    $replacement = "*/
    
function _openberkeley_dns() {
  return ";
    $replacement .= var_export($settings_dns, TRUE);
    $replacement .= ';
}';

    $new_code = preg_replace("/\*\/\s+function _openberkeley_dns\(\) {[^}]+}/m", $replacement, $data);

    if (!file_put_contents($path_to_settings_dns, $new_code)) {
      $this->log()
        ->error("{function}: Failed to write to {file}", [
          'function' => __FUNCTION__,
          'file' => $path_to_settings_dns
        ]);
      return FALSE;
    }

    if (!$this->gitAddCommitPush($clone_path, "Revised settings_dns() for cloned site.")) {
      $this->log()
        ->error("{function}: Failed commit new {file}.", [
          'function' => __FUNCTION__,
          'file' => $path_to_settings_dns
        ]);
      return FALSE;
    }

    return TRUE;
  }

  public function transformContent_001(\Terminus\Models\Site $site, $environment, $assoc_args) {
    if (isset($assoc_args['no-disable-smtp'])) {
      return TRUE;
    }

    $this->log()
      ->info("Disabling smtp_host in target environment {env}.", ['env' => $environment]);

    // smtp_host is used by smtp module.  lazily not checking if that module exists.
    // not sure if there is a fallback to localhost smtp if smtp_host is unset or null...
    $result = $this->doFrameworkCommand($site, $environment, "drush vset smtp_host 'NOEMAIL-FROM-CLONED-SITE.example.com'");

    if ($result['exit_code'] != 0) {
      $this->log()
        ->error("Failed to disable smtp_host in target environment {env}.", ['env' => $environment]);
      return FALSE;
    }
  }

  public function transformContent_002(\Terminus\Models\Site $site, $environment, $assoc_args) {
    if (isset($assoc_args['no-remove-emails'])) {
      return TRUE;
    }

    $this->log()
      ->info("Removing user emails in target environment {env}.", ['env' => $environment]);

    // Now there's no way a user could get an auto-generated email.
    $result = $this->doFrameworkCommand($site, $environment, "drush sqlq \"update users set mail = '' where uid <> 0\"");

    if ($result['exit_code'] != 0) {
      $this->log()
        ->error("Failed to remove user emails in target environment {env}.", ['env' => $environment]);
      return FALSE;
    }
  }

  public function transformContent_003(\Terminus\Models\Site $site, $env, $assoc_args) {
    $target_site_name = $site->get("name");

    $new_paths = "http://dev-$target_site_name.pantheon.berkeley.edu/\nhttp://test-$target_site_name.pantheon.berkeley.edu/\nhttp://live-$target_site_name.pantheon.berkeley.edu/\nhttp://dev-$target_site_name.pantheonsite.io/\nhttp://test-$target_site_name.pantheonsite.io/\nhttp://live-$target_site_name.pantheonsite.io/\nhttp://$target_site_name.berkeley.edu\nhttp://$target_site_name.localhost";

    $result = $this->doFrameworkCommand($site, $env, "drush vget openberkeley_wysiwyg_override_pathologic_paths");

    if ($result['exit_code'] != 0) {
      $this->log()
        ->error("Failed to get OB Pathologic paths for {site} {env}", [
          'site' => $target_site_name,
          'env' => $env
        ]);
      return FALSE;
    }

    $old_paths = str_replace("openberkeley_wysiwyg_override_pathologic_paths: ", "", $result['output']);
    $old_paths = str_replace('"', '', $old_paths);
    // some sites have crufty data. attempt to clean it up.
    $old_paths = str_replace('\nhttp', "\nhttp", $old_paths);
    $old_paths = str_replace('\r\nhttp', "\nhttp", $old_paths);
    //$old_paths = str_replace("\r\nhttp", "\nhttp", $old_paths);

    // prepend new paths to old with new line.
    $paths = "$new_paths\n$old_paths";
    $result = [];
    $result = $this->doFrameworkCommand($site, $env, "drush vset openberkeley_wysiwyg_override_pathologic_paths '$paths'");

    if ($result['exit_code'] != 0) {
      $this->log()
        ->error("Failed to set OB Pathologic paths for {site} {env}", [
          'site' => $target_site_name,
          'env' => $env
        ]);
      return FALSE;
    }
  }
}